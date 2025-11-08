<?php

namespace App\Repository;

use App\Entity\LedgerEntry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LedgerEntry>
 */
class LedgerEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LedgerEntry::class);
    }

    /**
     * Find all ledger entries for a specific user
     *
     * @param User $user
     * @param array<string, string> $orderBy
     * @return LedgerEntry[]
     */
    public function findByUser(User $user, array $orderBy = []): array
    {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->setParameter('user', $user);

        foreach ($orderBy as $field => $direction) {
            $qb->addOrderBy('l.' . $field, $direction);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find ledger entries for a user with optional filters
     *
     * @param User $user
     * @param array<string, mixed> $filters
     * @param array<string, string> $orderBy
     * @return LedgerEntry[]
     */
    public function findByUserWithFilters(User $user, array $filters = [], array $orderBy = []): array
    {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->setParameter('user', $user);

        // Apply filters
        if (!empty($filters['transactionType'])) {
            $qb->andWhere('l.transactionType = :type')
                ->setParameter('type', $filters['transactionType']);
        }

        if (!empty($filters['category'])) {
            $qb->andWhere('l.category = :category')
                ->setParameter('category', $filters['category']);
        }

        if (!empty($filters['currency'])) {
            $qb->andWhere('l.currency = :currency')
                ->setParameter('currency', $filters['currency']);
        }

        if (!empty($filters['dateFrom'])) {
            $qb->andWhere('l.transactionDate >= :dateFrom')
                ->setParameter('dateFrom', $filters['dateFrom']);
        }

        if (!empty($filters['dateTo'])) {
            $qb->andWhere('l.transactionDate <= :dateTo')
                ->setParameter('dateTo', $filters['dateTo']);
        }

        // Apply ordering
        foreach ($orderBy as $field => $direction) {
            $qb->addOrderBy('l.' . $field, $direction);
        }

        return $qb->getQuery()->getResult();
    }
}
