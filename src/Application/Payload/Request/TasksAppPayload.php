<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Entry route for the Tasks UI-skill — renders the standalone task-manager page
 * hosted in the OS window (iframe in web mode, native window in OS mode).
 */
/**
 * Console surface: gated by OsAdminGate, not merely by being signed in.
 *
 * This window mounts under /os/app, so a visitor authenticated by the host
 * site's own login would satisfy #[AsProtectedPayload] exactly as an operator
 * does. OsSurfacePayloadInterface is what asks the narrower question.
 */
#[AsProtectedPayload(
    path: '/os/app/tasks',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class TasksAppPayload implements OsSurfacePayloadInterface
{
}
