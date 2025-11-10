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

    /**
     * Find an item by its Steam class ID
     */
    public function findByClassId(string $classId): ?Item
    {
        return $this->createQueryBuilder('i')
            ->where('i.classId = :classId')
            ->setParameter('classId', $classId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get all unique subcategories
     *
     * @return string[]
     */
    public function findAllSubcategories(): array
    {
        $result = $this->createQueryBuilder('i')
            ->select('DISTINCT i.subcategory')
            ->where('i.subcategory IS NOT NULL')
            ->orderBy('i.subcategory', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'subcategory');
    }

    /**
     * Find items with filters, sorting, and pagination
     *
     * @param array $filters Filter criteria (search, category, subcategory, type, rarity, stattrakAvailable, souvenirAvailable, active)
     * @param string $sortBy Column to sort by
     * @param string $sortDirection Sort direction (ASC or DESC)
     * @param int $limit Maximum number of results
     * @param int $offset Result offset for pagination
     * @return Item[]
     */
    public function findAllWithFiltersAndPagination(
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'ASC',
        int $limit = 25,
        int $offset = 0
    ): array {
        $qb = $this->createQueryBuilder('i');

        // Apply filters
        $this->applyFilters($qb, $filters);

        // Validate and apply sorting
        $validSortColumns = ['name', 'category', 'subcategory', 'type', 'rarity', 'updatedAt'];
        if (!in_array($sortBy, $validSortColumns)) {
            $sortBy = 'name';
        }

        $sortDirection = strtoupper($sortDirection) === 'DESC' ? 'DESC' : 'ASC';
        $qb->orderBy('i.' . $sortBy, $sortDirection);

        // Apply pagination
        $qb->setFirstResult($offset)
           ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Count items matching filters
     *
     * @param array $filters Filter criteria (same as findAllWithFiltersAndPagination)
     * @return int
     */
    public function countWithFilters(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)');

        // Apply same filters
        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Count items matching filters including price range
     * Uses SQL query with price join for accurate counting when price filters are present
     *
     * @param array $filters Filter criteria
     * @return int
     */
    public function countWithPriceFilters(array $filters = []): int
    {
        $conn = $this->getEntityManager()->getConnection();

        // Build WHERE clause (same logic as findItemIdsSortedByPrice)
        $whereClauses = ['i.active = :active'];
        $params = ['active' => $filters['active'] ?? true ? 1 : 0];

        // Text search
        if (!empty($filters['search']) && strlen($filters['search']) >= 2) {
            $whereClauses[] = '(LOWER(i.name) LIKE :search OR LOWER(i.market_name) LIKE :search OR LOWER(i.hash_name) LIKE :search)';
            $params['search'] = '%' . strtolower($filters['search']) . '%';
        }

        // Exact match filters
        if (!empty($filters['category'])) {
            $whereClauses[] = 'i.category = :category';
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['subcategory'])) {
            $whereClauses[] = 'i.subcategory = :subcategory';
            $params['subcategory'] = $filters['subcategory'];
        }

        if (!empty($filters['type'])) {
            $whereClauses[] = 'i.type = :type';
            $params['type'] = $filters['type'];
        }

        if (!empty($filters['rarity'])) {
            $whereClauses[] = 'i.rarity = :rarity';
            $params['rarity'] = $filters['rarity'];
        }

        // Boolean filters
        if (isset($filters['stattrakAvailable']) && $filters['stattrakAvailable'] !== '') {
            $whereClauses[] = 'i.stattrak_available = :stattrakAvailable';
            $params['stattrakAvailable'] = (bool) $filters['stattrakAvailable'] ? 1 : 0;
        }

        if (isset($filters['souvenirAvailable']) && $filters['souvenirAvailable'] !== '') {
            $whereClauses[] = 'i.souvenir_available = :souvenirAvailable';
            $params['souvenirAvailable'] = (bool) $filters['souvenirAvailable'] ? 1 : 0;
        }

        // Price range filters
        if (isset($filters['minPrice']) && is_numeric($filters['minPrice'])) {
            $whereClauses[] = 'latest_price.price >= :minPrice';
            $params['minPrice'] = (float) $filters['minPrice'];
        }

        if (isset($filters['maxPrice']) && is_numeric($filters['maxPrice'])) {
            $whereClauses[] = 'latest_price.price <= :maxPrice';
            $params['maxPrice'] = (float) $filters['maxPrice'];
        }

        $whereClause = implode(' AND ', $whereClauses);

        // Build COUNT SQL with price join
        $sql = "
            SELECT COUNT(DISTINCT i.id) as total
            FROM item i
            LEFT JOIN (
                SELECT ip.item_id, ip.price, ip.sold_total
                FROM item_price ip
                INNER JOIN (
                    SELECT item_id, MAX(price_date) as max_date
                    FROM item_price
                    GROUP BY item_id
                ) latest ON ip.item_id = latest.item_id AND ip.price_date = latest.max_date
            ) latest_price ON i.id = latest_price.item_id
            WHERE {$whereClause}
        ";

        $stmt = $conn->executeQuery($sql, $params);
        $result = $stmt->fetchAssociative();

        return (int) ($result['total'] ?? 0);
    }

    /**
     * Apply filter criteria to a QueryBuilder
     *
     * @param \Doctrine\ORM\QueryBuilder $qb
     * @param array $filters
     * @return void
     */
    private function applyFilters($qb, array $filters): void
    {
        // Default: only show active items
        $active = $filters['active'] ?? true;
        $qb->andWhere('i.active = :active')
           ->setParameter('active', $active);

        // Text search across name, marketName, and hashName
        if (!empty($filters['search']) && strlen($filters['search']) >= 2) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(i.name)', ':search'),
                    $qb->expr()->like('LOWER(i.marketName)', ':search'),
                    $qb->expr()->like('LOWER(i.hashName)', ':search')
                )
            )->setParameter('search', '%' . strtolower($filters['search']) . '%');
        }

        // Exact match filters
        if (!empty($filters['category'])) {
            $qb->andWhere('i.category = :category')
               ->setParameter('category', $filters['category']);
        }

        if (!empty($filters['subcategory'])) {
            $qb->andWhere('i.subcategory = :subcategory')
               ->setParameter('subcategory', $filters['subcategory']);
        }

        if (!empty($filters['type'])) {
            $qb->andWhere('i.type = :type')
               ->setParameter('type', $filters['type']);
        }

        if (!empty($filters['rarity'])) {
            $qb->andWhere('i.rarity = :rarity')
               ->setParameter('rarity', $filters['rarity']);
        }

        // Boolean filters
        if (isset($filters['stattrakAvailable']) && $filters['stattrakAvailable'] !== '') {
            $qb->andWhere('i.stattrakAvailable = :stattrakAvailable')
               ->setParameter('stattrakAvailable', (bool) $filters['stattrakAvailable']);
        }

        if (isset($filters['souvenirAvailable']) && $filters['souvenirAvailable'] !== '') {
            $qb->andWhere('i.souvenirAvailable = :souvenirAvailable')
               ->setParameter('souvenirAvailable', (bool) $filters['souvenirAvailable']);
        }
    }

    /**
     * Find items with their latest price and trend data
     *
     * @param array $itemIds Array of item IDs to fetch data for
     * @return array Array of associative arrays with item, price, and trend data
     */
    public function findWithLatestPriceAndTrend(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        // Fetch items
        $items = $this->createQueryBuilder('i')
            ->where('i.id IN (:itemIds)')
            ->setParameter('itemIds', $itemIds)
            ->getQuery()
            ->getResult();

        // Create indexed array of items by ID for quick lookup
        $itemsById = [];
        foreach ($items as $item) {
            $itemsById[$item->getId()] = $item;
        }

        // Fetch latest prices for all items in a single query using subquery
        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT
                ip.item_id,
                ip.price,
                ip.sold_total,
                ip.price_date
            FROM item_price ip
            INNER JOIN (
                SELECT item_id, MAX(price_date) as max_date
                FROM item_price
                WHERE item_id IN (?)
                GROUP BY item_id
            ) latest ON ip.item_id = latest.item_id AND ip.price_date = latest.max_date
        ';

        $stmt = $conn->executeQuery(
            $sql,
            [$itemIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $latestPrices = $stmt->fetchAllAssociative();

        // Index prices by item_id
        $pricesByItemId = [];
        foreach ($latestPrices as $priceData) {
            $pricesByItemId[$priceData['item_id']] = $priceData;
        }

        // Get ItemPriceRepository for trend calculations
        $priceRepo = $this->getEntityManager()->getRepository(\App\Entity\ItemPrice::class);

        // Build result array
        $results = [];
        foreach ($itemIds as $itemId) {
            if (!isset($itemsById[$itemId])) {
                continue;
            }

            $item = $itemsById[$itemId];
            $priceData = $pricesByItemId[$itemId] ?? null;

            $results[] = [
                'item' => $item,
                'latestPrice' => $priceData ? (float) $priceData['price'] : null,
                'volume' => $priceData ? (int) $priceData['sold_total'] : null,
                'priceDate' => $priceData ? new \DateTimeImmutable($priceData['price_date']) : null,
                'trend7d' => $priceRepo->getPriceTrend($itemId, 7),
                'trend30d' => $priceRepo->getPriceTrend($itemId, 30),
            ];
        }

        return $results;
    }

    /**
     * Find items with filters, sorting by price/volume, and pagination
     * Uses SQL join to sort efficiently at database level
     *
     * @param array $filters Filter criteria
     * @param string $sortBy Price field to sort by (price or volume)
     * @param string $sortDirection Sort direction (ASC or DESC)
     * @param int $limit Maximum number of results
     * @param int $offset Result offset for pagination
     * @return array Array of item IDs sorted by price
     */
    public function findItemIdsSortedByPrice(
        array $filters = [],
        string $sortBy = 'price',
        string $sortDirection = 'ASC',
        int $limit = 25,
        int $offset = 0
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        // Build WHERE clause
        $whereClauses = ['i.active = :active'];
        $params = ['active' => $filters['active'] ?? true ? 1 : 0];

        // Text search
        if (!empty($filters['search']) && strlen($filters['search']) >= 2) {
            $whereClauses[] = '(LOWER(i.name) LIKE :search OR LOWER(i.market_name) LIKE :search OR LOWER(i.hash_name) LIKE :search)';
            $params['search'] = '%' . strtolower($filters['search']) . '%';
        }

        // Exact match filters
        if (!empty($filters['category'])) {
            $whereClauses[] = 'i.category = :category';
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['subcategory'])) {
            $whereClauses[] = 'i.subcategory = :subcategory';
            $params['subcategory'] = $filters['subcategory'];
        }

        if (!empty($filters['type'])) {
            $whereClauses[] = 'i.type = :type';
            $params['type'] = $filters['type'];
        }

        if (!empty($filters['rarity'])) {
            $whereClauses[] = 'i.rarity = :rarity';
            $params['rarity'] = $filters['rarity'];
        }

        // Boolean filters
        if (isset($filters['stattrakAvailable']) && $filters['stattrakAvailable'] !== '') {
            $whereClauses[] = 'i.stattrak_available = :stattrakAvailable';
            $params['stattrakAvailable'] = (bool) $filters['stattrakAvailable'] ? 1 : 0;
        }

        if (isset($filters['souvenirAvailable']) && $filters['souvenirAvailable'] !== '') {
            $whereClauses[] = 'i.souvenir_available = :souvenirAvailable';
            $params['souvenirAvailable'] = (bool) $filters['souvenirAvailable'] ? 1 : 0;
        }

        // Price range filters
        if (isset($filters['minPrice']) && is_numeric($filters['minPrice'])) {
            $whereClauses[] = 'latest_price.price >= :minPrice';
            $params['minPrice'] = (float) $filters['minPrice'];
        }

        if (isset($filters['maxPrice']) && is_numeric($filters['maxPrice'])) {
            $whereClauses[] = 'latest_price.price <= :maxPrice';
            $params['maxPrice'] = (float) $filters['maxPrice'];
        }

        $whereClause = implode(' AND ', $whereClauses);

        // Validate sort field
        $validSortFields = ['price', 'volume'];
        if (!in_array($sortBy, $validSortFields)) {
            $sortBy = 'price';
        }

        $sortDirection = strtoupper($sortDirection) === 'DESC' ? 'DESC' : 'ASC';

        // Build SQL - join with latest prices and sort
        // Note: LIMIT and OFFSET must be integers directly in SQL, not bound parameters
        $sql = "
            SELECT i.id
            FROM item i
            LEFT JOIN (
                SELECT ip.item_id, ip.price, ip.sold_total as volume
                FROM item_price ip
                INNER JOIN (
                    SELECT item_id, MAX(price_date) as max_date
                    FROM item_price
                    GROUP BY item_id
                ) latest ON ip.item_id = latest.item_id AND ip.price_date = latest.max_date
            ) latest_price ON i.id = latest_price.item_id
            WHERE {$whereClause}
            ORDER BY latest_price.{$sortBy} {$sortDirection}, i.name ASC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $conn->executeQuery($sql, $params);
        $results = $stmt->fetchAllAssociative();

        return array_map(fn($row) => (int) $row['id'], $results);
    }
}