<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Tasks\Application\Service\TaskStore;

/**
 * os_task is #[TenantScoped]: one tenant's task list, completion claim, and
 * automated-tick set are isolated from another's. This is what makes the
 * per-tenant TaskTicker fan-out correct — without it the ticker would
 * advance/complete every tenant's tasks and post notices into the wrong
 * transcript.
 */
final class TaskStoreTenantIsolationTest extends TestCase
{
    private TaskStore $store;
    private TaskTenantContextStore $ctx;

    protected function setUp(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(
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

        $this->ctx = new TaskTenantContextStore();
        $this->store = (new TaskStore())->withTenantContextStore($this->ctx);
        (new \ReflectionProperty(TaskStore::class, 'orm'))->setValue($this->store, $orm);
    }

    #[Test]
    public function one_tenant_never_sees_or_completes_another_tenants_tasks(): void
    {
        $this->ctx->switchTo('acme');
        $acme = $this->store->create('Acme migration', automated: true);

        // Globex's list and automated set are empty; Acme's task id is unreachable.
        $this->ctx->switchTo('globex');
        self::assertSame([], $this->store->all(), 'Globex sees none of Acme\'s tasks.');
        self::assertSame([], $this->store->automatedActive(), 'Globex has no automated tasks.');
        self::assertNull($this->store->find($acme->id), 'A foreign task id must not resolve.');
        self::assertFalse($this->store->claimComplete($acme->id), 'Globex cannot complete Acme\'s task.');
        self::assertFalse($this->store->remove($acme->id), 'Globex cannot remove Acme\'s task.');

        // Acme's task is intact and still todo.
        $this->ctx->switchTo('acme');
        self::assertCount(1, $this->store->all());
        self::assertNotNull($this->store->find($acme->id));
        self::assertTrue($this->store->claimComplete($acme->id), 'The owner completes its own task.');
    }

    #[Test]
    public function the_same_tick_under_two_tenants_only_touches_each_ones_tasks(): void
    {
        $this->ctx->switchTo('acme');
        $this->store->create('Acme A', automated: true);
        $this->ctx->switchTo('globex');
        $this->store->create('Globex G', automated: true);

        // Each tenant's automated set is exactly its own — the shape the
        // per-tenant ticker fan-out relies on.
        $this->ctx->switchTo('acme');
        $acmeAutomated = array_map(static fn ($t) => $t->title, $this->store->automatedActive());
        $this->ctx->switchTo('globex');
        $globexAutomated = array_map(static fn ($t) => $t->title, $this->store->automatedActive());

        self::assertSame(['Acme A'], $acmeAutomated);
        self::assertSame(['Globex G'], $globexAutomated);
    }
}

final class TaskTenantContextStore implements TenantContextStoreInterface
{
    private ?TenantContextInterface $context = null;

    public function switchTo(string $tenantId): void
    {
        $this->context = new class ($tenantId) implements TenantContextInterface {
            public function __construct(private readonly string $id) {}

            public function getTenantId(): string
            {
                return $this->id;
            }

            public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
            {
                return null;
            }

            public function hasLayer(TenantLayerInterface $layer): bool
            {
                return false;
            }
        };
    }

    public function get(): TenantContextInterface
    {
        return $this->context ?? throw new \LogicException('no context');
    }

    public function tryGet(): ?TenantContextInterface
    {
        return $this->context;
    }

    public function set(TenantContextInterface $context): void
    {
        $this->context = $context;
    }

    public function clear(): void
    {
        $this->context = null;
    }
}
