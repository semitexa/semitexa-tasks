<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Read the task list as JSON. Polled by the Tasks app so background-tick
 * progress shows live.
 */
#[AsPublicPayload(
    path: '/os/app/tasks/list',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['application/json'],
)]
final class TasksListPayload
{
}
