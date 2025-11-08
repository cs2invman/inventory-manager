<?php

namespace App\Repository;

use App\Entity\ItemUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ItemUser>
 */
class ItemUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ItemUser::class);
    }

    /**
     * Find user's inventory with optional filters
     *
     * @param array $filters Supported: item_type, category, rarity, wear_category, is_stattrak, storage_box
     * @return ItemUser[]
     */
    public function findUserInventory(int $userId, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('iu')
            ->leftJoin('iu.item', 'i')
            ->where('iu.user = :userId')
            ->setParameter('userId', $userId);

        if (isset($filters['item_type'])) {
            $qb->andWhere('i.type = :itemType')
                ->setParameter('itemType', $filters['item_type']);
        }

        if (isset($filters['category'])) {
            $qb->andWhere('i.category = :category')
                ->setParameter('category', $filters['category']);
        }

        if (isset($filters['rarity'])) {
            $qb->andWhere('i.rarity = :rarity')
                ->setParameter('rarity', $filters['rarity']);
        }

        if (isset($filters['wear_category'])) {
            $qb->andWhere('iu.wearCategory = :wearCategory')
                ->setParameter('wearCategory', $filters['wear_category']);
        }

        if (isset($filters['is_stattrak'])) {
            $qb->andWhere('iu.isStattrak = :isStattrak')
                ->setParameter('isStattrak', $filters['is_stattrak']);
        }

        if (isset($filters['is_souvenir'])) {
            $qb->andWhere('iu.isSouvenir = :isSouvenir')
                ->setParameter('isSouvenir', $filters['is_souvenir']);
        }

        if (isset($filters['storage_box'])) {
            $qb->andWhere('iu.storageBoxName = :storageBox')
                ->setParameter('storageBox', $filters['storage_box']);
        }

        if (isset($filters['min_value'])) {
            $qb->andWhere('iu.currentMarketValue >= :minValue')
                ->setParameter('minValue', $filters['min_value']);
        }

        if (isset($filters['max_value'])) {
            $qb->andWhere('iu.currentMarketValue <= :maxValue')
                ->setParameter('maxValue', $filters['max_value']);
        }

        $qb->orderBy('iu.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find items by storage box
     *
     * @return ItemUser[]
     */
    public function findByStorageBox(int $userId, string $boxName): array
    {
        return $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.storageBoxName = :boxName')
            ->setParameter('userId', $userId)
            ->setParameter('boxName', $boxName)
            ->orderBy('iu.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculate total inventory value for a user
     */
    public function calculateInventoryValue(int $userId): float
    {
        $result = $this->createQueryBuilder('iu')
            ->select('SUM(iu.currentMarketValue) as totalValue')
            ->where('iu.user = :userId')
            ->andWhere('iu.currentMarketValue IS NOT NULL')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (float) $result : 0.0;
    }

    /**
     * Find all unique storage boxes for a user
     *
     * @return string[]
     */
    public function findUserStorageBoxes(int $userId): array
    {
        $result = $this->createQueryBuilder('iu')
            ->select('DISTINCT iu.storageBoxName')
            ->where('iu.user = :userId')
            ->andWhere('iu.storageBoxName IS NOT NULL')
            ->setParameter('userId', $userId)
            ->orderBy('iu.storageBoxName', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'storageBoxName');
    }

    /**
     * Find StatTrak items in user's inventory
     *
     * @return ItemUser[]
     */
    public function findStattrakItems(int $userId): array
    {
        return $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.isStattrak = true')
            ->setParameter('userId', $userId)
            ->orderBy('iu.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find Souvenir items in user's inventory
     *
     * @return ItemUser[]
     */
    public function findSouvenirItems(int $userId): array
    {
        return $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.isSouvenir = true')
            ->setParameter('userId', $userId)
            ->orderBy('iu.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items with custom name tags
     *
     * @return ItemUser[]
     */
    public function findNameTaggedItems(int $userId): array
    {
        return $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.nameTag IS NOT NULL')
            ->setParameter('userId', $userId)
            ->orderBy('iu.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items with stickers
     *
     * @return ItemUser[]
     */
    public function findItemsWithStickers(int $userId): array
    {
        return $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.stickers IS NOT NULL')
            ->setParameter('userId', $userId)
            ->orderBy('iu.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find items by wear category
     *
     * @return ItemUser[]
     */
    public function findByWearCategory(int $userId, string $wearCategory): array
    {
        return $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.wearCategory = :wearCategory')
            ->setParameter('userId', $userId)
            ->setParameter('wearCategory', $wearCategory)
            ->orderBy('iu.floatValue', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find most valuable items in user's inventory
     *
     * @return ItemUser[]
     */
    public function findMostValuableItems(int $userId, int $limit = 10): array
    {
        return $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.currentMarketValue IS NOT NULL')
            ->setParameter('userId', $userId)
            ->orderBy('iu.currentMarketValue', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recently acquired items
     *
     * @return ItemUser[]
     */
    public function findRecentlyAcquired(int $userId, int $days = 7, int $limit = 20): array
    {
        $from = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.acquiredDate >= :from OR iu.createdAt >= :from')
            ->setParameter('userId', $userId)
            ->setParameter('from', $from)
            ->orderBy('COALESCE(iu.acquiredDate, iu.createdAt)', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get inventory statistics by category
     *
     * @return array<string, array{count: int, total_value: float}>
     */
    public function getInventoryStatsByCategory(int $userId): array
    {
        $results = $this->createQueryBuilder('iu')
            ->select('i.category, COUNT(iu.id) as itemCount, SUM(iu.currentMarketValue) as totalValue')
            ->leftJoin('iu.item', 'i')
            ->where('iu.user = :userId')
            ->setParameter('userId', $userId)
            ->groupBy('i.category')
            ->orderBy('totalValue', 'DESC')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($results as $row) {
            $stats[$row['category']] = [
                'count' => (int) $row['itemCount'],
                'total_value' => $row['totalValue'] !== null ? (float) $row['totalValue'] : 0.0,
            ];
        }

        return $stats;
    }

    /**
     * Get inventory statistics by rarity
     *
     * @return array<string, array{count: int, total_value: float}>
     */
    public function getInventoryStatsByRarity(int $userId): array
    {
        $results = $this->createQueryBuilder('iu')
            ->select('i.rarity, COUNT(iu.id) as itemCount, SUM(iu.currentMarketValue) as totalValue')
            ->leftJoin('iu.item', 'i')
            ->where('iu.user = :userId')
            ->setParameter('userId', $userId)
            ->groupBy('i.rarity')
            ->orderBy('totalValue', 'DESC')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($results as $row) {
            $stats[$row['rarity']] = [
                'count' => (int) $row['itemCount'],
                'total_value' => $row['totalValue'] !== null ? (float) $row['totalValue'] : 0.0,
            ];
        }

        return $stats;
    }

    /**
     * Calculate total profit/loss for user's inventory
     */
    public function calculateTotalProfitLoss(int $userId): float
    {
        $items = $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.acquiredPrice IS NOT NULL')
            ->andWhere('iu.currentMarketValue IS NOT NULL')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        $totalProfitLoss = 0.0;
        foreach ($items as $item) {
            $profitLoss = $item->calculateProfitLoss();
            if ($profitLoss !== null) {
                $totalProfitLoss += $profitLoss;
            }
        }

        return $totalProfitLoss;
    }

    /**
     * Find items with profit/loss above threshold
     *
     * @return ItemUser[]
     */
    public function findItemsWithSignificantProfitLoss(
        int $userId,
        float $minPercentage = 10.0
    ): array {
        $items = $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.acquiredPrice IS NOT NULL')
            ->andWhere('iu.currentMarketValue IS NOT NULL')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return array_filter($items, function (ItemUser $item) use ($minPercentage) {
            $percentage = $item->calculateProfitLossPercentage();
            return $percentage !== null && abs($percentage) >= $minPercentage;
        });
    }

    /**
     * Find user's main inventory (excludes items in storage boxes)
     *
     * @return ItemUser[]
     */
    public function findMainInventoryOnly(int $userId): array
    {
        return $this->createQueryBuilder('iu')
            ->where('iu.user = :userId')
            ->andWhere('iu.storageBox IS NULL')  // Only main inventory
            ->setParameter('userId', $userId)
            ->orderBy('iu.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count items in user's inventory
     */
    public function countUserItems(int $userId): int
    {
        return (int) $this->createQueryBuilder('iu')
            ->select('COUNT(iu.id)')
            ->where('iu.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find item by asset ID
     */
    public function findByAssetId(string $assetId): ?ItemUser
    {
        return $this->createQueryBuilder('iu')
            ->where('iu.assetId = :assetId')
            ->setParameter('assetId', $assetId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Search user's inventory by item name
     *
     * @return ItemUser[]
     */
    public function searchInventoryByName(int $userId, string $searchTerm): array
    {
        return $this->createQueryBuilder('iu')
            ->leftJoin('iu.item', 'i')
            ->where('iu.user = :userId')
            ->andWhere('LOWER(i.name) LIKE LOWER(:searchTerm)')
            ->setParameter('userId', $userId)
            ->setParameter('searchTerm', '%' . $searchTerm . '%')
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}