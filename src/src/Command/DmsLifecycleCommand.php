<?php

namespace App\Command;

use App\Service\Dms\DocumentLifecycle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:dms:lifecycle', description: 'Put registered documents in force on their effective date, supersede replaced documents and archive expired ones (safe to run repeatedly).')]
final class DmsLifecycleCommand extends Command
{
    public function __construct(private readonly DocumentLifecycle $lifecycle)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->lifecycle->run(new \DateTimeImmutable());
        (new SymfonyStyle($input, $output))->success(sprintf('%d document(s) now in force, %d superseded, %d archived on expiry.', $result['in_force'], $result['superseded'], $result['archived']));

        return Command::SUCCESS;
    }
}
