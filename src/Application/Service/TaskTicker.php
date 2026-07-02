<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Os\Application\Service\ConversationStore;

/**
 * The one shared task-tick code path — advance automated tasks toward their ETA
 * and, on completion, make the assistant speak first.
 *
 * Two fronts call this: the in-worker {@see \Semitexa\Tasks\Application\Service\Server\TaskTickTimerListener}
 * (a Swoole Timer armed at worker start — the production path, needs no scheduler
 * container) and {@see \Semitexa\Tasks\Application\Console\Command\TaskTickCommand}
 * (manual trigger for dev). Best-effort: a stray error must never take the worker down.
 */
#[AsService]
final class TaskTicker
{
    #[InjectAsReadonly]
    protected TaskStore $tasks;

    #[InjectAsReadonly]
    protected ConversationStore $conversation;

    /**
     * One tick over the automated work-list: auto-start todos, advance progress
     * from elapsed/eta, and at the ETA complete the task + append a PROACTIVE
     * assistant turn (surfaced by the shell's /os/proactive poll).
     *
     * @return array{started: int, advanced: int, completed: int}
     */
    public function tick(): array
    {
        $now = new \DateTimeImmutable();
        $started = 0;
        $advanced = 0;
        $completed = 0;

        try {
            foreach ($this->tasks->automatedActive() as $task) {
                if ($task->status === 'todo') {
                    $this->tasks->start($task->id); // begins on its own; progresses next tick
                    $started++;
                    continue;
                }

                $eta = $task->eta_seconds ?? 0;
                if ($eta <= 0 || $task->started_at === null) {
                    continue;
                }

                $elapsed = $now->getTimestamp() - $task->started_at->getTimestamp();
                if ($elapsed >= $eta) {
                    $this->tasks->complete($task->id);
                    $this->conversation->append(
                        ConversationStore::ROLE_ASSISTANT,
                        \sprintf('✅ Done — "%s" finished.', $task->title),
                        ['proactive' => true, 'source' => 'task', 'kind' => 'task_completed', 'task_id' => $task->id],
                    );
                    $completed++;
                } else {
                    $this->tasks->setProgress($task->id, (int) \min(99, \max(1, \round($elapsed / $eta * 100))));
                    $advanced++;
                }
            }
        } catch (\Throwable) {
            // best-effort — never break the worker's event loop
        }

        return ['started' => $started, 'advanced' => $advanced, 'completed' => $completed];
    }
}
