<?php

namespace App\Repository;

use App\Entity\ProcessQueue;
use App\Entity\ProcessQueueProcessor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessQueueProcessor>
 */
class ProcessQueueProcessorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessQueueProcessor::class);
    }

    /**
     * Check if all processors for a queue item have completed
     */
    public function areAllProcessorsComplete(ProcessQueue $processQueue): bool
    {
        $incompleteCount = (int) $this->createQueryBuilder('pqp')
            ->select('COUNT(pqp.id)')
            ->where('pqp.processQueue = :queue')
            ->andWhere('pqp.status != :status')
            ->setParameter('queue', $processQueue)
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getSingleScalarResult();

        return $incompleteCount === 0;
    }

    /**
     * Get next pending processor for a queue item
     */
    public function findNextPendingProcessor(ProcessQueue $processQueue): ?ProcessQueueProcessor
    {
        return $this->createQueryBuilder('pqp')
            ->where('pqp.processQueue = :queue')
            ->andWhere('pqp.status = :status')
            ->setParameter('queue', $processQueue)
            ->setParameter('status', 'pending')
            ->orderBy('pqp.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get processor tracking for specific queue and processor name
     */
    public function findByQueueAndProcessor(
        ProcessQueue $processQueue,
        string $processorName
    ): ?ProcessQueueProcessor {
        return $this->createQueryBuilder('pqp')
            ->where('pqp.processQueue = :queue')
            ->andWhere('pqp.processorName = :processor')
            ->setParameter('queue', $processQueue)
            ->setParameter('processor', $processorName)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Count pending processors for a queue item
     */
    public function countPendingProcessors(ProcessQueue $processQueue): int
    {
        return (int) $this->createQueryBuilder('pqp')
            ->select('COUNT(pqp.id)')
            ->where('pqp.processQueue = :queue')
            ->andWhere('pqp.status = :status')
            ->setParameter('queue', $processQueue)
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get failed processors for review
     *
     * @return ProcessQueueProcessor[]
     */
    public function findFailedProcessors(int $limit = 100): array
    {
        return $this->createQueryBuilder('pqp')
            ->where('pqp.status = :status')
            ->setParameter('status', 'failed')
            ->orderBy('pqp.failedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
