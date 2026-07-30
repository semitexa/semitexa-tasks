<?php

declare(strict_types=1);

namespace Semitexa\Tasks;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * The package ships no attributes of its own, so there is nothing for a
 * mechanism-level declaration to hang on — and without this the package is
 * invisible to anyone whose project has not installed it, which is precisely
 * the audience worth telling. The convention is one `Capabilities` class per
 * package: a definite place to look, and a definite place for a guard to check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'tasks.list',
    summary: 'An ORM-backed task list with status, progress and deadlines, plus an assistant integration that reports auto-completed tasks.',
    useWhen: 'The application needs a task list users track, and something should notice when work completes rather than waiting to be asked.',
    avoidWhen: 'The work items belong to a domain aggregate with its own lifecycle - model them there instead of bolting a generic task onto them.',
    replaces: [
        'a hand-rolled todo table with status columns and a cron job to notice completion',
        'tracking in-flight work in a comment field nobody queries',
    ],
)]
final class Capabilities
{
}
