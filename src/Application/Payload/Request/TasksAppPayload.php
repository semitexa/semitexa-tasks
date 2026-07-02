<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Entry route for the Tasks UI-skill — renders the standalone task-manager page
 * hosted in the OS window (iframe in web mode, native window in OS mode).
 */
#[AsPublicPayload(
    path: '/os/app/tasks',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class TasksAppPayload
{
}
