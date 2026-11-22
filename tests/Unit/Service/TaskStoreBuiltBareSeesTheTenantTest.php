<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\DefaultTenantContextStore;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Tasks\Application\Service\TaskStore;

/**
 * The task skills build this store with `new TaskStore()`: no tenant store is
 * injected. It answered 'default' then without asking anything, so every
 * tenant listed, completed and removed one tenant's tasks. It must ask core's
 * store, which reads the slot the active tenant store writes.
 */
final class TaskStoreBuiltBareSeesTheTenantTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute(
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
    }

    #[Test]
    public function outside_a_coroutine_one_tenant_never_lists_or_completes_anothers_task(): void
    {
        // The slot is process-wide out here: whatever another fixture left in
        // it is put back, not wiped.
        $previous = CoroutineLocal::get(DefaultTenantContextStore::CONTEXT_KEY);
        try {
            self::activate('tenant-acme');
            $acme = $this->bareStore()->create('Acme migration');

            self::activate('tenant-globex');
            self::assertSame([], $this->bareStore()->all(), 'Globex sees none of Acme\'s tasks.');
            self::assertNull($this->bareStore()->find($acme->getId()), 'A foreign task id must not resolve.');
            self::assertFalse($this->bareStore()->claimComplete($acme->getId()), 'Globex cannot complete Acme\'s task.');
            $this->bareStore()->create('Globex audit');

            self::activate('tenant-acme');
            self::assertCount(1, $this->bareStore()->all());
        } finally {
            if ($previous === null) {
                CoroutineLocal::remove(DefaultTenantContextStore::CONTEXT_KEY);
            } else {
                CoroutineLocal::set(DefaultTenantContextStore::CONTEXT_KEY, $previous);
            }
        }

        self::assertSame(
            ['tenant-acme' => 'Acme migration', 'tenant-globex' => 'Globex audit'],
            $this->titlesByTenant(),
        );
    }

    #[Test]
    public function two_tenants_in_two_coroutines_each_create_under_their_own_id(): void
    {
        $hooks = \Swoole\Runtime::getHookFlags();
        try {
            \Swoole\Coroutine\run(function (): void {
                foreach (['tenant-acme' => 'Acme migration', 'tenant-globex' => 'Globex audit'] as $tenantId => $title) {
                    \Swoole\Coroutine::create(function () use ($tenantId, $title): void {
                        // What semitexa/tenancy's store does on set().
                        self::activate($tenantId);
                        // Yield, so the other tenant's coroutine runs in between.
                        \Swoole\Coroutine::sleep(0.005);
                        $this->bareStore()->create($title);
                    });
                }
            });
        } finally {
            \Swoole\Coroutine::set(['hook_flags' => $hooks ?: SWOOLE_HOOK_ALL]);
        }

        self::assertSame(
            ['tenant-acme' => 'Acme migration', 'tenant-globex' => 'Globex audit'],
            $this->titlesByTenant(),
        );
    }

    private function bareStore(): TaskStore
    {
        $store = new TaskStore();
        (new \ReflectionProperty(TaskStore::class, 'orm'))->setValue($store, $this->orm);

        return $store;
    }

    /** @return array<string, string> */
    private function titlesByTenant(): array
    {
        $rows = $this->orm->getAdapter()
            ->execute('SELECT tenant_id, title FROM os_task ORDER BY tenant_id')
            ->fetchAll();

        $byTenant = [];
        foreach ($rows as $row) {
            self::assertIsString($row['tenant_id']);
            self::assertIsString($row['title']);
            $byTenant[$row['tenant_id']] = $row['title'];
        }

        return $byTenant;
    }

    private static function activate(string $tenantId): void
    {
        CoroutineLocal::set(DefaultTenantContextStore::CONTEXT_KEY, new class ($tenantId) implements TenantContextInterface {
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
        });
    }
}
