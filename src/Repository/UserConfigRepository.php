<?php

namespace App\Repository;

use App\Entity\UserConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserConfig>
 */
class UserConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserConfig::class);
    }

    /**
     * Find configuration by user ID.
     *
     * @param int $userId
     * @return UserConfig|null
     */
    public function findByUserId(int $userId): ?UserConfig
    {
        return $this->createQueryBuilder('uc')
            ->andWhere('uc.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
