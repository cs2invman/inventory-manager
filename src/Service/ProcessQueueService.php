<?php

namespace App\Service;

use App\Entity\Item;
use App\Entity\ProcessQueue;
use App\Entity\ProcessQueueProcessor;
use App\Repository\ProcessQueueRepository;
use App\Repository\ProcessQueueProcessorRepository;
use App\Service\QueueProcessor\ProcessorRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ProcessQueueService
{
    public function __construct(
        private ProcessQueueRepository $repository,
        private ProcessQueueProcessorRepository $processorRepository,
        private ProcessorRegistry $processorRegistry,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Add item to processing queue (skips if already queued)
     *
     * @return ProcessQueue|null Returns queue item if added, null if already exists
     */
    public function enqueue(Item $item, string $processType): ?ProcessQueue
    {
        // Check if already queued (pending or processing)
        if ($this->repository->existsPendingOrProcessing($item, $processType)) {
            return null; // Already queued, skip
        }

        $queueItem = new ProcessQueue();
        $queueItem->setItem($item);
        $queueItem->setProcessType($processType);
        $queueItem->setStatus('pending');
        $queueItem->setCreatedAt(new \DateTime());

        // Initialize processor tracking entries
        $this->initializeProcessorTracking($queueItem);

        $this->em->persist($queueItem);
        $this->em->flush();

        return $queueItem;
    }

    /**
     * Bulk enqueue items (for sync operations)
     * Automatically deduplicates against existing queue entries
     *
     * @param Item[] $items
     * @return int Number of items actually enqueued (after deduplication)
     */
    public function enqueueBulk(array $items, string $processType): int
    {
        if (empty($items)) {
            return 0;
        }

        // Get item IDs that already have pending/processing queue entries
        $itemIds = array_map(fn($item) => $item->getId(), $items);
        $existingItemIds = $this->repository->findExistingItemIds($itemIds, $processType);

        $count = 0;
        $skipped = 0;

        foreach ($items as $item) {
            // Skip if already queued
            if (in_array($item->getId(), $existingItemIds, true)) {
                $skipped++;
                continue;
            }

            $queueItem = new ProcessQueue();
            $queueItem->setItem($item);
            $queueItem->setProcessType($processType);
            $queueItem->setStatus('pending');
            $queueItem->setCreatedAt(new \DateTime());

            // Initialize processor tracking entries
            $this->initializeProcessorTracking($queueItem);

            $this->em->persist($queueItem);
            $count++;

            // Batch flush every 50 items
            if ($count % 50 === 0) {
                $this->em->flush();
            }
        }

        // Flush remaining
        if ($count % 50 !== 0) {
            $this->em->flush();
        }

        if ($skipped > 0) {
            $this->logger->debug('Skipped duplicate queue entries', [
                'process_type' => $processType,
                'skipped_count' => $skipped,
                'enqueued_count' => $count
            ]);
        }

        return $count;
    }

    /**
     * Get next batch of pending items (FIFO)
     *
     * @return ProcessQueue[]
     */
    public function getNextBatch(int $limit = 100, ?string $processType = null): array
    {
        return $this->repository->findPendingBatch($limit, $processType);
    }

    /**
     * Mark item as processing
     */
    public function markProcessing(ProcessQueue $queueItem): void
    {
        $queueItem->setStatus('processing');
        $queueItem->setAttempts($queueItem->getAttempts() + 1);
        $this->em->flush();
    }

    /**
     * Mark specific processor as processing
     */
    public function markProcessorProcessing(ProcessQueue $queueItem, string $processorName): void
    {
        $tracking = $this->processorRepository->findByQueueAndProcessor($queueItem, $processorName);

        if (!$tracking) {
            throw new \RuntimeException(
                sprintf('Processor tracking not found for: %s', $processorName)
            );
        }

        $tracking->setStatus('processing');
        $tracking->setAttempts($tracking->getAttempts() + 1);
        $this->em->flush();
    }

    /**
     * Mark specific processor as complete
     * Only deletes the queue item if ALL processors are complete
     */
    public function markProcessorComplete(ProcessQueue $queueItem, string $processorName): void
    {
        $tracking = $this->processorRepository->findByQueueAndProcessor($queueItem, $processorName);

        if (!$tracking) {
            throw new \RuntimeException(
                sprintf('Processor tracking not found for: %s', $processorName)
            );
        }

        $tracking->setStatus('completed');
        $tracking->setCompletedAt(new \DateTime());
        $this->em->flush();

        // Check if all processors are complete
        if ($this->processorRepository->areAllProcessorsComplete($queueItem)) {
            // All processors complete - delete the queue item
            $this->em->remove($queueItem);
            $this->em->flush();

            $this->logger->info('Queue item completed by all processors', [
                'queue_id' => $queueItem->getId(),
                'process_type' => $queueItem->getProcessType(),
                'item_id' => $queueItem->getItem()->getId()
            ]);
        }
    }

    /**
     * Mark specific processor as failed
     */
    public function markProcessorFailed(ProcessQueue $queueItem, string $processorName, string $errorMessage): void
    {
        $tracking = $this->processorRepository->findByQueueAndProcessor($queueItem, $processorName);

        if (!$tracking) {
            throw new \RuntimeException(
                sprintf('Processor tracking not found for: %s', $processorName)
            );
        }

        $tracking->setStatus('failed');
        $tracking->setFailedAt(new \DateTime());
        $tracking->setErrorMessage($errorMessage);
        $this->em->flush();
    }

    /**
     * Get failed items for review
     *
     * @return ProcessQueue[]
     */
    public function getFailedItems(int $limit = 100): array
    {
        return $this->repository->findFailedItems($limit);
    }

    /**
     * Initialize processor tracking entries for a queue item
     * Creates one tracking entry for each registered processor of the queue's type
     */
    private function initializeProcessorTracking(ProcessQueue $queueItem): void
    {
        $processType = $queueItem->getProcessType();

        // Get all processors for this type
        try {
            $processors = $this->processorRegistry->getProcessors($processType);
        } catch (\RuntimeException $e) {
            // No processors registered yet - this is ok during testing/development
            $this->logger->warning('No processors registered for type', [
                'process_type' => $processType
            ]);
            return;
        }

        // Create tracking entry for each processor
        foreach ($processors as $processor) {
            $tracking = new ProcessQueueProcessor();
            $tracking->setProcessQueue($queueItem);
            $tracking->setProcessorName($processor->getProcessorName());
            $tracking->setStatus('pending');
            $tracking->setCreatedAt(new \DateTime());

            $queueItem->addProcessorTracking($tracking);
        }
    }

    /**
     * Get next pending processor for a queue item
     */
    public function getNextPendingProcessor(ProcessQueue $queueItem): ?ProcessQueueProcessor
    {
        return $this->processorRepository->findNextPendingProcessor($queueItem);
    }
}
