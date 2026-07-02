<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Domain\Enum;

/**
 * The lifecycle of a task in the OS task manager.
 *
 * `todo → in_progress → done` is the happy path; `blocked` parks a task and
 * `cancelled` retires it. An *automated* task (see {@see \Semitexa\Tasks\Application\Db\MySQL\Model\TaskResource})
 * moves through `in_progress → done` on its own via the background tick, which
 * is what makes the assistant proactively report the completion.
 */
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /** A short human label for the UI. */
    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::Blocked => 'Blocked',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Terminal states never advance further. */
    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }
}
