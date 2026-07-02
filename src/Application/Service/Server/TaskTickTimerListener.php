<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Service\Server;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Tasks\Application\Service\TaskTicker;
use Swoole\Timer;

/**
 * The task manager's heartbeat, in-process — a per-worker {@see Timer::tick} that
 * drives {@see TaskTicker} so automated tasks advance and auto-complete without
 * any separate scheduler/queue container. This is why the task auto-complete +
 * proactive-chat loop works identically in web mode AND on the OS machine.
 *
 * Modelled on {@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\ReapStaleSubscriptionsListener}:
 * armed at {@see ServerLifecyclePhase::WorkerStartFinalize} (the worker has its
 * event loop, so `Timer::tick` is safe — unlike a CLI/pre-fork context), with a
 * static per-worker timer id so a phase re-run never arms a second timer.
 *
 * Guarded to worker 0: the tick MUTATES tasks and appends one proactive message
 * per completion, so it must run on exactly one worker (otherwise N workers would
 * each complete the task and post duplicate notices).
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartFinalize->value,
    priority: 0,
    requiresContainer: true,
)]
final class TaskTickTimerListener implements ServerLifecycleListenerInterface
{
    /** Tick cadence — cheap (one indexed lookup when idle), snappy enough for short ETAs. */
    private const TICK_INTERVAL_MS = 5000;

    /** Per-worker timer id so a phase re-run does not arm a second timer. */
    private static int $timerId = 0;

    #[InjectAsReadonly]
    protected TaskTicker $ticker;

    public function handle(ServerLifecycleContext $context): void
    {
        if (!class_exists(Timer::class, false)) {
            return; // not under Swoole (e.g. CLI) — the console command covers that
        }
        if ($context->workerId !== 0) {
            return; // single owner — see class docblock
        }
        if (self::$timerId !== 0) {
            return; // already armed in this worker
        }

        $ticker = $this->ticker;
        self::$timerId = Timer::tick(
            self::TICK_INTERVAL_MS,
            static function () use ($ticker): void {
                $ticker->tick();
            },
        );
    }

    /** Test/worker-stop hygiene: clear the per-worker timer. */
    public static function reset(): void
    {
        if (self::$timerId !== 0 && class_exists(Timer::class, false)) {
            Timer::clear(self::$timerId);
        }
        self::$timerId = 0;
    }
}
