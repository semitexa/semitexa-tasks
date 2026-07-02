<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Tasks\Application\Payload\Request\TasksListPayload;
use Semitexa\Tasks\Application\Service\TaskStore;

/** Return the task list as JSON ({tasks: [...]}), newest first. */
#[AsPayloadHandler(payload: TasksListPayload::class, resource: ResourceResponse::class)]
final class TasksListHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected TaskStore $tasks;

    public function handle(TasksListPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $rows = array_map(fn($t) => $this->tasks->toArray($t), $this->tasks->all());

        return $resource
            ->setContent((string) json_encode(['tasks' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
