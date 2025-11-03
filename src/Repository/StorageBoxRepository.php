<?php

namespace App\Repository;

use App\Entity\StorageBox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StorageBox>
 */
class StorageBoxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StorageBox::class);
    }

    /**
     * Find all storage boxes for a user
     *
     * @return StorageBox[]
     */
    public function findByUser(\App\Entity\User $user): array
    {
        return $this->createQueryBuilder('sb')
            ->where('sb.user = :user')
            ->setParameter('user', $user)
            ->orderBy('sb.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a storage box by assetId for a specific user
     */
    public function findByAssetId(\App\Entity\User $user, string $assetId): ?StorageBox
    {
        return $this->createQueryBuilder('sb')
            ->where('sb.user = :user')
            ->andWhere('sb.assetId = :assetId')
            ->setParameter('user', $user)
            ->setParameter('assetId', $assetId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all storage boxes with actual item counts from database
     *
     * @return array Array of arrays with StorageBox and actualItemCount
     */
    public function findWithItemCount(\App\Entity\User $user): array
    {
        return $this->createQueryBuilder('sb')
            ->select('sb', 'COUNT(iu.id) as actualItemCount')
            ->leftJoin('App\Entity\ItemUser', 'iu', 'WITH', 'iu.storageBox = sb.id')
            ->where('sb.user = :user')
            ->setParameter('user', $user)
            ->groupBy('sb.id')
            ->orderBy('sb.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all manually created storage boxes for a user (assetId is null)
     * These are used for tracking items lent to friends
     *
     * @return StorageBox[]
     */
    public function findManualBoxes(\App\Entity\User $user): array
    {
        return $this->createQueryBuilder('sb')
            ->where('sb.user = :user')
            ->andWhere('sb.assetId IS NULL')
            ->setParameter('user', $user)
            ->orderBy('sb.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all Steam-imported storage boxes for a user (assetId is not null)
     *
     * @return StorageBox[]
     */
    public function findSteamBoxes(\App\Entity\User $user): array
    {
        return $this->createQueryBuilder('sb')
            ->where('sb.user = :user')
            ->andWhere('sb.assetId IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('sb.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
