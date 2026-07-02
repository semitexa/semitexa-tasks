<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Tasks\Application\Payload\Request\TasksMutatePayload;
use Semitexa\Tasks\Application\Service\TaskStore;
use Semitexa\Tasks\Domain\Enum\TaskStatus;

/**
 * Every task mutation from the app: create | start | complete | status | delete.
 * Replies with {ok, tasks:[...]} so the client re-renders from one response.
 */
#[AsPayloadHandler(payload: TasksMutatePayload::class, resource: ResourceResponse::class)]
final class TasksMutateHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected TaskStore $tasks;

    public function handle(TasksMutatePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $ok = true;
        $error = null;
        $id = $payload->getId();

        switch ($payload->getAction()) {
            case 'create':
                $eta = $payload->getEtaSeconds();
                $this->tasks->create(
                    title: $payload->getTitle(),
                    automated: $payload->getAutomated(),
                    etaSeconds: $eta > 0 ? $eta : null,
                    source: 'user',
                );
                break;
            case 'start':
                $ok = $this->tasks->start($id) !== null;
                break;
            case 'complete':
                $ok = $this->tasks->complete($id) !== null;
                break;
            case 'status':
                $status = TaskStatus::tryFrom($payload->getStatus());
                $ok = $status !== null && $this->tasks->setStatus($id, $status) !== null;
                if ($status === null) {
                    $error = 'Unknown status.';
                }
                break;
            case 'delete':
                $ok = $this->tasks->remove($id);
                break;
            default:
                $ok = false;
                $error = 'Unknown action.';
        }

        $rows = array_map(fn($t) => $this->tasks->toArray($t), $this->tasks->all());
        $body = ['ok' => $ok, 'tasks' => $rows];
        if ($error !== null) {
            $body['error'] = $error;
        }

        return $resource
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
