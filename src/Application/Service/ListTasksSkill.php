<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Tasks\Domain\Enum\TaskStatus;
use Semitexa\Tasks\Domain\Model\Task;

/**
 * Answer "what are my tasks?" in the chat — a short readout of the open tasks
 * (title, status, and progress for anything running).
 */
#[AsAiSkill(
    name: 'list-tasks',
    summary: 'Tell the user their open tasks.',
    useWhen: 'The user asks what tasks / to-dos they have, what is left, or their task status (e.g. "what are my tasks", "what\'s on my list", "які в мене задачі") and wants the answer in chat rather than opening the window.',
    avoidWhen: 'The user wants to OPEN the task manager window (use Tasks), ADD (create-task) or COMPLETE (complete-task) a task.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['web'],
)]
final class ListTasksSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $open = array_filter(
            (new TaskStore())->all(),
            static fn (Task $t): bool => $t->getStatus() !== TaskStatus::Done->value && $t->getStatus() !== TaskStatus::Cancelled->value,
        );

        if ($open === []) {
            return 'You have no open tasks — all clear.';
        }

        $lines = [];
        foreach ($open as $t) {
            $status = TaskStatus::tryFrom($t->getStatus()) ?? TaskStatus::Todo;
            $suffix = $status === TaskStatus::InProgress ? ' (' . $t->getProgress() . '%)' : '';
            $lines[] = '• ' . $t->getTitle() . ' — ' . strtolower($status->label()) . $suffix;
        }

        $n = count($lines);

        return 'You have ' . $n . ' open task' . ($n === 1 ? '' : 's') . ":\n" . implode("\n", $lines);
    }
}
