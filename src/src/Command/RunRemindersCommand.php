<?php

namespace App\Command;

use App\Service\Dms\AcknowledgementAudience;
use App\Service\ReminderGenerator;
use App\Service\Module\ModuleGate;
use App\Service\Time\MissingTimeReminder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:reminders:run', description: 'Add people who joined targeted units to open acknowledgement requests, then create due-soon, overdue and escalation notifications for tasks, projects, approvals and acknowledgements, and missing-time reminders for the previous working day (safe to run repeatedly).')]
final class RunRemindersCommand extends Command
{
    public function __construct(private readonly ReminderGenerator $generator, private readonly AcknowledgementAudience $audience, private readonly MissingTimeReminder $missingTime, private readonly ModuleGate $modules)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $io = new SymfonyStyle($input, $output);
        // Employments can start on a future date, so joiners are picked up here as well as when they are recorded.
        $joined = $this->modules->isEnabled('document_management') ? $this->audience->addJoiners($now) : 0;
        if ($joined > 0) {
            $io->note(sprintf('Added %d person(s) who joined a targeted unit or position to open acknowledgement requests.', $joined));
        }
        $result = $this->generator->run($now);
        $missing = $this->missingTime->run($now);
        $io->success(sprintf('Created %d due-soon, %d overdue, %d escalation and %d missing-time notification(s).', $result['due_soon'], $result['overdue'], $result['escalations'], $missing));

        return Command::SUCCESS;
    }
}
