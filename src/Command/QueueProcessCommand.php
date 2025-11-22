<?php

namespace App\Command;

use App\Command\Traits\CronOptimizedCommandTrait;
use App\Service\ProcessQueueService;
use App\Service\QueueProcessor\ProcessorRegistry;
use App\Service\Discord\DiscordWebhookService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:queue:process',
    description: 'Process pending items in the processing queue'
)]
class QueueProcessCommand extends Command
{
    use CronOptimizedCommandTrait;

    public function __construct(
        private ProcessQueueService $queueService,
        private ProcessorRegistry $processorRegistry,
        private DiscordWebhookService $discordService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of items to process',
                1000
            )
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED,
                'Only process specific type',
                null
            )
            ->addOption(
                'progress',
                null,
                InputOption::VALUE_NONE,
                'Show progress bar during processing'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $type = $input->getOption('type');

        // Get pending items
        $queueItems = $this->queueService->getNextBatch($limit, $type);

        if (empty($queueItems)) {
            // Cron-optimized: exit silently if no items
            return Command::SUCCESS;
        }

        if ($this->isVerbose($output)) {
            $output->writeln(sprintf(
                '<info>Processing %d queue items...</info>',
                count($queueItems)
            ));
        }

        $processed = 0;
        $failed = 0;

        foreach ($queueItems as $queueItem) {
            // Mark queue item as processing
            $this->queueService->markProcessing($queueItem);

            // Get all processors for this type
            try {
                $processors = $this->processorRegistry->getProcessors(
                    $queueItem->getProcessType()
                );
            } catch (\Exception $e) {
                $this->logger->error('No processors registered for type', [
                    'process_type' => $queueItem->getProcessType(),
                    'error' => $e->getMessage()
                ]);
                $failed++;
                continue;
            }

            // Process with each processor
            foreach ($processors as $processor) {
                $processorName = $processor->getProcessorName();

                try {
                    // Mark this processor as processing
                    $this->queueService->markProcessorProcessing($queueItem, $processorName);

                    // Process the item with this processor
                    $processor->process($queueItem);

                    // Mark this processor as complete
                    // Note: This will auto-delete the queue item if all processors are done
                    $this->queueService->markProcessorComplete($queueItem, $processorName);

                    $processed++;

                    if ($this->isVerbose($output)) {
                        $output->writeln(sprintf(
                            '  ✓ Processed %s / %s for item #%d',
                            $queueItem->getProcessType(),
                            $processorName,
                            $queueItem->getItem()->getId()
                        ));
                    }

                } catch (\Exception $e) {
                    $failed++;

                    // Mark this specific processor as failed
                    $errorMessage = sprintf(
                        '%s: %s',
                        get_class($e),
                        $e->getMessage()
                    );

                    try {
                        $this->queueService->markProcessorFailed($queueItem, $processorName, $errorMessage);
                    } catch (\Exception $markFailedException) {
                        $this->logger->error('Failed to mark processor as failed', [
                            'error' => $markFailedException->getMessage()
                        ]);
                    }

                    // Log the error
                    $this->logger->error('Queue processor failed', [
                        'queue_id' => $queueItem->getId(),
                        'process_type' => $queueItem->getProcessType(),
                        'processor_name' => $processorName,
                        'item_id' => $queueItem->getItem()->getId(),
                        'error' => $errorMessage,
                        'trace' => $e->getTraceAsString()
                    ]);

                    // Send Discord notification
                    $this->sendFailureNotification($queueItem, $processorName, $e);

                    if ($this->isVerbose($output)) {
                        $output->writeln(sprintf(
                            '  ✗ <error>Failed %s / %s for item #%d: %s</error>',
                            $queueItem->getProcessType(),
                            $processorName,
                            $queueItem->getItem()->getId(),
                            $e->getMessage()
                        ));
                    }
                }
            }

            // Clear entity manager every 10 items to prevent memory issues
            if (($processed + $failed) % 10 === 0) {
                $this->em->clear();
            }
        }

        // Only show summary in verbose mode
        if ($this->isVerbose($output)) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<info>Completed: %d processed, %d failed</info>',
                $processed,
                $failed
            ));
        }

        return Command::SUCCESS;
    }

    private function sendFailureNotification(
        \App\Entity\ProcessQueue $queueItem,
        string $processorName,
        \Exception $e
    ): void {
        try {
            $message = sprintf(
                "**Queue Processor Failed** :warning:\n\n" .
                "**Type:** %s\n" .
                "**Processor:** %s\n" .
                "**Item:** %s (ID: %d)\n" .
                "**Error:** %s\n" .
                "**Queue Attempts:** %d",
                $queueItem->getProcessType(),
                $processorName,
                $queueItem->getItem()->getName(),
                $queueItem->getItem()->getId(),
                $e->getMessage(),
                $queueItem->getAttempts()
            );

            $this->discordService->sendMessage('system_events', $message);
        } catch (\Exception $discordException) {
            // Log but don't throw - don't want Discord failures to crash processing
            $this->logger->error('Failed to send Discord notification', [
                'error' => $discordException->getMessage()
            ]);
        }
    }
}
