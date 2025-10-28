<?php

namespace App\Repository;

use App\Entity\Item;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Item>
 */
class ItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Item::class);
    }

    /**
     * Find an item by its Steam market hash name
     */
    public function findByHashName(string $hashName): ?Item
    {
        return $this->createQueryBuilder('i')
            ->where('i.hashName = :hashName')
            ->setParameter('hashName', $hashName)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find items by category
     *
     * @return Item[]
     */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.category = :category')
            ->setParameter('category', $category)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items by type
     *
     * @return Item[]
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.type = :type')
            ->setParameter('type', $type)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items by rarity
     *
     * @return Item[]
     */
    public function findByRarity(string $rarity): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.rarity = :rarity')
            ->setParameter('rarity', $rarity)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items that support StatTrak
     *
     * @return Item[]
     */
    public function findStattrakItems(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.stattrakAvailable = true')
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items that support Souvenir
     *
     * @return Item[]
     */
    public function findSouvenirItems(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.souvenirAvailable = true')
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items by collection
     *
     * @return Item[]
     */
    public function findByCollection(string $collection): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.collection = :collection')
            ->setParameter('collection', $collection)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search items by name (case-insensitive)
     *
     * @return Item[]
     */
    public function searchByName(string $searchTerm): array
    {
        return $this->createQueryBuilder('i')
            ->where('LOWER(i.name) LIKE LOWER(:searchTerm)')
            ->setParameter('searchTerm', '%' . $searchTerm . '%')
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all unique categories
     *
     * @return string[]
     */
    public function findAllCategories(): array
    {
        $result = $this->createQueryBuilder('i')
            ->select('DISTINCT i.category')
            ->orderBy('i.category', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'category');
    }

    /**
     * Get all unique types
     *
     * @return string[]
     */
    public function findAllTypes(): array
    {
        $result = $this->createQueryBuilder('i')
            ->select('DISTINCT i.type')
            ->orderBy('i.type', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'type');
    }

    /**
     * Get all unique rarities
     *
     * @return string[]
     */
    public function findAllRarities(): array
    {
        $result = $this->createQueryBuilder('i')
            ->select('DISTINCT i.rarity')
            ->orderBy('i.rarity', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'rarity');
    }

    /**
     * Get all unique collections
     *
     * @return string[]
     */
    public function findAllCollections(): array
    {
        $result = $this->createQueryBuilder('i')
            ->select('DISTINCT i.collection')
            ->where('i.collection IS NOT NULL')
            ->orderBy('i.collection', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'collection');
    }

    /**
     * Count items by category
     *
     * @return array<string, int>
     */
    public function countByCategory(): array
    {
        $result = $this->createQueryBuilder('i')
            ->select('i.category, COUNT(i.id) as count')
            ->groupBy('i.category')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($result as $row) {
            $counts[$row['category']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Count items by type
     *
     * @return array<string, int>
     */
    public function countByType(): array
    {
        $result = $this->createQueryBuilder('i')
            ->select('i.type, COUNT(i.id) as count')
            ->groupBy('i.type')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($result as $row) {
            $counts[$row['type']] = (int) $row['count'];
        }

        return $counts;
    }
}