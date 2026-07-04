<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Tasks\Domain\Enum\TaskStatus;

/**
 * Mark a task done by talking to the OS ("mark the launch post done", "I finished
 * the backup"). The planner passes the task's title (or a distinctive fragment);
 * this matches it against the open tasks and completes the match.
 */
#[AsAiSkill(
    name: 'complete-task',
    summary: 'Mark a task as done.',
    useWhen: 'The user says a task is finished / done / complete (e.g. "mark X done", "I finished Y", "познач Z виконаною"). Put the task title or a distinctive fragment of it in `title`.',
    avoidWhen: 'The user wants to ADD a task (use create-task) or LIST tasks (use list-tasks).',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['title'],
    argumentHints: [
        'title' => 'The task title or a distinctive fragment of it.',
    ],
    channels: ['web'],
)]
final class CompleteTaskSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $q = strtolower(trim((string) ($arguments['title'] ?? '')));
        if ($q === '') {
            return 'Which task should I mark done?';
        }

        $store = new TaskStore();
        $open = array_filter(
            $store->all(),
            static fn($t) => $t->status !== TaskStatus::Done->value && $t->status !== TaskStatus::Cancelled->value,
        );

        $match = null;
        foreach ($open as $t) {
            if (str_contains(strtolower($t->title), $q)) {
                $match = $t;
                break;
            }
        }

        if ($match === null) {
            return 'I couldn\'t find an open task matching "' . trim((string) ($arguments['title'] ?? '')) . '".';
        }

        $store->complete($match->id);

        return 'Marked done: "' . $match->title . '". Nice.';
    }
}
