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
use Semitexa\Tasks\Domain\Model\Task;
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

    /** Keeps the aggregate "Today's plan" registry row in step with every user-visible mutation. */
    #[InjectAsReadonly]
    protected TodayPlanReporter $todayPlan;

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
    ): Task {
        $task = new Task(
            id: Uuid7::generate(),
            tenantId: $this->currentTenantId(),
            title: trim($title) !== '' ? trim($title) : 'Untitled task',
            status: TaskStatus::Todo->value,
            progress: 0,
            automated: $automated,
            source: $source === 'assistant' ? 'assistant' : 'user',
            createdAt: new \DateTimeImmutable(),
            etaSeconds: $etaSeconds,
            deadline: $deadline,
        );
        $this->scoped()->insert($task);
        $this->reportTodayPlan();

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
     * @return list<Task>
     */
    public function all(): array
    {
        /** @var list<Task> $rows */
        $rows = $this->scoped()->query()
            ->orderBy(TaskResource::column('id'), Direction::Desc)
            ->limit(self::MAX_LISTED)
            ->fetchAllAs(Task::class, $this->orm()->getMapperRegistry());

        return $rows;
    }

    /**
     * Automated tasks currently running — the tick's work-list.
     *
     * @return list<Task>
     */
    public function automatedInProgress(): array
    {
        /** @var list<Task> $rows */
        $rows = $this->scoped()->findBy([
            'status' => TaskStatus::InProgress->value,
            'automated' => true,
        ]);

        return $rows;
    }

    /**
     * Automated tasks not yet finished — the tick's full work-list.
     *
     * @return list<Task>
     */
    public function automatedActive(): array
    {
        /** @var list<Task> $rows */
        $rows = $this->scoped()->findBy(['automated' => true]);

        return array_values(array_filter(
            $rows,
            static fn(Task $t): bool => $t->statusEnum() === TaskStatus::Todo
                || $t->statusEnum() === TaskStatus::InProgress,
        ));
    }

    public function find(string $id): ?Task
    {
        $task = $this->scoped()->findById($id);

        return $task instanceof Task ? $task : null;
    }

    /** Move a task to in_progress, stamping started_at on first start. */
    public function start(string $id): ?Task
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
    public function startOn(Task $t): Task
    {
        return $this->save($this->copy($t, [
            'status' => TaskStatus::InProgress->value,
            'startedAt' => $t->getStartedAt() ?? new \DateTimeImmutable(),
        ]));
    }

    /** Set progress 0–100; reaching 100 completes the task. */
    public function setProgress(string $id, int $progress): ?Task
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
    public function setProgressOn(Task $t, int $progress): ?Task
    {
        $progress = max(0, min(100, $progress));
        if ($progress >= 100) {
            return $this->complete($t->getId());
        }

        return $this->save($this->copy($t, [
            'progress' => $progress,
            'status' => TaskStatus::InProgress->value,
            'startedAt' => $t->getStartedAt() ?? new \DateTimeImmutable(),
        ]));
    }

    public function complete(string $id): ?Task
    {
        $t = $this->find($id);
        if ($t === null) {
            return null;
        }

        $done = $this->save($this->copy($t, [
            'status' => TaskStatus::Done->value,
            'progress' => 100,
            'completed_at' => new \DateTimeImmutable(),
        ]));
        $this->reportTodayPlan();

        return $done;
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

    public function setStatus(string $id, TaskStatus $status): ?Task
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
            $changes['startedAt'] = $t->getStartedAt() ?? new \DateTimeImmutable();
        }
        $updated = $this->save($this->copy($t, $changes));
        $this->reportTodayPlan();

        return $updated;
    }

    public function remove(string $id): bool
    {
        $t = $this->find($id);
        if ($t === null) {
            return false;
        }
        $this->scoped()->delete($t);
        $this->reportTodayPlan();

        return true;
    }

    /** @return TaskArray */
    public function toArray(Task $t): array
    {
        $iso = static fn(?\DateTimeImmutable $d): ?string => $d?->format('c');

        return [
            'id' => $t->getId(),
            'title' => $t->getTitle(),
            'status' => $t->getStatus(),
            'status_label' => $t->statusEnum()->label(),
            'progress' => $t->getProgress(),
            'automated' => $t->isAutomated(),
            'source' => $t->getSource(),
            'created_at' => ($t->getCreatedAt() ?? new \DateTimeImmutable())->format('c'),
            'deadline' => $iso($t->getDeadline()),
            'started_at' => $iso($t->getStartedAt()),
            'completed_at' => $iso($t->getCompletedAt()),
        ];
    }

    private function save(Task $t): Task
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
    /**
     * @param array<string, mixed> $ch keyed as the row was — translated once here
     */
    private function copy(Task $t, array $ch): Task
    {
        $renamed = [];
        foreach ($ch as $key => $value) {
            $renamed[match ($key) {
                'eta_seconds' => 'etaSeconds',
                'started_at' => 'startedAt',
                'completed_at' => 'completedAt',
                default => $key,
            }] = $value;
        }

        return $t->with($renamed);
    }

    /**
     * Refresh the "Today's plan" registry row after a user-visible mutation
     * (create / complete / status / delete). Best-effort by design AND placed
     * HERE, not in callers — skills construct this store bare, and every one
     * of them must move the plan bar the instant a task closes.
     */
    private function reportTodayPlan(): void
    {
        try {
            if (!isset($this->todayPlan)) {
                $this->todayPlan = new TodayPlanReporter();
            }
            $this->todayPlan->refresh($this->all());
        } catch (\Throwable) {
            // the plan bar must never break a task mutation
        }
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
        return $this->repository ??= $this->orm()->repository(TaskResource::class, Task::class);
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
