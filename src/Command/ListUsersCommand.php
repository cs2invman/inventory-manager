<?php

namespace App\Command;

use App\Command\Traits\CronOptimizedCommandTrait;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:list-users',
    description: 'List all users in the CS2 Inventory application',
)]
class ListUsersCommand extends Command
{
    use CronOptimizedCommandTrait;

    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: table, json, csv (default: table)',
                'table'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format');

        $users = $this->entityManager->getRepository(User::class)->findBy([], ['createdAt' => 'DESC']);

        if (empty($users)) {
            // Only show message in verbose mode
            if ($this->isVerbose($output)) {
                $io->info('No users found.');
            }
            return Command::SUCCESS;
        }

        // Format output based on --format option
        switch ($format) {
            case 'json':
                $this->outputJson($output, $users);
                break;

            case 'csv':
                $this->outputCsv($output, $users);
                break;

            case 'table':
            default:
                // Only show table in verbose mode (quiet by default)
                if ($this->isVerbose($output)) {
                    $this->outputTable($output, $users, $io);
                } else {
                    // In quiet mode, just output count
                    $output->writeln((string)count($users));
                }
                break;
        }

        return Command::SUCCESS;
    }

    private function outputTable(OutputInterface $output, array $users, SymfonyStyle $io): void
    {
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
    }

    private function outputJson(OutputInterface $output, array $users): void
    {
        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'fullName' => $user->getFullName(),
                'roles' => $user->getRoles(),
                'isActive' => $user->getIsActive(),
                'createdAt' => $user->getCreatedAt()?->format('c'),
                'lastLoginAt' => $user->getLastLoginAt()?->format('c'),
            ];
        }
        $output->writeln(json_encode($data, JSON_PRETTY_PRINT));
    }

    private function outputCsv(OutputInterface $output, array $users): void
    {
        // Output CSV header
        $output->writeln('ID,Email,First Name,Last Name,Roles,Active,Created At,Last Login');

        foreach ($users as $user) {
            $row = [
                $user->getId(),
                $this->escapeCsv($user->getEmail()),
                $this->escapeCsv($user->getFirstName()),
                $this->escapeCsv($user->getLastName()),
                $this->escapeCsv(implode(';', $user->getRoles())),
                $user->getIsActive() ? 'Yes' : 'No',
                $user->getCreatedAt()?->format('Y-m-d H:i:s') ?? 'N/A',
                $user->getLastLoginAt()?->format('Y-m-d H:i:s') ?? 'Never',
            ];
            $output->writeln(implode(',', $row));
        }
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}