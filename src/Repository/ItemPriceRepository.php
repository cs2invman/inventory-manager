<?php

namespace App\Repository;

use App\Entity\ItemPrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ItemPrice>
 */
class ItemPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ItemPrice::class);
    }

    /**
     * Find price history for an item within a date range
     *
     * @return ItemPrice[]
     */
    public function findPriceHistoryForItem(
        int $itemId,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        return $this->createQueryBuilder('ip')
            ->where('ip.item = :itemId')
            ->andWhere('ip.priceDate BETWEEN :from AND :to')
            ->setParameter('itemId', $itemId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('ip.priceDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find the latest price entry for an item
     */
    public function findLatestPriceForItem(int $itemId): ?ItemPrice
    {
        return $this->createQueryBuilder('ip')
            ->where('ip.item = :itemId')
            ->setParameter('itemId', $itemId)
            ->orderBy('ip.priceDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all price changes since a specific date
     *
     * @return ItemPrice[]
     */
    public function findPriceChangesSince(\DateTimeInterface $dateTime): array
    {
        return $this->createQueryBuilder('ip')
            ->where('ip.priceDate >= :dateTime')
            ->setParameter('dateTime', $dateTime)
            ->orderBy('ip.priceDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculate average price for an item over a number of days
     */
    public function calculateAveragePrice(int $itemId, int $days): ?float
    {
        $from = new \DateTimeImmutable("-{$days} days");

        $result = $this->createQueryBuilder('ip')
            ->select('AVG(ip.price) as avgPrice')
            ->where('ip.item = :itemId')
            ->andWhere('ip.priceDate >= :from')
            ->setParameter('itemId', $itemId)
            ->setParameter('from', $from)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['avgPrice'] !== null ? (float) $result['avgPrice'] : null;
    }

    /**
     * Calculate median price for an item over a number of days
     */
    public function calculateMedianPrice(int $itemId, int $days): ?float
    {
        $from = new \DateTimeImmutable("-{$days} days");

        $result = $this->createQueryBuilder('ip')
            ->select('AVG(ip.medianPrice) as avgMedianPrice')
            ->where('ip.item = :itemId')
            ->andWhere('ip.priceDate >= :from')
            ->andWhere('ip.medianPrice IS NOT NULL')
            ->setParameter('itemId', $itemId)
            ->setParameter('from', $from)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['avgMedianPrice'] !== null ? (float) $result['avgMedianPrice'] : null;
    }

    /**
     * Find the highest price for an item within a date range
     */
    public function findHighestPriceInRange(
        int $itemId,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): ?float {
        $result = $this->createQueryBuilder('ip')
            ->select('MAX(ip.price) as maxPrice')
            ->where('ip.item = :itemId')
            ->andWhere('ip.priceDate BETWEEN :from AND :to')
            ->setParameter('itemId', $itemId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['maxPrice'] !== null ? (float) $result['maxPrice'] : null;
    }

    /**
     * Find the lowest price for an item within a date range
     */
    public function findLowestPriceInRange(
        int $itemId,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): ?float {
        $result = $this->createQueryBuilder('ip')
            ->select('MIN(ip.price) as minPrice')
            ->where('ip.item = :itemId')
            ->andWhere('ip.priceDate BETWEEN :from AND :to')
            ->setParameter('itemId', $itemId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['minPrice'] !== null ? (float) $result['minPrice'] : null;
    }

    /**
     * Find price entries by source
     *
     * @return ItemPrice[]
     */
    public function findBySource(string $source, int $limit = 100): array
    {
        return $this->createQueryBuilder('ip')
            ->where('ip.source = :source')
            ->setParameter('source', $source)
            ->orderBy('ip.priceDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get price trend (percentage change) over a period
     * Returns positive for increase, negative for decrease
     */
    public function getPriceTrend(int $itemId, int $days): ?float
    {
        // Set to midnight to include all prices from that day
        $from = (new \DateTimeImmutable("-{$days} days"))->setTime(0, 0, 0);

        $oldestPrice = $this->createQueryBuilder('ip')
            ->where('ip.item = :itemId')
            ->andWhere('ip.priceDate >= :from')
            ->setParameter('itemId', $itemId)
            ->setParameter('from', $from)
            ->orderBy('ip.priceDate', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $latestPrice = $this->findLatestPriceForItem($itemId);

        if (!$oldestPrice || !$latestPrice) {
            return null;
        }

        $oldPrice = (float) $oldestPrice->getPrice();
        $newPrice = (float) $latestPrice->getPrice();

        if ($oldPrice == 0) {
            return null;
        }

        return (($newPrice - $oldPrice) / $oldPrice) * 100;
    }

    /**
     * Find items with significant price changes
     *
     * @return array<int, array{item_id: int, old_price: float, new_price: float, change_percent: float}>
     */
    public function findSignificantPriceChanges(
        int $days = 1,
        float $minChangePercent = 10.0
    ): array {
        $from = new \DateTimeImmutable("-{$days} days");

        // Get latest prices
        $latestPrices = $this->createQueryBuilder('ip1')
            ->select('IDENTITY(ip1.item) as item_id, MAX(ip1.priceDate) as max_date')
            ->groupBy('ip1.item')
            ->getQuery()
            ->getResult();

        $significantChanges = [];

        foreach ($latestPrices as $row) {
            $itemId = (int) $row['item_id'];
            $trend = $this->getPriceTrend($itemId, $days);

            if ($trend !== null && abs($trend) >= $minChangePercent) {
                $latestPrice = $this->findLatestPriceForItem($itemId);
                $oldPrice = $this->createQueryBuilder('ip')
                    ->where('ip.item = :itemId')
                    ->andWhere('ip.priceDate >= :from')
                    ->setParameter('itemId', $itemId)
                    ->setParameter('from', $from)
                    ->orderBy('ip.priceDate', 'ASC')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($latestPrice && $oldPrice) {
                    $significantChanges[] = [
                        'item_id' => $itemId,
                        'old_price' => (float) $oldPrice->getPrice(),
                        'new_price' => (float) $latestPrice->getPrice(),
                        'change_percent' => $trend,
                    ];
                }
            }
        }

        return $significantChanges;
    }

    /**
     * Get daily price statistics for an item
     *
     * @return array<string, array{date: string, avg: float, min: float, max: float, count: int}>
     */
    public function getDailyPriceStatistics(
        int $itemId,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        $results = $this->createQueryBuilder('ip')
            ->select(
                'DATE(ip.priceDate) as date',
                'AVG(ip.price) as avg_price',
                'MIN(ip.price) as min_price',
                'MAX(ip.price) as max_price',
                'COUNT(ip.id) as price_count'
            )
            ->where('ip.item = :itemId')
            ->andWhere('ip.priceDate BETWEEN :from AND :to')
            ->setParameter('itemId', $itemId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->getQuery()
            ->getResult();

        $statistics = [];
        foreach ($results as $row) {
            $statistics[$row['date']] = [
                'date' => $row['date'],
                'avg' => (float) $row['avg_price'],
                'min' => (float) $row['min_price'],
                'max' => (float) $row['max_price'],
                'count' => (int) $row['price_count'],
            ];
        }

        return $statistics;
    }

    /**
     * Delete old price records older than specified days
     */
    public function deleteOldPrices(int $daysToKeep): int
    {
        $cutoffDate = new \DateTimeImmutable("-{$daysToKeep} days");

        return $this->createQueryBuilder('ip')
            ->delete()
            ->where('ip.priceDate < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();
    }

    /**
     * Find the most recent price update date across all items
     * Optionally filtered by user's inventory items
     */
    public function findMostRecentPriceDate(): ?\DateTimeInterface
    {
        $result = $this->createQueryBuilder('ip')
            ->select('MAX(ip.priceDate) as maxDate')
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? new \DateTimeImmutable($result) : null;
    }

    /**
     * Find the closest price to a target date within a tolerance window
     *
     * @param \App\Entity\Item $item
     * @param \DateTimeInterface $targetDate
     * @param int $toleranceHours How many hours before/after to search
     * @return ItemPrice|null
     */
    public function findClosestPriceToDate(
        \App\Entity\Item $item,
        \DateTimeInterface $targetDate,
        int $toleranceHours = 24
    ): ?ItemPrice {
        $minDate = (clone $targetDate)->modify(sprintf('-%d hours', $toleranceHours));
        $maxDate = (clone $targetDate)->modify(sprintf('+%d hours', $toleranceHours));

        // Fetch all prices within the tolerance window
        $prices = $this->createQueryBuilder('ip')
            ->where('ip.item = :item')
            ->andWhere('ip.priceDate BETWEEN :minDate AND :maxDate')
            ->setParameter('item', $item)
            ->setParameter('minDate', $minDate)
            ->setParameter('maxDate', $maxDate)
            ->orderBy('ip.priceDate', 'ASC')
            ->getQuery()
            ->getResult();

        if (empty($prices)) {
            return null;
        }

        // Find the closest price by calculating the time difference in PHP
        $closestPrice = null;
        $smallestDiff = PHP_INT_MAX;
        $targetTimestamp = $targetDate->getTimestamp();

        foreach ($prices as $price) {
            $diff = abs($price->getPriceDate()->getTimestamp() - $targetTimestamp);
            if ($diff < $smallestDiff) {
                $smallestDiff = $diff;
                $closestPrice = $price;
            }
        }

        return $closestPrice;
    }
}