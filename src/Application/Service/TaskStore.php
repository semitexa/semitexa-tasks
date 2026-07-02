<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
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
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    public function create(
        string $title,
        bool $automated = false,
        ?int $etaSeconds = null,
        ?\DateTimeImmutable $deadline = null,
        string $source = 'user',
    ): TaskResource {
        $task = new TaskResource(
            id: Uuid7::generate(),
            title: trim($title) !== '' ? trim($title) : 'Untitled task',
            status: TaskStatus::Todo->value,
            progress: 0,
            automated: $automated,
            source: $source === 'assistant' ? 'assistant' : 'user',
            created_at: new \DateTimeImmutable(),
            eta_seconds: $etaSeconds,
            deadline: $deadline,
        );
        $this->repository()->insert($task);

        return $task;
    }

    /** All tasks, newest first. @return list<TaskResource> */
    public function all(): array
    {
        /** @var list<TaskResource> $rows */
        $rows = $this->repository()->query()
            ->orderBy(TaskResource::column('id'), Direction::Desc)
            ->fetchAllAs(TaskResource::class, $this->orm()->getMapperRegistry());

        return $rows;
    }

    /** Automated tasks currently running — the tick's work-list. @return list<TaskResource> */
    public function automatedInProgress(): array
    {
        /** @var list<TaskResource> $rows */
        $rows = $this->repository()->findBy([
            'status' => TaskStatus::InProgress->value,
            'automated' => true,
        ]);

        return $rows;
    }

    /** Automated tasks not yet finished — the tick's full work-list. @return list<TaskResource> */
    public function automatedActive(): array
    {
        /** @var list<TaskResource> $rows */
        $rows = $this->repository()->findBy(['automated' => true]);

        return array_values(array_filter(
            $rows,
            static fn(TaskResource $t): bool => $t->status === TaskStatus::Todo->value
                || $t->status === TaskStatus::InProgress->value,
        ));
    }

    public function find(string $id): ?TaskResource
    {
        return $this->repository()->findById($id);
    }

    /** Move a task to in_progress, stamping started_at on first start. */
    public function start(string $id): ?TaskResource
    {
        $t = $this->find($id);
        if ($t === null) {
            return null;
        }

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
        $progress = max(0, min(100, $progress));
        if ($progress >= 100) {
            return $this->complete($id);
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
        $this->repository()->delete($t);

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
        $this->repository()->update($t);

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
