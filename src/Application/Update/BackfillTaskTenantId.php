<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Update;

use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Semitexa\Update\Domain\Enum\UpdatePhase;

/**
 * Backfill os_task.tenant_id added when TaskResource became #[TenantScoped].
 *
 * Pre-tenancy rows are NULL; the scoped store reads under forTenant('default')
 * (WHERE tenant_id = 'default'), so without this patch every existing task
 * becomes invisible after the schema sync. 'default' is the no-context
 * sentinel. Idempotent: only NULL rows.
 */
#[AsDataPatch(
    id: 'backfill-task-tenant-id',
    module: 'semitexa/tasks',
    phase: UpdatePhase::Post,
    requiresColumns: ['os_task' => ['tenant_id']],
    description: 'Assign existing OS tasks to the default tenant.',
)]
final class BackfillTaskTenantId implements DataPatchInterface
{
    public function apply(DataPatchContext $ctx): void
    {
        $ctx->execute("UPDATE `os_task` SET `tenant_id` = 'default' WHERE `tenant_id` IS NULL");
    }
}
