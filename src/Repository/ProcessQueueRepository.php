<?php

namespace App\Repository;

use App\Entity\Item;
use App\Entity\ProcessQueue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessQueue>
 */
class ProcessQueueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessQueue::class);
    }

    /**
     * Check if item already has pending or processing queue entry
     */
    public function existsPendingOrProcessing(Item $item, string $processType): bool
    {
        $count = (int) $this->createQueryBuilder('pq')
            ->select('COUNT(pq.id)')
            ->where('pq.item = :item')
            ->andWhere('pq.processType = :processType')
            ->andWhere('pq.status IN (:statuses)')
            ->setParameter('item', $item)
            ->setParameter('processType', $processType)
            ->setParameter('statuses', ['pending', 'processing'])
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Find item IDs that already have pending/processing queue entries
     * Used for bulk deduplication
     *
     * @param array $itemIds
     * @return array Array of item IDs that already exist in queue
     */
    public function findExistingItemIds(array $itemIds, string $processType): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $result = $this->createQueryBuilder('pq')
            ->select('IDENTITY(pq.item) as item_id')
            ->where('pq.item IN (:itemIds)')
            ->andWhere('pq.processType = :processType')
            ->andWhere('pq.status IN (:statuses)')
            ->setParameter('itemIds', $itemIds)
            ->setParameter('processType', $processType)
            ->setParameter('statuses', ['pending', 'processing'])
            ->getQuery()
            ->getArrayResult();

        return array_column($result, 'item_id');
    }

    /**
     * Find pending items in FIFO order
     *
     * @return ProcessQueue[]
     */
    public function findPendingBatch(int $limit = 100, ?string $processType = null): array
    {
        $qb = $this->createQueryBuilder('pq')
            ->where('pq.status = :status')
            ->setParameter('status', 'pending')
            ->orderBy('pq.createdAt', 'ASC')
            ->setMaxResults($limit);

        if ($processType !== null) {
            $qb->andWhere('pq.processType = :processType')
               ->setParameter('processType', $processType);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find failed items for review
     *
     * @return ProcessQueue[]
     */
    public function findFailedItems(int $limit = 100): array
    {
        return $this->createQueryBuilder('pq')
            ->where('pq.status = :status')
            ->setParameter('status', 'failed')
            ->orderBy('pq.failedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count pending items by type
     */
    public function countPendingByType(string $processType): int
    {
        return (int) $this->createQueryBuilder('pq')
            ->select('COUNT(pq.id)')
            ->where('pq.status = :status')
            ->andWhere('pq.processType = :processType')
            ->setParameter('status', 'pending')
            ->setParameter('processType', $processType)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
