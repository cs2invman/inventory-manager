# Price Trend Calculation Processor

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-17

## Overview

Implement a queue processor that calculates and stores price trend percentages (24-hour, 7-day, and 30-day) directly on the `item_price` table when price updates occur. This processor responds to `PRICE_UPDATED` queue items and provides trend analysis for market price movements.

## Problem Statement

When new prices are added to items, we want to automatically calculate trend percentages showing how the price has changed over different time periods (24 hours, 7 days, 30 days). These trends help users and the system identify:
- Short-term price volatility (24h)
- Medium-term trends (7d)
- Long-term market movements (30d)

Currently, we have historical price data but no calculated trends. This processor will fill that gap by computing and storing trends whenever prices are updated.

## Requirements

### Functional Requirements
- Calculate 24-hour price trend percentage
- Calculate 7-day price trend percentage
- Calculate 30-day price trend percentage
- Store trends directly on the ItemPrice entity (new columns)
- Handle cases where insufficient historical data exists (e.g., new items)
- Process PRICE_UPDATED queue items
- Use latest price as the "current" price for calculations

### Non-Functional Requirements
- Efficient queries for historical price lookups
- Handle missing data gracefully (null trends if not enough history)
- Atomic updates (all three trends or none)
- Proper error handling with descriptive messages

## Technical Approach

### Database Changes

**Update ItemPrice Entity**
Add three new columns to track trend percentages:

```php
// In src/Entity/ItemPrice.php

#[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
private ?string $trend24h = null;

#[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
private ?string $trend7d = null;

#[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
private ?string $trend30d = null;

// Getters and setters
public function getTrend24h(): ?string
{
    return $this->trend24h;
}

public function setTrend24h(?string $trend24h): self
{
    $this->trend24h = $trend24h;
    return $this;
}

public function getTrend7d(): ?string
{
    return $this->trend7d;
}

public function setTrend7d(?string $trend7d): self
{
    $this->trend7d = $trend7d;
    return $this;
}

public function getTrend30d(): ?string
{
    return $this->trend30d;
}

public function setTrend30d(?string $trend30d): self
{
    $this->trend30d = $trend30d;
    return $this;
}
```

