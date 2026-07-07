<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Tasks\Application\Db\MySQL\Model\TaskResource;
use Semitexa\Tasks\Domain\Enum\TaskStatus;

/**
 * Persistence + lifecycle for tasks (`os_task`). Mirrors the OS ConversationStore
 * pattern (UUIDv7 ids, OrmManager, best-effort). Because the ORM resource is
 * `final readonly`, every mutation rebuilds the row via {@see self::copy()}.
 *
 * @phpstan-type TaskArray array{id:string,title:string,status:string,status_label:string,progress:int,automated:bool,source:string,created_at:string,deadline:?string,started_at:?string,completed_at:?string}
 */
#[AsService]
final class TaskStore
{
    /**
     * Hard cap on rows returned by {@see all()} — completed tasks accumulate
     * forever and this serves request paths in a Swoole worker, so no list
     * fetch may load the whole table. Generous for any task-list UI.
     */
    private const MAX_LISTED = 200;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * Ambient-tenant seam (coroutine-local), resolved AT CALL TIME. Also works
     * when the store is constructed bare (invocable skills) — falls back to the
     * 'default' sentinel then, same as {@see currentTenantId}.
     */
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    private ?DomainRepository $repository = null;

    /** Test seam — production path uses property injection. */
    public function withTenantContextStore(TenantContextStoreInterface $store): self
    {
        $this->tenantContextStore = $store;

        return $this;
    }

    public function create(
        string $title,
        bool $automated = false,
        ?int $etaSeconds = null,
        ?\DateTimeImmutable $deadline = null,
        string $source = 'user',
    ): TaskResource {
        $task = new TaskResource(
            id: Uuid7::generate(),
            tenant_id: $this->currentTenantId(),
            title: trim($title) !== '' ? trim($title) : 'Untitled task',
            status: TaskStatus::Todo->value,
            progress: 0,
            automated: $automated,
            source: $source === 'assistant' ? 'assistant' : 'user',
            created_at: new \DateTimeImmutable(),
            eta_seconds: $etaSeconds,
            deadline: $deadline,
        );
        $this->scoped()->insert($task);

        return $task;
    }

    /**
     * The most-recent tasks, newest first — bounded. Completed tasks are
     * retained forever, and this serves list/mutate request paths inside a
     * Swoole worker, so an unbounded fetch would load the whole ever-growing
     * table into a worker. The default caps to {@see MAX_LISTED} most-recently-
     * created rows; active tasks carry the newest ids, so they are always in
     * the window while stale completed tasks fall off.
     *
     * @return list<TaskResource>
     */
    public function all(): array
    {
        /** @var list<TaskResource> $rows */
        $rows = $this->scoped()->query()
            ->orderBy(TaskResource::column('id'), Direction::Desc)
            ->limit(self::MAX_LISTED)
            ->fetchAllAs(TaskResource::class, $this->orm()->getMapperRegistry());

        return $rows;
    }

    /** Automated tasks currently running — the tick's work-list. @return list<TaskResource> */
    public function automatedInProgress(): array
    {
        /** @var list<TaskResource> $rows */
        $rows = $this->scoped()->findBy([
            'status' => TaskStatus::InProgress->value,
            'automated' => true,
        ]);

        return $rows;
    }

    /** Automated tasks not yet finished — the tick's full work-list. @return list<TaskResource> */
    public function automatedActive(): array
    {
        /** @var list<TaskResource> $rows */
        $rows = $this->scoped()->findBy(['automated' => true]);

        return array_values(array_filter(
            $rows,
            static fn(TaskResource $t): bool => $t->status === TaskStatus::Todo->value
                || $t->status === TaskStatus::InProgress->value,
        ));
    }

    public function find(string $id): ?TaskResource
    {
        return $this->scoped()->findById($id);
    }

    /** Move a task to in_progress, stamping started_at on first start. */
    public function start(string $id): ?TaskResource
    {
        $t = $this->find($id);
        if ($t === null) {
            return null;
        }

        return $this->startOn($t);
    }

    /**
     * Like {@see start()} but on a row already in hand — the tick loop iterates
     * fully-hydrated {@see automatedActive()} rows, so re-`find()`ing each one
     * by id is a redundant per-item SELECT (N wasted queries per tick, per
     * worker). The resource-taking variants write directly.
     */
    public function startOn(TaskResource $t): TaskResource
    {
        return $this->save($this->copy($t, [
            'status' => TaskStatus::InProgress->value,
            'started_at' => $t->started_at ?? new \DateTimeImmutable(),
        ]));
    }

    /** Set progress 0–100; reaching 100 completes the task. */
    public function setProgress(string $id, int $progress): ?TaskResource
    {
        $t = $this->find($id);
        if ($t === null) {
            return null;
        }

        return $this->setProgressOn($t, $progress);
    }

