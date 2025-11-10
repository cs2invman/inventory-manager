<?php

namespace App\Service;

use App\DTO\PriceHistoryDTO;
use App\Entity\Item;
use App\Entity\ItemPrice;
use App\Repository\ItemPriceRepository;
use Doctrine\ORM\EntityManagerInterface;

class PriceHistoryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ItemPriceRepository $itemPriceRepository
    ) {
    }

    /**
     * Get price history for an item within a date range
     *
     * @return ItemPrice[]
     */
    public function getPriceHistory(
        Item $item,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        return $this->itemPriceRepository->findPriceHistoryForItem($item->getId(), $from, $to);
    }

    /**
     * Get the latest price for an item
     */
    public function getLatestPrice(Item $item): ?ItemPrice
    {
        return $this->itemPriceRepository->findLatestPriceForItem($item->getId());
    }

    /**
     * Get price changes since a specific date
     *
     * @return ItemPrice[]
     */
    public function getPriceChangesSince(\DateTimeInterface $dateTime): array
    {
        return $this->itemPriceRepository->findPriceChangesSince($dateTime);
    }

    /**
     * Calculate average price over a period
     */
    public function calculateAveragePrice(Item $item, int $days): ?float
    {
        return $this->itemPriceRepository->calculateAveragePrice($item->getId(), $days);
    }

    /**
     * Calculate median price over a period
     */
    public function calculateMedianPrice(Item $item, int $days): ?float
    {
        return $this->itemPriceRepository->calculateMedianPrice($item->getId(), $days);
    }

    /**
     * Get highest price in a date range
     */
    public function getHighestPrice(
        Item $item,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): ?float {
        return $this->itemPriceRepository->findHighestPriceInRange($item->getId(), $from, $to);
    }

    /**
     * Get lowest price in a date range
     */
    public function getLowestPrice(
        Item $item,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): ?float {
        return $this->itemPriceRepository->findLowestPriceInRange($item->getId(), $from, $to);
    }

    /**
     * Get price trend (percentage change) over a period
     */
    public function getPriceTrend(Item $item, int $days): ?float
    {
        return $this->itemPriceRepository->getPriceTrend($item->getId(), $days);
    }

    /**
     * Find items with significant price changes
     *
     * @return array<int, array{item_id: int, old_price: float, new_price: float, change_percent: float}>
     */
    public function findSignificantPriceChanges(int $days = 1, float $minChangePercent = 10.0): array
    {
        return $this->itemPriceRepository->findSignificantPriceChanges($days, $minChangePercent);
    }

    /**
     * Get daily price statistics for an item
     *
     * @return array<string, array{date: string, avg: float, min: float, max: float, count: int}>
     */
    public function getDailyPriceStatistics(
        Item $item,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        return $this->itemPriceRepository->getDailyPriceStatistics($item->getId(), $from, $to);
    }

    /**
     * Get prices by source
     *
     * @return ItemPrice[]
     */
    public function getPricesBySource(string $source, int $limit = 100): array
    {
        return $this->itemPriceRepository->findBySource($source, $limit);
    }

    /**
     * Record a new price
     */
    public function recordPrice(
        Item $item,
        string $price,
        string $source,
        ?\DateTimeImmutable $priceDate = null,
        ?int $soldTotal = null,
        ?string $medianPrice = null
    ): ItemPrice {
        $itemPrice = new ItemPrice();
        $itemPrice->setItem($item);
        $itemPrice->setPrice($price);
        $itemPrice->setSource($source);
        $itemPrice->setPriceDate($priceDate ?? new \DateTimeImmutable());

        if ($soldTotal !== null) {
            $itemPrice->setSoldTotal($soldTotal);
        }
        if ($medianPrice !== null) {
            $itemPrice->setMedianPrice($medianPrice);
        }

        $this->entityManager->persist($itemPrice);
        $this->entityManager->flush();

        return $itemPrice;
    }

    /**
     * Delete old price records
     */
    public function deleteOldPrices(int $daysToKeep = 365): int
    {
        return $this->itemPriceRepository->deleteOldPrices($daysToKeep);
    }

    /**
     * Get price analysis for an item
     */
    public function getPriceAnalysis(Item $item, int $days = 30): array
    {
        $from = new \DateTimeImmutable("-{$days} days");
        $to = new \DateTimeImmutable();

        $latestPrice = $this->getLatestPrice($item);
        $avgPrice = $this->calculateAveragePrice($item, $days);
        $medianPrice = $this->calculateMedianPrice($item, $days);
        $highestPrice = $this->getHighestPrice($item, $from, $to);
        $lowestPrice = $this->getLowestPrice($item, $from, $to);
        $trend = $this->getPriceTrend($item, $days);

        return [
            'item_id' => $item->getId(),
            'item_name' => $item->getName(),
            'period_days' => $days,
            'latest_price' => $latestPrice?->getPriceAsFloat(),
            'latest_price_date' => $latestPrice?->getPriceDate()?->format('c'),
            'average_price' => $avgPrice,
            'median_price' => $medianPrice,
            'highest_price' => $highestPrice,
            'lowest_price' => $lowestPrice,
            'price_trend_percent' => $trend,
            'price_volatility' => $highestPrice && $lowestPrice ? (($highestPrice - $lowestPrice) / $lowestPrice) * 100 : null,
        ];
    }

    /**
     * Convert entity to DTO
     */
    public function toDTO(ItemPrice $itemPrice): PriceHistoryDTO
    {
        return PriceHistoryDTO::fromEntity($itemPrice);
    }

    /**
     * Convert multiple entities to DTOs
     *
     * @param ItemPrice[] $prices
     * @return PriceHistoryDTO[]
     */
    public function toDTOs(array $prices): array
    {
        return array_map(fn(ItemPrice $price) => $this->toDTO($price), $prices);
    }
}