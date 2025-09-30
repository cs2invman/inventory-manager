<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:list-users',
    description: 'List all users in the CS2 Inventory application',
)]
class ListUsersCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $users = $this->entityManager->getRepository(User::class)->findBy([], ['createdAt' => 'DESC']);

        if (empty($users)) {
            $io->info('No users found.');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Email', 'Full Name', 'Roles', 'Active', 'Created At', 'Last Login']);

        foreach ($users as $user) {
            $table->addRow([
                $user->getId(),
                $user->getEmail(),
                $user->getFullName(),
                implode(', ', $user->getRoles()),
                $user->getIsActive() ? 'Yes' : 'No',
                $user->getCreatedAt()?->format('Y-m-d H:i:s') ?? 'N/A',
                $user->getLastLoginAt()?->format('Y-m-d H:i:s') ?? 'Never',
            ]);
        }

        $table->render();

        $io->success(sprintf('Found %d user(s).', count($users)));

        return Command::SUCCESS;
    }
}