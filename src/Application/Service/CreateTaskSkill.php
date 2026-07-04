<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Add a task by talking to the OS ("add a task to write the launch post",
 * "remind me to back up the project"). The planner extracts a short title and,
 * if the user asks for it to run on its own, sets `automated` + an `eta_seconds`
 * estimate — an automated task the background tick completes and the assistant
 * then reports proactively.
 */
#[AsAiSkill(
    name: 'create-task',
    summary: 'Add a task to the task manager.',
    useWhen: 'The user wants to add / create a task, to-do or reminder (e.g. "add a task to X", "remind me to Y", "додай задачу Z"). Put a short imperative title in `title`. If they want it to run automatically, set `automated` to "1" and put an estimated duration in seconds in `eta_seconds`. Optionally a `deadline` as ABSOLUTE "YYYY-MM-DD HH:MM" (24h) resolved from the current date — never a relative word.',
    avoidWhen: 'The user wants to COMPLETE a task (use complete-task), LIST tasks (use list-tasks) or OPEN the task window (use Tasks).',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['title', 'automated', 'eta_seconds', 'deadline'],
    argumentHints: [
        'title' => 'Short imperative task title, max 8 words (e.g. "Buy groceries").',
        'automated' => '"1" if it should run and complete automatically; omit otherwise.',
        'eta_seconds' => 'Estimated duration in seconds (only with automated).',
        'deadline' => 'Optional ABSOLUTE "YYYY-MM-DD HH:MM" (24h) — never a relative word.',
    ],
    channels: ['web'],
)]
final class CreateTaskSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $title = trim((string) ($arguments['title'] ?? ''));
        if ($title === '') {
            return 'What should the task be?';
        }

        $automatedArg = strtolower(trim((string) ($arguments['automated'] ?? '')));
        $automated = in_array($automatedArg, ['1', 'true', 'yes'], true);
        $eta = (int) ($arguments['eta_seconds'] ?? 0);
        if ($automated && $eta <= 0) {
            $eta = 30;
        }

        $deadline = null;
        $deadlineArg = trim((string) ($arguments['deadline'] ?? ''));
        if ($deadlineArg !== '') {
            try {
                $deadline = new \DateTimeImmutable($deadlineArg);
            } catch (\Throwable) {
                $deadline = null;
            }
        }

        (new TaskStore())->create(
            title: $title,
            automated: $automated,
            etaSeconds: $automated ? $eta : null,
            deadline: $deadline,
            source: 'assistant',
        );

        if ($automated) {
            return 'Added "' . $title . '" and started it — I\'ll let you know here when it\'s done.';
        }

        return 'Added "' . $title . '" to your tasks.';
    }
}
