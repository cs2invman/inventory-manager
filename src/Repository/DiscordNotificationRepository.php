<?php

namespace App\Repository;

use App\Entity\DiscordNotification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscordNotification>
 */
class DiscordNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscordNotification::class);
    }

    /**
     * Find recent notifications by type within the specified time window.
     *
     * @param string $type Notification type to filter by
     * @param int $minutes How many minutes back to search
     * @return DiscordNotification[]
     */
    public function findRecentByType(string $type, int $minutes): array
    {
        $since = new \DateTimeImmutable(sprintf('-%d minutes', $minutes));

        return $this->createQueryBuilder('dn')
            ->andWhere('dn.notificationType = :type')
            ->andWhere('dn.createdAt >= :since')
            ->setParameter('type', $type)
            ->setParameter('since', $since)
            ->orderBy('dn.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all failed notifications for retry purposes.
     *
     * @return DiscordNotification[]
     */
    public function findFailedNotifications(): array
    {
        return $this->createQueryBuilder('dn')
            ->andWhere('dn.status = :status')
            ->setParameter('status', DiscordNotification::STATUS_FAILED)
            ->orderBy('dn.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
