<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Os\Application\Service\ConversationStore;
use Semitexa\Os\Application\Service\ProcessRegistry;

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

    /** The ticker is one producer among many now — every automated task mirrors into the OS process registry. */
    #[InjectAsReadonly]
    protected ProcessRegistry $processes;

    /** Keeps the standing "Today's plan" row alive (heartbeat) and rolls the day over. */
    #[InjectAsReadonly]
    protected TodayPlanReporter $todayPlan;

        /**
     * A tick in flight — Timer::tick fires every 5s regardless of whether the
     * previous callback finished; a stalled DB read would otherwise stack
     * overlapping ticks (same family as the weaver's double-narration).
     */
    private bool $ticking = false;

    /**
     * One tick over the automated work-list: auto-start todos, advance progress
     * from elapsed/eta, and at the ETA complete the task + append a PROACTIVE
     * assistant turn (surfaced by the shell's /os/proactive poll).
     *
     * @return array{started: int, advanced: int, completed: int}
     */
    public function tick(): array
    {
        if ($this->ticking) {
            return ['started' => 0, 'advanced' => 0, 'completed' => 0]; // a tick is already in flight
        }
        $this->ticking = true;

        try {
            return $this->tickPass();
        } finally {
            $this->ticking = false;
        }
    }

    /** @return array{started: int, advanced: int, completed: int} */
    private function tickPass(): array
    {
        $now = new \DateTimeImmutable();
        $started = 0;
        $advanced = 0;
        $completed = 0;

        try {
            foreach ($this->tasks->automatedActive() as $task) {
                if ($task->status === 'todo') {
                    $this->tasks->startOn($task); // row already in hand — no re-find
                    $this->processes->begin(
                        id: 'task:' . $task->id,
                        source: 'tasks',
                        title: $task->title,
                        progress: 0,
                        detail: ($task->eta_seconds ?? 0) > 0 ? \sprintf('timer · ~%ds to auto-complete', $task->eta_seconds) : null,
                    );
                    $started++;
                    continue;
                }

                $eta = $task->eta_seconds ?? 0;
                if ($eta <= 0 || $task->started_at === null) {
                    continue;
                }

                $elapsed = $now->getTimestamp() - $task->started_at->getTimestamp();
                if ($elapsed >= $eta) {
                    // Cross-worker single-winner: every worker's tick timer sees
                    // this finishing task, but only the one that wins the atomic
                    // completion claim announces it — otherwise the user gets N
                    // duplicate "✅ Done" turns (the per-worker $ticking guard
                    // cannot serialize across processes).
                    if ($this->tasks->claimComplete($task->id)) {
                        $this->processes->complete('task:' . $task->id);
                        $this->conversation->append(
                            ConversationStore::ROLE_ASSISTANT,
                            \sprintf('✅ Done — "%s" finished.', $task->title),
                            ['proactive' => true, 'source' => 'task', 'kind' => 'task_completed', 'task_id' => $task->id],
                        );
                        $completed++;
                    }
                } else {
                    $pct = (int) \min(99, \max(1, \round($elapsed / $eta * 100)));
                    $this->tasks->setProgressOn($task, $pct);
                    // Mirror into the registry with the honest semantics spelled
                    // out: this % is elapsed-vs-estimate, not measured work.
                    $detail = \sprintf('timer · ~%ds to auto-complete', \max(0, $eta - $elapsed));
                    if ($this->processes->progress('task:' . $task->id, $pct, $detail) === null) {
                        // task predates its registry row (started before deploy) — register it now
                        $this->processes->begin(
                            id: 'task:' . $task->id,
                            source: 'tasks',
                            title: $task->title,
                            progress: $pct,
                            detail: $detail,
                        );
                    }
                    $advanced++;
                }
            }
        } catch (\Throwable) {
            // best-effort — never break the worker's event loop
        }

        // The plan row's keep-alive + day rollover. Interactive mutations
        // already refresh via TaskStore; this covers the quiet stretches
        // (write-avoidant: heartbeats at half the stall TTL, else no write).
        try {
            $this->todayPlan->refresh($this->tasks->all());
        } catch (\Throwable) {
            // best-effort
        }

        return ['started' => $started, 'advanced' => $advanced, 'completed' => $completed];
    }
}
