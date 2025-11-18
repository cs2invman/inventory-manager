<?php

namespace App\Service;

use App\Entity\Item;
use App\Entity\ProcessQueue;
use App\Repository\ProcessQueueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ProcessQueueService
{
    public function __construct(
        private ProcessQueueRepository $repository,
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
     * Mark item as complete and delete
     */
    public function markComplete(ProcessQueue $queueItem): void
    {
        $this->em->remove($queueItem);
        $this->em->flush();
    }

    /**
     * Mark item as failed
     */
    public function markFailed(ProcessQueue $queueItem, string $errorMessage): void
    {
        $queueItem->setStatus('failed');
        $queueItem->setFailedAt(new \DateTime());
        $queueItem->setErrorMessage($errorMessage);
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
}
