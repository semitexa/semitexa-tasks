<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Tasks\Application\Db\MySQL\Model\TaskResource;

/**
 * Self-mapping mapper for {@see TaskResource} — the resource IS the domain
 * model, both directions are clone-passthroughs (mirrors ConversationTurnMapper).
 */
#[AsMapper(
    resourceModel: TaskResource::class,
    domainModel: TaskResource::class,
)]
final class TaskMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof TaskResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof TaskResource
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return clone $domainModel;
    }
}
