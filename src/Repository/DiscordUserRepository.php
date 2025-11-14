<?php

namespace App\Repository;

use App\Entity\DiscordUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscordUser>
 */
class DiscordUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscordUser::class);
    }

    /**
     * Find a Discord user by their Discord ID.
     */
    public function findByDiscordId(string $discordId): ?DiscordUser
    {
        return $this->findOneBy(['discordId' => $discordId]);
    }

    /**
     * Find all unverified Discord user accounts.
     *
     * @return DiscordUser[]
     */
    public function findUnverified(): array
    {
        return $this->createQueryBuilder('du')
            ->andWhere('du.isVerified = :verified')
            ->setParameter('verified', false)
            ->orderBy('du.linkedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
