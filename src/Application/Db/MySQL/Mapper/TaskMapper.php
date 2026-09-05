<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Tasks\Application\Db\MySQL\Model\TaskResource;
use Semitexa\Tasks\Domain\Model\Task;

/** The bridge between the MySQL row and the task the plan reasons about. */
#[AsMapper(resourceModel: TaskResource::class, domainModel: Task::class)]
final class TaskMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof TaskResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new Task(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            title: $resourceModel->title,
            status: $resourceModel->status,
            progress: $resourceModel->progress,
            automated: $resourceModel->automated,
            source: $resourceModel->source,
            createdAt: $resourceModel->created_at,
            etaSeconds: $resourceModel->eta_seconds,
            deadline: $resourceModel->deadline,
            startedAt: $resourceModel->started_at,
            completedAt: $resourceModel->completed_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof Task || throw new \InvalidArgumentException('Unexpected domain model.');

        return new TaskResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            title: $domainModel->getTitle(),
            status: $domainModel->getStatus(),
            progress: $domainModel->getProgress(),
            automated: $domainModel->isAutomated(),
            source: $domainModel->getSource(),
            created_at: $domainModel->getCreatedAt() ?? new \DateTimeImmutable(),
            eta_seconds: $domainModel->getEtaSeconds(),
            deadline: $domainModel->getDeadline(),
            started_at: $domainModel->getStartedAt(),
            completed_at: $domainModel->getCompletedAt(),
        );
    }
}
