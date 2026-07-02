<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The Tasks UI-skill: opens the task manager as a dialog (entry route
 * `/os/app/tasks`) — a live list of tasks with status, progress, deadlines and
 * start times. The planner routes "open my tasks" / "show my task list" here.
 *
 * Creating/completing tasks by intent is handled by the console planner skills
 * (create-task / complete-task / list-tasks), so "add a task…" works without
 * the window even being open.
 */
#[AsAiSkill(
    name: 'Tasks',
    summary: 'Open the task manager — your task list with status, progress and deadlines.',
    useWhen: 'The user wants to see/open their tasks, task list, to-dos or the task manager — e.g. "open my tasks", "show my to-do list", "відкрий мої задачі".',
    avoidWhen: 'The user just wants to ADD or COMPLETE a task by voice without opening anything (the create-task / complete-task skills handle that).',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'list-checks',
    entry: '/os/app/tasks',
)]
final class TasksSkill
{
}