**Migration**
- Add `trend_24h`, `trend_7d`, `trend_30d` columns to `item_price` table
- All nullable (new items won't have enough history)
- Decimal(10,2) to store percentages (e.g., 15.75 for 15.75% increase)

### Service Layer

**PriceTrendCalculator Service**
Location: `src/Service/PriceTrendCalculator.php`

```php
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
     * @return array{trend24h: ?float, trend7d: ?float, trend30d: ?float}
     */
    public function calculateAndUpdateTrends(Item $item): array
    {
        $currentPrice = $item->getCurrentPrice();

        if (!$currentPrice) {
            throw new \RuntimeException(
                sprintf('Item #%d has no current price', $item->getId())
            );
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

        return $trends;
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
```

### Repository Updates

**Add method to ItemPriceRepository**
Location: `src/Repository/ItemPriceRepository.php`

```php
/**
 * Find the closest price to a target date within a tolerance window
 *
 * @param Item $item
 * @param \DateTimeInterface $targetDate
 * @param int $toleranceHours How many hours before/after to search
 * @return ItemPrice|null
 */
public function findClosestPriceToDate(
    Item $item,
    \DateTimeInterface $targetDate,
    int $toleranceHours = 24
): ?ItemPrice {
    $minDate = (clone $targetDate)->modify(sprintf('-%d hours', $toleranceHours));
    $maxDate = (clone $targetDate)->modify(sprintf('+%d hours', $toleranceHours));

    $qb = $this->createQueryBuilder('ip')
        ->where('ip.item = :item')
        ->andWhere('ip.priceDate BETWEEN :minDate AND :maxDate')
        ->setParameter('item', $item)
        ->setParameter('minDate', $minDate)
        ->setParameter('maxDate', $maxDate)
        ->orderBy('ABS(TIMESTAMPDIFF(SECOND, ip.priceDate, :targetDate))', 'ASC')
        ->setParameter('targetDate', $targetDate)
        ->setMaxResults(1);

    return $qb->getQuery()->getOneOrNullResult();
}
```

### Processor Implementation

**PriceTrendProcessor**
Location: `src/Service/QueueProcessor/PriceTrendProcessor.php`

```php
namespace App\Service\QueueProcessor;

use App\Entity\ProcessQueue;
use App\Service\PriceTrendCalculator;
use Psr\Log\LoggerInterface;

class PriceTrendProcessor implements ProcessorInterface
{
    public function __construct(
        private PriceTrendCalculator $trendCalculator,
        private LoggerInterface $logger
    ) {}

    public function process(ProcessQueue $queueItem): void
    {
        $item = $queueItem->getItem();

        $this->logger->info('Calculating price trends', [
            'item_id' => $item->getId(),
            'item_name' => $item->getName()
        ]);

        try {
            $trends = $this->trendCalculator->calculateAndUpdateTrends($item);

            $this->logger->info('Price trends calculated', [
                'item_id' => $item->getId(),
                'trend_24h' => $trends['trend24h'],
                'trend_7d' => $trends['trend7d'],
                'trend_30d' => $trends['trend30d']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to calculate price trends', [
                'item_id' => $item->getId(),
                'error' => $e->getMessage()
            ]);

            throw $e; // Re-throw to mark queue item as failed
        }
    }

    public function getProcessType(): string
    {
        return ProcessQueue::TYPE_PRICE_UPDATED;
    }
}
```

**Service Registration**
The processor will be auto-registered via the `_instanceof` configuration from Task 62.

## Implementation Steps

1. **Update ItemPrice Entity**
   - Add `trend24h`, `trend7d`, `trend30d` properties
   - Add getter and setter methods
   - Use nullable decimal(10,2) type

2. **Create Database Migration**
   - Run: `docker compose exec php php bin/console make:migration`
   - Review migration to ensure three columns are added correctly
   - Run: `docker compose exec php php bin/console doctrine:migrations:migrate`

3. **Update ItemPriceRepository**
   - Add `findClosestPriceToDate()` method
   - Use TIMESTAMPDIFF to find closest price by date
   - Test query manually to verify it works

4. **Create PriceTrendCalculator Service**
   - Create `src/Service/PriceTrendCalculator.php`
   - Implement `calculateAndUpdateTrends()` method
   - Implement private `calculateTrend()` method
   - Add proper error handling for missing data

5. **Create PriceTrendProcessor**
   - Create `src/Service/QueueProcessor/PriceTrendProcessor.php`
   - Implement ProcessorInterface
   - Inject PriceTrendCalculator
   - Add logging for success and failure cases

6. **Test Processor**
   - Clear cache: `docker compose exec php php bin/console cache:clear`
   - Run sync to create PRICE_UPDATED queue items
   - Run queue processor: `docker compose exec php php bin/console app:queue:process`
   - Verify trends are calculated and stored in database
   - Check for items with insufficient historical data (should have null trends)

7. **Backfill Existing Prices (Optional)**
   - Create console command to backfill trends for existing prices
   - Run: `docker compose exec php php bin/console app:item:backfill-price-trends`
   - This is optional - can be done separately if needed

## Edge Cases & Error Handling

### New Items (No Historical Data)
- **Scenario**: Item just appeared on market, no 24h/7d/30d history
- **Handling**: `findClosestPriceToDate()` returns null, trend is set to null
- **Display**: UI should show "N/A" or "--" for null trends
- **Example**: New case released today can't have 7-day trend

### Insufficient Data for Some Periods
- **Scenario**: Item has 3 days of history - can calculate 24h, maybe 7d, but not 30d
- **Handling**: Calculate what's possible, set others to null
- **Result**: trend24h = 5.2, trend7d = null, trend30d = null

### Price Data Gaps
- **Scenario**: Item had no price recorded exactly 7 days ago
- **Handling**: `findClosestPriceToDate()` searches within tolerance window (±24 hours)
- **Fallback**: If no price within tolerance, trend is null
- **Reasoning**: Better to have no trend than inaccurate trend from wrong date

### Zero Price Historical Data
- **Scenario**: Historical price is $0 (market data error)
- **Handling**: Return null trend to avoid division by zero
- **Logging**: Log warning about zero-price data

### Current Price Missing
- **Scenario**: Item has no currentPrice set
- **Handling**: Throw exception - this shouldn't happen if queue is populated correctly
- **Recovery**: Queue item marked as failed, admin can investigate

### Multiple Price Updates Rapidly
- **Scenario**: Item gets 3 price updates in 1 hour
- **Handling**: Each creates a queue item, trends calculated for each
- **Impact**: Later calculations will reference more recent historical prices
- **Optimization**: Could deduplicate queue items, but MVP accepts this

## Dependencies

### Blocking Dependencies
- Task 61: Queue Processing System Foundation (MUST be completed - needs ProcessQueue entity)
- Task 62: Queue Processor Command and Infrastructure (MUST be completed - needs processor registry)

### Related Tasks (same feature)
- Task 64: Price Anomaly Detection Processor (parallel - separate processor)
- Task 65: New Item Notification Processor (parallel - separate processor)

### Can Be Done in Parallel With
- Task 64 and 65 (all are independent processors)

### External Dependencies
- ItemPrice entity (already exists)
- Item entity (already exists)
- ItemPriceRepository (already exists, adding method)

## Acceptance Criteria

- [ ] ItemPrice entity updated with trend24h, trend7d, trend30d columns
- [ ] Database migration created and executed successfully
- [ ] Three new columns exist in item_price table
- [ ] ItemPriceRepository has findClosestPriceToDate() method
- [ ] PriceTrendCalculator service created with calculation logic
- [ ] PriceTrendProcessor created implementing ProcessorInterface
- [ ] Processor auto-registered via compiler pass
- [ ] Processor correctly implements getProcessType() returning PRICE_UPDATED
- [ ] Manual verification: Run sync to generate PRICE_UPDATED queue items
- [ ] Manual verification: Run queue processor and verify trends calculated
- [ ] Manual verification: Check database - item_price records have trend values
- [ ] Manual verification: Items with insufficient history have null trends
- [ ] Manual verification: Percentage calculations are accurate (spot check a few)
- [ ] Error handling works: Items with no currentPrice fail gracefully
- [ ] Logging includes item ID, calculated trends, and any errors
- [ ] Integration verified: Processor works with queue system from Task 62

## Notes & Considerations

### Why Store Trends on ItemPrice
- **Query performance**: Pre-calculated trends are faster than calculating on-demand
- **Consistency**: Historical trends are preserved (what the trend WAS at that time)
- **Simplicity**: No need for complex queries to calculate trends in UI
- **Trade-off**: Uses more storage, but storage is cheap

### Why Nullable Trends
- **New items**: Can't calculate trends without history
- **Data gaps**: Sometimes historical data is missing
- **Market anomalies**: Better null than wrong calculation
- **UI flexibility**: Can show "N/A" or hide trend section

### Percentage Calculation Formula
```
percentage_change = ((current - historical) / historical) * 100

Examples:
- Price went from $100 to $115: (115-100)/100 * 100 = 15% (positive trend)
- Price went from $100 to $85: (85-100)/100 * 100 = -15% (negative trend)
- Price stayed $100: (100-100)/100 * 100 = 0% (no change)
```

### Time Period Considerations
- **24 hours**: Short-term volatility, good for day traders
- **7 days**: Weekly trends, smooths out daily noise
- **30 days**: Monthly trends, shows sustained movements
- **Tolerance window**: ±24 hours allows for data gaps without failing

### Future Enhancements (NOT in MVP)
- 90-day and 1-year trends
- Trend smoothing algorithms (moving averages)
- Trend visualization in UI (graphs)
- Trend-based alerts (e.g., "sustained 7-day uptrend")
- Backfill command for existing prices

## Related Tasks

- Task 61: Queue Processing System Foundation (blocking)
- Task 62: Queue Processor Command and Infrastructure (blocking)
- Task 64: Price Anomaly Detection Processor (parallel)
- Task 65: New Item Notification Processor (parallel)

---

## Task Completion Instructions

When this task is fully complete:

1. **Update this file**:
   - Change `**Status**: Not Started` to `**Status**: Completed`
   - Add completion date: `**Completed**: [Date]`

2. **Move to completed folder**:
   - Move this file from `tasks/` to `tasks/completed/`
   - Keep the same filename

3. **Verify all acceptance criteria** are checked off before marking as complete
