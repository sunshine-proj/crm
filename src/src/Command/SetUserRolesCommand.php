<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:user:roles', description: 'Set the application roles of a user (ROLE_FINANCE, ROLE_ADMIN); ROLE_USER is implicit.')]
final class SetUserRolesCommand extends Command
{
    public const ASSIGNABLE_ROLES = ['ROLE_FINANCE', 'ROLE_ADMIN'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User e-mail address')
            ->addArgument('roles', InputArgument::IS_ARRAY, 'Roles to assign; omit to remove all elevated roles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->userRepository->findOneByEmail((string) $input->getArgument('email'));
        if ($user === null) {
            $io->error('User not found.');

            return Command::FAILURE;
        }

        $roles = array_values(array_unique(array_map('strtoupper', $input->getArgument('roles'))));
        $unknown = array_diff($roles, self::ASSIGNABLE_ROLES);
        if ($unknown !== []) {
            $io->error(sprintf('Unsupported role(s): %s. Allowed: %s.', implode(', ', $unknown), implode(', ', self::ASSIGNABLE_ROLES)));

            return Command::FAILURE;
        }

        $before = $user->getRoles();
        $user->setRoles($roles);
        $this->entityManager->flush();
        $this->auditLogger->info('User roles changed from the console.', ['user_id' => $user->getId(), 'before' => $before, 'after' => $user->getRoles()]);
        $io->success(sprintf('Roles for %s: %s. The user must sign in again to receive a new token.', $user->getUserIdentifier(), implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
