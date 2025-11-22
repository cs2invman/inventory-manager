<?php

namespace App\Service;

use App\Entity\Item;
use App\Repository\ItemPriceRepository;
use Doctrine\ORM\EntityManagerInterface;

class PriceTrendCalculator
{
    public function __construct(
        private ItemPriceRepository $priceRepository,
        private EntityManagerInterface $em
    ) {}

    /**
     * Calculate and update trends for an item's latest price
     *
     * @param Item $item
     * @return array{trend24h: ?float, trend7d: ?float, trend30d: ?float, skipped: bool}
     */
    public function calculateAndUpdateTrends(Item $item): array
    {
        $currentPrice = $item->getCurrentPrice();

        if (!$currentPrice) {
            // Item has no current price - skip trend calculation
            return [
                'trend24h' => null,
                'trend7d' => null,
                'trend30d' => null,
                'skipped' => true,
            ];
        }

        $trends = [
            'trend24h' => $this->calculateTrend($item, 24),
            'trend7d' => $this->calculateTrend($item, 7 * 24),
            'trend30d' => $this->calculateTrend($item, 30 * 24),
        ];

        // Update the current price entity with trends
        $currentPrice->setTrend24h(
            $trends['trend24h'] !== null ? (string) $trends['trend24h'] : null
        );
        $currentPrice->setTrend7d(
            $trends['trend7d'] !== null ? (string) $trends['trend7d'] : null
        );
        $currentPrice->setTrend30d(
            $trends['trend30d'] !== null ? (string) $trends['trend30d'] : null
        );

        $this->em->flush();

        return [
            'trend24h' => $trends['trend24h'],
            'trend7d' => $trends['trend7d'],
            'trend30d' => $trends['trend30d'],
            'skipped' => false,
        ];
    }

    /**
     * Calculate trend percentage for a specific time period
     *
     * @param Item $item
     * @param int $hoursAgo Number of hours to look back
     * @return float|null Percentage change, or null if insufficient data
     */
    private function calculateTrend(Item $item, int $hoursAgo): ?float
    {
        $currentPrice = $item->getCurrentPrice();
        if (!$currentPrice || !$currentPrice->getMedianPrice()) {
            return null;
        }

        $currentValue = (float) $currentPrice->getMedianPrice();
        $currentDate = $currentPrice->getPriceDate();

        // Calculate the target date
        $targetDate = (clone $currentDate)->modify(sprintf('-%d hours', $hoursAgo));

        // Find closest price to target date
        $historicalPrice = $this->priceRepository->findClosestPriceToDate(
            $item,
            $targetDate,
            $hoursAgo // tolerance in hours
        );

        if (!$historicalPrice || !$historicalPrice->getMedianPrice()) {
            return null; // Not enough historical data
        }

        $historicalValue = (float) $historicalPrice->getMedianPrice();

        // Prevent division by zero
        if ($historicalValue == 0) {
            return null;
        }

        // Calculate percentage change: ((current - historical) / historical) * 100
        $percentageChange = (($currentValue - $historicalValue) / $historicalValue) * 100;

        // Round to 2 decimal places
        return round($percentageChange, 2);
    }
}
