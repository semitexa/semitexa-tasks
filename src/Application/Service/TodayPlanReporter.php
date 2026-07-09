<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Os\Application\Service\ProcessRegistry;
use Semitexa\Tasks\Application\Db\MySQL\Model\TaskResource;
use Semitexa\Tasks\Domain\Enum\TaskStatus;

/**
 * Publishes the aggregate "Today's plan" process into the OS process registry:
 * progress = tasks done / tasks planned for today. Closing a task moves the
 * bar — the first producer whose percentage is a MEASURED fact, not a timer.
 *
 * What counts as planned for today (user-local timezone, {@see OsPreferences}):
 * a non-automated task whose deadline falls on today, or a dateless task
 * created today. Automated tasks are excluded — they complete themselves and
 * already report their own registry rows via {@see TaskTicker}; a self-filling
 * bar inside the day plan would be exactly the theater the registry replaced.
 * Cancelled tasks leave the plan entirely (denominator too).
 *
 * refresh() is write-avoidant: it reports only on real change, plus a
 * heartbeat at half the stall TTL so the standing row never demotes to
 * 'stalled' between changes. Callers treat it as best-effort — a registry
 * hiccup must never break a task mutation.
 *
 * Day rollover: yesterday's still-open row is finalized as FAILED with a
 * "day ended" note — an unfinished plan honestly did not complete; complete()
 * would stamp a lying 100%.
 */
#[AsService]
final class TodayPlanReporter
{
    /** Heartbeat cadence: half the registry stall TTL, so the row stays provably alive. */
    private const HEARTBEAT_SECONDS = ProcessRegistry::STALL_TTL_SECONDS / 2;

    #[InjectAsReadonly]
    protected ProcessRegistry $registry;

    /** @param list<TaskResource> $tasks the CURRENT task list (bounded, newest first) */
    public function refresh(array $tasks): void
    {
        try {
            $tz = (new OsPreferences())->timezone();
            $today = (new \DateTimeImmutable())->setTimezone($tz)->format('Y-m-d');

            $this->finalizeYesterday($tz, $today);

            $plan = array_values(array_filter(
                $tasks,
                fn(TaskResource $t): bool => !$t->automated
                    && $t->status !== TaskStatus::Cancelled->value
                    && $this->plannedDay($t, $tz) === $today,
            ));
            $total = count($plan);
            $done = count(array_filter($plan, static fn(TaskResource $t): bool => $t->status === TaskStatus::Done->value));

            $id = 'tasks:today:' . $today;
            $row = $this->registry()->find($id);
            $rowFinal = $row !== null
                && in_array($row->status, ['done', 'failed'], true);

            if ($total === 0) {
                // Plan emptied (all deleted) — close a live row; report nothing otherwise.
                if ($row !== null && !$rowFinal) {
                    $this->registry()->complete($id, 'no tasks planned today');
                }

                return;
            }

            $pct = (int) round($done / $total * 100);
            $detail = sprintf('%d of %d done', $done, $total);

            if ($done === $total) {
                if ($row === null) {
                    $this->registry()->begin(id: $id, source: 'tasks', title: "Today's plan", progress: $pct, detail: $detail);
                }
                if ($row === null || !$rowFinal || $row->detail !== $detail) {
                    $this->registry()->complete($id, $detail);
                }

                return;
            }

            if ($row === null || $rowFinal) {
                // First task of the day, or a task added after the plan closed —
                // (re)open the row.
                $this->registry()->begin(id: $id, source: 'tasks', title: "Today's plan", progress: $pct, detail: $detail);

                return;
            }

            if ($row->progress !== $pct || $row->detail !== $detail) {
                $this->registry()->progress($id, $pct, $detail);

                return;
            }

            if (time() - $row->updated_at->getTimestamp() >= self::HEARTBEAT_SECONDS) {
                $this->registry()->heartbeat($id);
            }
        } catch (\Throwable) {
            // best-effort — the plan bar must never break a task mutation
        }
    }

    /** The user-local day a task belongs to: its deadline day, else its creation day. */
    private function plannedDay(TaskResource $t, \DateTimeZone $tz): string
    {
        return ($t->deadline ?? $t->created_at)->setTimezone($tz)->format('Y-m-d');
    }

    /**
     * A still-open row from the previous local day did not finish — fail it
     * (keeping its "X of N done" detail) so the panel's history stays honest.
     */
    private function finalizeYesterday(\DateTimeZone $tz, string $today): void
    {
        $yesterday = (new \DateTimeImmutable($today, $tz))->modify('-1 day')->format('Y-m-d');
        $yid = 'tasks:today:' . $yesterday;
        $row = $this->registry()->find($yid);
        if ($row === null || in_array($row->status, ['done', 'failed'], true)) {
            return;
        }
        // NB: address by the producer id, not $row->id — the stored PK carries
        // the registry's internal tenant prefix.
        $note = trim(($row->detail !== null ? $row->detail . ' · ' : '') . 'day ended');
        $this->registry()->fail($yid, $note);
    }

    private function registry(): ProcessRegistry
    {
        // isset() guard so the reporter also works when constructed bare
        // (`new TodayPlanReporter()`) — TaskStore is, inside invocable skills.
        if (!isset($this->registry)) {
            $this->registry = new ProcessRegistry();
        }

        return $this->registry;
    }
}
