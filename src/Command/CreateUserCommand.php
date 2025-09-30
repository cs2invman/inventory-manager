<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Create a new user for the CS2 Inventory application',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email of the user')
            ->addArgument('firstName', InputArgument::REQUIRED, 'The first name of the user')
            ->addArgument('lastName', InputArgument::REQUIRED, 'The last name of the user')
            ->addOption('admin', 'a', InputOption::VALUE_NONE, 'Give the user admin rights')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'The plain password of the user')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $firstName = $input->getArgument('firstName');
        $lastName = $input->getArgument('lastName');
        $isAdmin = $input->getOption('admin');

        // Check if user already exists
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            $io->error(sprintf('User with email "%s" already exists!', $email));
            return Command::FAILURE;
        }

        // Get password
        $password = $input->getOption('password');
        if (!$password) {
            $question = new Question('Please enter the password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = $io->askQuestion($question);
        }

        if (!$password) {
            $io->error('Password cannot be empty');
            return Command::FAILURE;
        }

        // Create user
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setIsActive(true);

        // Set roles
        $roles = ['ROLE_USER'];
        if ($isAdmin) {
            $roles[] = 'ROLE_ADMIN';
        }
        $user->setRoles($roles);

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // Save user
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            'User "%s" (%s %s) created successfully!%s',
            $email,
            $firstName,
            $lastName,
            $isAdmin ? ' [ADMIN]' : ''
        ));

        return Command::SUCCESS;
    }
}