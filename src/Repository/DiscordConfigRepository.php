<?php

namespace App\Repository;

use App\Entity\DiscordConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscordConfig>
 */
class DiscordConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscordConfig::class);
    }

    /**
     * Find a configuration entry by its key.
     */
    public function findByKey(string $key): ?DiscordConfig
    {
        return $this->findOneBy(['configKey' => $key]);
    }

    /**
     * Get all enabled configuration entries.
     *
     * @return DiscordConfig[]
     */
    public function getEnabledConfigs(): array
    {
        return $this->createQueryBuilder('dc')
            ->andWhere('dc.isEnabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('dc.configKey', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
