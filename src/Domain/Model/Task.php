<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Domain\Model;

use Semitexa\Tasks\Domain\Enum\TaskStatus;

/**
 * One item on the plan: what is being done, how far along, and by whom.
 *
 * `automated` is the distinction that matters — an automated task advances on
 * the tick without anyone touching it, a manual one waits for a person. That is
 * this package's rule, not a column's.
 */
final readonly class Task
{
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $title,
        private string $status,
        private int $progress,
        private bool $automated,
        private string $source,
        private ?\DateTimeImmutable $createdAt = null,
        private ?int $etaSeconds = null,
        private ?\DateTimeImmutable $deadline = null,
        private ?\DateTimeImmutable $startedAt = null,
        private ?\DateTimeImmutable $completedAt = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }

    public function isAutomated(): bool
    {
        return $this->automated;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEtaSeconds(): ?int
    {
        return $this->etaSeconds;
    }

    public function getDeadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /** The status as this package understands it; anything unrecognised is still to do. */
    public function statusEnum(): TaskStatus
    {
        return TaskStatus::tryFrom($this->status) ?? TaskStatus::Todo;
    }

    /**
     * A copy with some fields replaced.
     *
     * `array_key_exists` for the nullable four: a `??` fallback would refuse to
     * clear a deadline or un-complete a task, so a mistake could never be undone.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            title: is_string($changes['title'] ?? null) ? $changes['title'] : $this->title,
            status: is_string($changes['status'] ?? null) ? $changes['status'] : $this->status,
            progress: is_int($changes['progress'] ?? null) ? $changes['progress'] : $this->progress,
            automated: is_bool($changes['automated'] ?? null) ? $changes['automated'] : $this->automated,
            source: $this->source,
            createdAt: $this->createdAt,
            etaSeconds: array_key_exists('etaSeconds', $changes)
                ? (is_int($changes['etaSeconds']) ? $changes['etaSeconds'] : null)
                : $this->etaSeconds,
            deadline: array_key_exists('deadline', $changes)
                ? ($changes['deadline'] instanceof \DateTimeImmutable ? $changes['deadline'] : null)
                : $this->deadline,
            startedAt: array_key_exists('startedAt', $changes)
                ? ($changes['startedAt'] instanceof \DateTimeImmutable ? $changes['startedAt'] : null)
                : $this->startedAt,
            completedAt: array_key_exists('completedAt', $changes)
                ? ($changes['completedAt'] instanceof \DateTimeImmutable ? $changes['completedAt'] : null)
                : $this->completedAt,
        );
    }
}
