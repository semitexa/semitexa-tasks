<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Os\Application\Service\ConversationStore;
use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Os\Application\Service\ProcessRegistry;
use Semitexa\Tasks\Application\Service\TaskStore;
use Semitexa\Tasks\Application\Service\TaskTicker;
use Semitexa\Tasks\Application\Service\TodayPlanReporter;

/**
 * The tick loop, driven end to end over a real store.
 *
 * Every other test in this package exercises TaskStore directly, so when the
 * store started answering in Task objects and the ticker kept reading the row
 * — `$task->status`, `$task->eta_seconds`, `$task->started_at` — the whole
 * suite stayed green while nothing automated started, advanced or completed.
 * The plan bar died the same way, silently, because its own best-effort catch
 * swallowed the TypeError. Nothing here mocks the ticker's collaborators: the
 * point is that the objects the store hands out are the objects the tick can
 * actually read.
 */
final class TaskTickerTest extends TestCase
{
    private OrmManager $orm;
    private TaskStore $tasks;
    private TaskTicker $ticker;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $db = $this->orm->getAdapter();

        $db->execute(
            'CREATE TABLE os_task (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                title TEXT NOT NULL,
                status TEXT NOT NULL,
                progress INTEGER NOT NULL DEFAULT 0,
                automated INTEGER NOT NULL DEFAULT 0,
                source TEXT NOT NULL DEFAULT "user",
                created_at TEXT NOT NULL,
                eta_seconds INTEGER,
                deadline TEXT,
                started_at TEXT,
                completed_at TEXT
            )',
        );
        $db->execute(
            'CREATE TABLE os_process (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                source TEXT NOT NULL,
                origin TEXT NOT NULL,
                title TEXT NOT NULL,
                status TEXT NOT NULL,
                started_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                progress INTEGER,
                detail TEXT,
                completed_at TEXT
            )',
        );
        $db->execute(
            'CREATE TABLE os_conversation_turn (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                role TEXT NOT NULL,
                text TEXT NOT NULL,
                meta_json TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        );

        $this->tasks = new TaskStore();
        $this->overOrm($this->tasks);
        $this->ticker = new TaskTicker();

        (new \ReflectionProperty(TaskTicker::class, 'tasks'))->setValue($this->ticker, $this->tasks);
        (new \ReflectionProperty(TaskTicker::class, 'processes'))
            ->setValue($this->ticker, $this->registry());
        $conversation = new ConversationStore();
        $this->overOrm($conversation);
        (new \ReflectionProperty(TaskTicker::class, 'conversation'))->setValue($this->ticker, $conversation);
        (new \ReflectionProperty(TaskTicker::class, 'todayPlan'))
            ->setValue($this->ticker, new TodayPlanReporter());
    }

    /** Point a bare-constructed store at this test's in-memory database. */
    private function overOrm(object $service): void
    {
        (new \ReflectionProperty($service::class, 'orm'))->setValue($service, $this->orm);
    }

    private function registry(): ProcessRegistry
    {
        $registry = new ProcessRegistry();
        $this->overOrm($registry);

        return $registry;
    }

    #[Test]
    public function an_automated_todo_is_started_by_the_tick(): void
    {
        $this->seedAutomated('t1', 'Reindex the catalog', 'todo', eta: 60, startedAt: null);

        $counts = $this->ticker->tick();

        self::assertSame(1, $counts['started'], 'the tick must start an automated todo');
        $started = $this->tasks->find('t1');
        self::assertNotNull($started);
        self::assertSame('in_progress', $started->getStatus());
        self::assertNotNull($started->getStartedAt());
    }

    /**
     * automatedActive() admits an unrecognised stored status as Todo, so the
     * tick must decide on the same normalised value. Comparing the raw column
     * left such a task listed every tick and started by none of them.
     */
    #[Test]
    public function a_task_with_an_unrecognised_status_is_still_started(): void
    {
        $this->seedAutomated('t9', 'Rotate the logs', 'pending', eta: 60, startedAt: null);

        $counts = $this->ticker->tick();

        self::assertSame(1, $counts['started']);
        self::assertSame('in_progress', $this->tasks->find('t9')?->getStatus() ?? '');
    }

    #[Test]
    public function a_running_task_advances_from_elapsed_over_eta(): void
    {
        $this->seedAutomated(
            't2',
            'Rebuild thumbnails',
            'in_progress',
            eta: 100,
            startedAt: (new \DateTimeImmutable())->modify('-50 seconds'),
        );

        $counts = $this->ticker->tick();

        self::assertSame(1, $counts['advanced'], 'half-elapsed work must advance');
        $progress = $this->tasks->find('t2')?->getProgress() ?? 0;
        self::assertGreaterThan(0, $progress);
        self::assertLessThan(100, $progress);
    }

    #[Test]
    public function a_task_past_its_eta_is_completed_once(): void
    {
        $this->seedAutomated(
            't3',
            'Import the archive',
            'in_progress',
            eta: 10,
            startedAt: (new \DateTimeImmutable())->modify('-60 seconds'),
        );

        $first = $this->ticker->tick();
        $second = $this->ticker->tick();

        self::assertSame(1, $first['completed'], 'the ETA must complete the task');
        self::assertSame(0, $second['completed'], 'a completed task must not be announced twice');
        self::assertSame('done', $this->tasks->find('t3')?->getStatus() ?? '');
    }

    #[Test]
    public function the_plan_bar_opens_a_row_for_todays_tasks(): void
    {
        // refresh() type-hinted its closures TaskResource while the store hands
        // out Task, so every call raised a TypeError that its own best-effort
        // catch swallowed — the bar simply stopped. The row it opens is the
        // only evidence either way, which is why this asserts the row and not
        // a return value.
        $this->seedPlanned('p1', 'Write the release notes', 'todo');
        $this->seedPlanned('p2', 'Cut the tag', 'done');

        $this->reporter()->refresh($this->tasks->all());

        $rows = $this->orm->getAdapter()->query('SELECT id, detail, progress FROM os_process')->rows;

        self::assertCount(1, $rows, "the day's plan row must exist");
        // The stored PK carries the registry's internal tenant prefix, which is
        // exactly why the reporter addresses the row by the producer id.
        self::assertIsString($rows[0]['id']);
        // The day is the reader's, not UTC's — the reporter works in the
        // timezone OsPreferences reports. Comparing against a UTC date passed
        // for most of the day and failed for the hours where the two disagree.
        $today = (new \DateTimeImmutable())->setTimezone((new OsPreferences())->timezone())->format('Y-m-d');
        self::assertStringEndsWith('tasks:today:' . $today, $rows[0]['id']);
        self::assertSame('1 of 2 done', $rows[0]['detail']);
        self::assertIsNumeric($rows[0]['progress']);
        self::assertSame(50, (int) $rows[0]['progress']);
    }

    private function reporter(): TodayPlanReporter
    {
        $reporter = new TodayPlanReporter();
        (new \ReflectionProperty(TodayPlanReporter::class, 'registry'))->setValue($reporter, $this->registry());

        return $reporter;
    }

    /** A non-automated task deadlined today — what the plan bar counts. */
    private function seedPlanned(string $id, string $title, string $status): void
    {
        $this->orm->getAdapter()->execute(
            'INSERT INTO os_task (id, tenant_id, title, status, progress, automated, source, created_at, deadline)
             VALUES (:id, \'default\', :title, :status, 0, 0, :source, :created_at, :deadline)',
            [
                'id' => $id,
                'title' => $title,
                'status' => $status,
                'source' => 'test',
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'deadline' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function seedAutomated(
        string $id,
        string $title,
        string $status,
        int $eta,
        ?\DateTimeImmutable $startedAt,
    ): void {
        $this->orm->getAdapter()->execute(
            'INSERT INTO os_task (id, tenant_id, title, status, progress, automated, source, created_at, eta_seconds, started_at)
             VALUES (:id, \'default\', :title, :status, 0, 1, :source, :created_at, :eta, :started_at)',
            [
                'id' => $id,
                'title' => $title,
                'status' => $status,
                'source' => 'test',
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'eta' => $eta,
                'started_at' => $startedAt?->format('Y-m-d H:i:s'),
            ],
        );
    }
}
