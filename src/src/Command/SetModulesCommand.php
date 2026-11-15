<?php

namespace App\Command;

use App\Service\Module\ModuleCatalog;
use App\Service\Module\ModuleChangeRefused;
use App\Service\Module\ModuleConfigurator;
use App\Service\Module\ModuleGate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Deployment counterpart of Settings → Modules; uses the same rules and history as the administrator API. */
#[AsCommand(name: 'app:modules:set', description: 'Show or change which modules are on: apply a preset, then enable or disable single modules (dependency rules apply).')]
final class SetModulesCommand extends Command
{
    public function __construct(private readonly ModuleConfigurator $configurator, private readonly ModuleGate $gate)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Preset to apply first: '.implode(', ', array_keys(ModuleConfigurator::PRESETS)))
            ->addOption('enable', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Module key to turn on (repeatable)')
            ->addOption('disable', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Module key to turn off (repeatable)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            if (is_string($preset = $input->getOption('preset'))) {
                $this->configurator->applyPreset($preset, 'console');
            }
            foreach ((array) $input->getOption('enable') as $key) {
                $this->configurator->configure((string) $key, true, null, null, 'console');
            }
            foreach ((array) $input->getOption('disable') as $key) {
                $this->configurator->configure((string) $key, false, null, null, 'console');
            }
        } catch (ModuleChangeRefused $refused) {
            $io->error($refused->getMessage());

            return Command::FAILURE;
        }
        $io->table(['Module', 'Key', 'State', 'Restricted to'], array_map(fn (string $key): array => [
            ModuleCatalog::MODULES[$key]['label'], $key, $this->gate->isEnabled($key) ? 'on' : 'off', implode(', ', $this->gate->allowedRoles($key)) ?: 'everyone',
        ], ModuleCatalog::keys()));

        return Command::SUCCESS;
    }
}
