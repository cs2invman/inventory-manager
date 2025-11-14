<?php

namespace App\Command\Discord;

use App\Service\Discord\DiscordWebhookService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:discord:test-webhook',
    description: 'Test Discord webhook by sending a test message',
)]
class TestWebhookCommand extends Command
{
    public function __construct(
        private readonly DiscordWebhookService $discordWebhookService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('config-key', InputArgument::REQUIRED, 'The webhook config key (e.g., webhook_system_events)')
            ->addArgument('message', InputArgument::REQUIRED, 'The test message to send')
            ->setHelp(<<<'HELP'
This command sends a test message to a Discord webhook to verify configuration.

Usage:
  php bin/console app:discord:test-webhook webhook_system_events "Hello from CS2 Inventory!"

The config-key argument should match a webhook URL configuration key in the database.
The message will be sent immediately (synchronously) for testing purposes.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configKey = $input->getArgument('config-key');
        $message = $input->getArgument('message');

        $io->title('Discord Webhook Test');

        $io->section('Configuration');
        $io->writeln("Config Key: <info>{$configKey}</info>");
        $io->writeln("Message: <info>{$message}</info>");
        $io->newLine();

        $io->section('Sending Test Message');

        try {
            $success = $this->discordWebhookService->sendMessage($configKey, $message);

            if ($success) {
                $io->success('Test message sent successfully!');
                $io->writeln('Check your Discord channel to verify the message was received.');
                return Command::SUCCESS;
            } else {
                $io->error('Failed to send test message.');
                $io->writeln('Possible reasons:');
                $io->listing([
                    'Webhook URL not configured in database',
                    'Webhook URL is invalid or disabled',
                    'Discord API returned an error',
                    'Network connectivity issues',
                ]);
                $io->note('Check the application logs and discord_notification table for more details.');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error('Exception occurred while sending test message:');
            $io->writeln($e->getMessage());
            return Command::FAILURE;
        }
    }
}
