<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Tasks\Application\Service\TaskStore;

/**
 * The cross-worker completion claim. Every Swoole worker arms its own tick
 * timer, so all of them see the same finishing task; the per-worker $ticking
 * bool only serialises WITHIN a worker. Completion must be a single-winner
 * atomic transition so exactly one worker announces the "done" proactive turn
 * — otherwise the user gets N duplicate messages. Exercised through the real
 * guarded UPDATE against an in-memory SQLite database.
 */
final class TaskCompletionClaimTest extends TestCase
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
                tenant_id TEXT,
                status TEXT NOT NULL,
                progress INTEGER NOT NULL DEFAULT 0,
                completed_at TEXT
            )',
        );

        $this->store = new TaskStore();
        (new \ReflectionProperty(TaskStore::class, 'orm'))->setValue($this->store, $orm);
    }

    #[Test]
    public function only_the_first_claim_wins_the_completion(): void
    {
        $this->seed('task-1', 'in_progress');

        self::assertTrue($this->store->claimComplete('task-1'), 'First worker wins the transition.');
        self::assertFalse($this->store->claimComplete('task-1'), 'A second worker sees it already done.');
        self::assertFalse($this->store->claimComplete('task-1'), 'And every worker after that.');

        self::assertSame('done', $this->statusOf('task-1'));
        self::assertSame(100, (int) $this->rowOf('task-1')['progress']);
    }

    #[Test]
    public function a_claim_on_an_already_done_task_loses(): void
    {
        $this->seed('task-2', 'done');

        self::assertFalse($this->store->claimComplete('task-2'));
    }

    #[Test]
    public function a_claim_on_a_missing_task_loses_without_error(): void
    {
        self::assertFalse($this->store->claimComplete('ghost'));
    }

    private function seed(string $id, string $status): void
    {
        $this->db->execute(
            'INSERT INTO os_task (id, tenant_id, status, progress) VALUES (:id, \'default\', :status, 0)',
            ['id' => $id, 'status' => $status],
        );
    }

    private function statusOf(string $id): string
    {
        return (string) $this->rowOf($id)['status'];
    }

    /** @return array<string, mixed> */
    private function rowOf(string $id): array
    {
        $rows = $this->db->execute('SELECT * FROM os_task WHERE id = :id', ['id' => $id])->rows;
        self::assertNotEmpty($rows, "Task {$id} must exist.");

        return $rows[0];
    }
}
