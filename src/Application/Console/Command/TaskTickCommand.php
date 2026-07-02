<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Tasks\Application\Service\TaskTicker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual/dev trigger for one task tick. The PRODUCTION heartbeat is the in-worker
 * Swoole timer ({@see \Semitexa\Tasks\Application\Service\Server\TaskTickTimerListener});
 * both delegate to the one shared {@see TaskTicker} — no bespoke scheduler loop.
 */
#[AsCommand(
    name: 'tasks:tick',
    description: 'Run one task tick: advance automated tasks, complete them at their ETA, and proactively notify the chat.',
)]
final class TaskTickCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected TaskTicker $ticker;

    protected function configure(): void
    {
        $this->setName('tasks:tick')
            ->setDescription('Run one task tick: advance automated tasks, complete them at their ETA, and proactively notify the chat.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $r = $this->ticker->tick();
        (new SymfonyStyle($input, $output))->text(\sprintf(
            'tasks:tick — started %d, advanced %d, completed %d.',
            $r['started'],
            $r['advanced'],
            $r['completed'],
        ));

        return Command::SUCCESS;
    }
}
