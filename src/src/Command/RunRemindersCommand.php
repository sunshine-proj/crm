<?php

namespace App\Command;

use App\Service\ReminderGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:reminders:run', description: 'Create due-soon, overdue and escalation notifications for tasks and projects (safe to run repeatedly).')]
final class RunRemindersCommand extends Command
{
    public function __construct(private readonly ReminderGenerator $generator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->generator->run(new \DateTimeImmutable());
        (new SymfonyStyle($input, $output))->success(sprintf('Created %d due-soon, %d overdue and %d escalation notification(s).', $result['due_soon'], $result['overdue'], $result['escalations']));

        return Command::SUCCESS;
    }
}
