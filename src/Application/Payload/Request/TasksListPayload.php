<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Read the task list as JSON. Polled by the Tasks app so background-tick
 * progress shows live.
 */
/**
 * Console surface: gated by OsAdminGate, not merely by being signed in.
 *
 * This window mounts under /os/app, so a visitor authenticated by the host
 * site's own login would satisfy #[AsProtectedPayload] exactly as an operator
 * does. OsSurfacePayloadInterface is what asks the narrower question.
 */
#[AsProtectedPayload(
    path: '/os/app/tasks/list',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['application/json'],
)]
final class TasksListPayload implements OsSurfacePayloadInterface
{
}
