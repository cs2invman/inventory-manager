<?php

namespace App\Command;

use App\Repository\ItemRepository;
use App\Service\ProcessQueueService;
use App\Service\QueueProcessor\ProcessorRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:queue:enqueue-all',
    description: 'Enqueue all items for processing with specified type'
)]
class QueueEnqueueAllCommand extends Command
{
    public function __construct(
        private ItemRepository $itemRepository,
        private ProcessQueueService $queueService,
        private ProcessorRegistry $processorRegistry,
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'type',
                InputArgument::REQUIRED,
                'Process type (e.g., PRICE_UPDATED, NEW_ITEM)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $processType = $input->getArgument('type');

        // Validate that processors are registered for this type
        if (!$this->processorRegistry->hasProcessor($processType)) {
            $io->error(sprintf(
                'No processors registered for type: %s',
                $processType
            ));

            $registeredTypes = $this->processorRegistry->getRegisteredTypes();
            if (!empty($registeredTypes)) {
                $io->note('Available types: ' . implode(', ', $registeredTypes));
            } else {
                $io->note('No processors are currently registered.');
            }

            return Command::FAILURE;
        }

        // Get processor names for this type
        $processorNames = $this->processorRegistry->getProcessorNames($processType);
        $io->info(sprintf(
            'Processors for %s: %s',
            $processType,
            implode(', ', $processorNames)
        ));

        // Get total count of items
        $io->text('Counting items in database...');
        $totalItems = $this->itemRepository->count([]);

        if ($totalItems === 0) {
            $io->warning('No items found in database.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d items', $totalItems));

        // Process in batches to avoid memory issues
        $batchSize = 100;
        $totalBatches = (int) ceil($totalItems / $batchSize);
        $totalEnqueued = 0;

        $io->text(sprintf('Enqueueing items for type: %s (batch size: %d)', $processType, $batchSize));
        $io->newLine();

        $progressBar = $io->createProgressBar($totalItems);
        $progressBar->start();

        for ($i = 0; $i < $totalBatches; $i++) {
            $offset = $i * $batchSize;

            // Fetch batch of items
            $items = $this->itemRepository->findBy([], null, $batchSize, $offset);

            // Enqueue batch
            $enqueued = $this->queueService->enqueueBulk($items, $processType);
            $totalEnqueued += $enqueued;

            // Update progress
            $progressBar->advance(count($items));

            // Clear entity manager to free memory
            $this->em->clear();
        }

        $progressBar->finish();
        $io->newLine(2);

        // Show summary
        $io->success(sprintf(
            'Enqueued %d items (skipped %d already queued)',
            $totalEnqueued,
            $totalItems - $totalEnqueued
        ));

        $io->table(
            ['Metric', 'Value'],
            [
                ['Total items in database', number_format($totalItems)],
                ['Items enqueued', number_format($totalEnqueued)],
                ['Items already queued', number_format($totalItems - $totalEnqueued)],
                ['Process type', $processType],
                ['Processors', implode(', ', $processorNames)],
            ]
        );

        return Command::SUCCESS;
    }
}
