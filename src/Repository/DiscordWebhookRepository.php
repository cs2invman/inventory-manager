<?php

namespace App\Repository;

use App\Entity\DiscordWebhook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscordWebhook>
 */
class DiscordWebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscordWebhook::class);
    }

    /**
     * Find webhook by identifier.
     */
    public function findByIdentifier(string $identifier): ?DiscordWebhook
    {
        return $this->findOneBy(['identifier' => $identifier]);
    }

    /**
     * Find all enabled webhooks.
     *
     * @return DiscordWebhook[]
     */
    public function findAllEnabled(): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.isEnabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('w.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if identifier exists.
     */
    public function identifierExists(string $identifier): bool
    {
        $count = $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.identifier = :identifier')
            ->setParameter('identifier', $identifier)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
