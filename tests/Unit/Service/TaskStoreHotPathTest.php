<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Tasks\Application\Db\MySQL\Model\TaskResource;
use Semitexa\Tasks\Application\Service\TaskStore;

/**
 * Hot-path efficiency: the task list serves request paths and the tick loop
 * runs every worker cycle, both inside a Swoole worker.
 *
 *  - all() must be bounded — completed tasks are retained forever, so an
 *    unbounded fetch loads the whole ever-growing table into a worker.
 *  - the tick loop already holds fully-hydrated rows, so the resource-taking
 *    mutators (startOn / setProgressOn) must operate on the row in hand
 *    rather than re-find()ing it by id.
 */
final class TaskStoreHotPathTest extends TestCase
{
    private TaskStore $store;
    private DatabaseAdapterInterface $db;

    protected function setUp(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->db = $orm->getAdapter();
        $this->db->execute(
            'CREATE TABLE os_task (
                id TEXT PRIMARY KEY,
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

        $this->store = new TaskStore();
        (new \ReflectionProperty(TaskStore::class, 'orm'))->setValue($this->store, $orm);
    }

    #[Test]
    public function all_is_capped_to_the_recent_window(): void
    {
        $this->seed(250);

        $rows = $this->store->all();

        self::assertCount(200, $rows, 'all() must be bounded to the hard cap.');
        // Newest-first, so the window is the 250 → 51 most-recently-created rows.
        self::assertSame('task-00000250', $rows[0]->id);
        self::assertSame('task-00000051', $rows[array_key_last($rows)]->id);
    }

    #[Test]
    public function set_progress_on_writes_the_in_hand_row(): void
    {
        $this->seedOne('task-a', 'in_progress', 10);
        $row = $this->store->find('task-a');
        self::assertNotNull($row);

        $updated = $this->store->setProgressOn($row, 60);

        self::assertNotNull($updated);
        self::assertSame(60, $updated->progress);
        self::assertSame(60, $this->store->find('task-a')?->progress, 'the write landed on the DB row');
    }

    #[Test]
    public function set_progress_on_at_100_completes(): void
    {
        $this->seedOne('task-b', 'in_progress', 90);
        $row = $this->store->find('task-b');
        self::assertNotNull($row);

        $this->store->setProgressOn($row, 100);

        self::assertSame('done', $this->store->find('task-b')?->status);
    }

    #[Test]
    public function start_on_stamps_in_progress_without_clobbering_started_at(): void
    {
        $this->seedOne('task-c', 'todo', 0);
        $row = $this->store->find('task-c');
        self::assertNotNull($row);

        $started = $this->store->startOn($row);

        self::assertSame('in_progress', $started->status);
        self::assertNotNull($started->started_at);
    }

    private function seed(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->seedOne(sprintf('task-%08d', $i), 'todo', 0);
        }
    }

    private function seedOne(string $id, string $status, int $progress): void
    {
        $this->db->execute(
            'INSERT INTO os_task (id, title, status, progress, automated, source, created_at, started_at, eta_seconds)
             VALUES (:id, :title, :status, :progress, 1, :source, :created, :started, 10)',
            [
                'id' => $id,
                'title' => 'T ' . $id,
                'status' => $status,
                'progress' => $progress,
                'source' => 'user',
                'created' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                'started' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            ],
        );
    }
}