    /**
     * {@see setProgress()} on a row already in hand — avoids the redundant
     * per-item re-`find()` in the tick loop (see {@see startOn()}).
     */
    public function setProgressOn(TaskResource $t, int $progress): ?TaskResource
    {
        $progress = max(0, min(100, $progress));
        if ($progress >= 100) {
            return $this->complete($t->id);
        }

        return $this->save($this->copy($t, [
            'progress' => $progress,
            'status' => TaskStatus::InProgress->value,
            'started_at' => $t->started_at ?? new \DateTimeImmutable(),
        ]));
    }

    public function complete(string $id): ?TaskResource
    {
        $t = $this->find($id);
        if ($t === null) {
            return null;
        }

        return $this->save($this->copy($t, [
            'status' => TaskStatus::Done->value,
            'progress' => 100,
            'completed_at' => new \DateTimeImmutable(),
        ]));
    }

    /**
     * Atomically claim a task's completion — the cross-worker single-winner
     * transition. Every Swoole worker arms its own tick timer, so all of them
     * see the same finishing task; a find-then-update {@see complete()} lets
     * each one flip the row and (via the ticker) append a duplicate proactive
     * "done" turn. This guarded UPDATE transitions the row out of its
     * non-done state ONCE: the first worker's write matches and returns
     * rowCount 1, every later worker finds status already 'done' and returns
     * 0. Only the caller that gets true should announce the completion.
     *
     * Each placeholder appears exactly once — native prepares
     * (ATTR_EMULATE_PREPARES=false) reject a reused name.
     */
    public function claimComplete(string $id): bool
    {
        $result = $this->orm()->getAdapter()->execute(
            'UPDATE `os_task`
                SET status = :done_set, progress = 100, completed_at = :completed_at
              WHERE tenant_id = :tenant_id AND id = :id AND status <> :done_guard',
            [
                'done_set' => TaskStatus::Done->value,
                'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                'tenant_id' => $this->currentTenantId(),
                'id' => $id,
                'done_guard' => TaskStatus::Done->value,
            ],
        );

        return $result->rowCount === 1;
    }

    public function setStatus(string $id, TaskStatus $status): ?TaskResource
    {
        if ($status === TaskStatus::Done) {
            return $this->complete($id);
        }
        $t = $this->find($id);
        if ($t === null) {
            return null;
        }
        $changes = ['status' => $status->value];
        if ($status === TaskStatus::InProgress) {
            $changes['started_at'] = $t->started_at ?? new \DateTimeImmutable();
        }

        return $this->save($this->copy($t, $changes));
    }

    public function remove(string $id): bool
    {
        $t = $this->find($id);
        if ($t === null) {
            return false;
        }
        $this->scoped()->delete($t);

        return true;
    }

    /** @return TaskArray */
    public function toArray(TaskResource $t): array
    {
        $iso = static fn(?\DateTimeImmutable $d): ?string => $d?->format('c');
        $status = TaskStatus::tryFrom($t->status) ?? TaskStatus::Todo;

        return [
            'id' => $t->id,
            'title' => $t->title,
            'status' => $t->status,
            'status_label' => $status->label(),
            'progress' => $t->progress,
            'automated' => $t->automated,
            'source' => $t->source,
            'created_at' => $t->created_at->format('c'),
            'deadline' => $iso($t->deadline),
            'started_at' => $iso($t->started_at),
            'completed_at' => $iso($t->completed_at),
        ];
    }

    private function save(TaskResource $t): TaskResource
    {
        $this->scoped()->update($t);

        return $t;
    }

    /**
     * Rebuild a readonly TaskResource with overrides. Nullable columns use
     * array_key_exists so an explicit null override is honoured.
     *
     * @param array<string, mixed> $ch
     */
    private function copy(TaskResource $t, array $ch): TaskResource
    {
        return new TaskResource(
            id: $t->id,
            tenant_id: $t->tenant_id,
            title: $ch['title'] ?? $t->title,
            status: $ch['status'] ?? $t->status,
            progress: $ch['progress'] ?? $t->progress,
            automated: $ch['automated'] ?? $t->automated,
            source: $t->source,
            created_at: $t->created_at,
            eta_seconds: array_key_exists('eta_seconds', $ch) ? $ch['eta_seconds'] : $t->eta_seconds,
            deadline: array_key_exists('deadline', $ch) ? $ch['deadline'] : $t->deadline,
            started_at: array_key_exists('started_at', $ch) ? $ch['started_at'] : $t->started_at,
            completed_at: array_key_exists('completed_at', $ch) ? $ch['completed_at'] : $t->completed_at,
        );
    }

    /** Repository bound to the ambient tenant — the ORM gate filters every query. */
    private function scoped(): DomainRepository
    {
        return $this->repository()->forTenant($this->currentTenantId());
    }

    /**
     * Current tenant id, or the 'default' sentinel — never null, so the
     * fail-closed tenant filter and the raw claimComplete WHERE bind a concrete
     * value. Tolerates a bare-constructed store (no injected seam).
     */
    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(TaskResource::class, TaskResource::class);
    }

    private function orm(): OrmManager
    {
        // isset() guard (not ??=) so the store also works when constructed with
        // `new TaskStore()` outside the container — invocable skills do that.
        if (!isset($this->orm)) {
            $this->orm = new OrmManager();
        }

        return $this->orm;
    }
}
