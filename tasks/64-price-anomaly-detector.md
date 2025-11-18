# Price Anomaly Detection Processor

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Large
**Created**: 2025-11-17

## Overview

Implement a queue processor that detects significant price and volume anomalies using statistical analysis. When items experience unusual price spikes, drops, or volume changes, the system sends Discord notifications to alert about potentially significant market events. This processor responds to `PRICE_UPDATED` queue items and uses Z-score analysis to identify anomalies.

## Problem Statement

The CS2 marketplace experiences various price movements - some normal, some exceptional. We need to automatically detect and get notified about:
- **Significant price increases**: Large jumps that might indicate market manipulation, hype, or supply shortage
- **Significant price decreases**: Sharp drops that might indicate market crashes or oversupply
- **Volume spikes**: Unusual trading volume that might precede price movements
- **Volume drops**: Abnormally low volume that might indicate loss of interest

Manual monitoring is impractical with thousands of items. This processor automates anomaly detection using statistical methods researched from best practices.

## Problem Analysis & Research Findings

Based on research into time-series anomaly detection and trading alert systems, the recommended approach is:

### Statistical Method: Z-Score Analysis
- **Why**: Industry standard for anomaly detection, simple to implement, proven effective
- **How**: Measures how many standard deviations a value is from the mean
- **Threshold**: Z-score > 2 or < -2 (2 standard deviations = ~95% confidence)
- **Advantages**: Self-adjusting to each item's normal behavior, no manual thresholds per item

### Multi-Period Analysis
- **Short-term (24h)**: Detect immediate spikes/crashes
- **Medium-term (7d)**: Identify sustained trends
- **Why both**: Catches different anomaly types (flash crashes vs. sustained manipulation)

### What to Monitor
1. **Price changes**: Percentage change vs. historical volatility
2. **Volume changes**: Absolute volume vs. historical average
3. **Price-volume divergence**: High volume with minimal price change (accumulation/distribution)

### Alert Severity (Single Level for MVP)
- All anomalies treated equally for MVP
- Future: Could add warning/critical levels based on Z-score magnitude

## Requirements

### Functional Requirements
- Calculate statistical baselines for each item (mean, standard deviation)
- Detect price anomalies using Z-score analysis (24h and 7d periods)
- Detect volume anomalies using Z-score analysis
- Send Discord notifications when anomalies detected
- Use dedicated 'price_alerts' webhook
- Include item details, metrics, and context in notifications
- Process PRICE_UPDATED queue items
- Handle items with insufficient historical data gracefully

### Non-Functional Requirements
- Efficient queries for historical price/volume data
- Statistical calculations must be accurate
- Notifications must be actionable (include relevant details)
- Handle high-volume items and low-volume items differently
- Avoid false positives from normal market volatility

## Technical Approach

### Statistical Baseline Calculation

**For each item, calculate:**
- Mean price over last 30 days
- Standard deviation of price over last 30 days
- Mean volume over last 30 days
- Standard deviation of volume over last 30 days

**Z-Score Formula:**
```
z_score = (current_value - mean) / standard_deviation

Where:
- current_value: Latest price or volume
- mean: Average over lookback period (30 days)
- standard_deviation: Measure of volatility/variance
```

**Anomaly Threshold:**
- Alert if abs(z_score) >= 2.0
- Positive z-score: Value is unusually HIGH
- Negative z-score: Value is unusually LOW

### Service Layer

**PriceAnomalyDetector Service**
Location: `src/Service/PriceAnomalyDetector.php`

```php
namespace App\Service;

use App\Entity\Item;
use App\Repository\ItemPriceRepository;

class PriceAnomalyDetector
{
    private const Z_SCORE_THRESHOLD = 2.0;
    private const BASELINE_DAYS = 30;
    private const MIN_DATA_POINTS = 7; // Minimum prices needed for statistical analysis

    public function __construct(
        private ItemPriceRepository $priceRepository
    ) {}

    /**
     * Detect anomalies in latest price
     *
     * @return array{
     *     has_anomaly: bool,
     *     price_anomaly: ?array,
     *     volume_anomaly: ?array,
     *     trend_anomaly: ?array
     * }
     */
    public function detectAnomalies(Item $item): array
    {
        $currentPrice = $item->getCurrentPrice();

        if (!$currentPrice) {
            return [
                'has_anomaly' => false,
                'price_anomaly' => null,
                'volume_anomaly' => null,
                'trend_anomaly' => null
            ];
        }

        // Get historical data for baseline
        $historicalPrices = $this->priceRepository->findRecentPrices(
            $item,
            self::BASELINE_DAYS
        );

        if (count($historicalPrices) < self::MIN_DATA_POINTS) {
            // Not enough data for statistical analysis
            return [
                'has_anomaly' => false,
                'price_anomaly' => null,
                'volume_anomaly' => null,
                'trend_anomaly' => null
            ];
        }

        $priceAnomaly = $this->detectPriceAnomaly($currentPrice, $historicalPrices);
        $volumeAnomaly = $this->detectVolumeAnomaly($currentPrice, $historicalPrices);
        $trendAnomaly = $this->detectTrendAnomaly($currentPrice);

        return [
            'has_anomaly' => $priceAnomaly !== null || $volumeAnomaly !== null || $trendAnomaly !== null,
            'price_anomaly' => $priceAnomaly,
            'volume_anomaly' => $volumeAnomaly,
            'trend_anomaly' => $trendAnomaly
        ];
    }

    /**
     * Detect price anomaly using Z-score
     */
    private function detectPriceAnomaly($currentPrice, array $historicalPrices): ?array
    {
        $currentValue = (float) $currentPrice->getMedianPrice();
        if ($currentValue <= 0) {
            return null;
        }

        $values = array_map(
            fn($p) => (float) $p->getMedianPrice(),
            $historicalPrices
        );
        $values = array_filter($values, fn($v) => $v > 0);

        if (count($values) < self::MIN_DATA_POINTS) {
            return null;
        }

        $stats = $this->calculateStatistics($values);
        $zScore = $this->calculateZScore($currentValue, $stats['mean'], $stats['stddev']);

        if (abs($zScore) >= self::Z_SCORE_THRESHOLD) {
            return [
                'type' => $zScore > 0 ? 'price_spike' : 'price_drop',
                'z_score' => round($zScore, 2),
                'current_price' => $currentValue,
                'mean_price' => round($stats['mean'], 2),
                'std_dev' => round($stats['stddev'], 2),
                'deviation_percent' => round((($currentValue - $stats['mean']) / $stats['mean']) * 100, 2)
            ];
        }

        return null;
    }

    /**
     * Detect volume anomaly using Z-score
     */
    private function detectVolumeAnomaly($currentPrice, array $historicalPrices): ?array
    {
        $currentVolume = (int) $currentPrice->getVolume();
        if ($currentVolume <= 0) {
            return null;
        }

        $volumes = array_map(
            fn($p) => (int) $p->getVolume(),
            $historicalPrices
        );
        $volumes = array_filter($volumes, fn($v) => $v > 0);

        if (count($volumes) < self::MIN_DATA_POINTS) {
            return null;
        }

        $stats = $this->calculateStatistics($volumes);
        $zScore = $this->calculateZScore($currentVolume, $stats['mean'], $stats['stddev']);

        if (abs($zScore) >= self::Z_SCORE_THRESHOLD) {
            return [
                'type' => $zScore > 0 ? 'volume_spike' : 'volume_drop',
                'z_score' => round($zScore, 2),
                'current_volume' => $currentVolume,
                'mean_volume' => round($stats['mean'], 0),
                'std_dev' => round($stats['stddev'], 0),
                'deviation_percent' => round((($currentVolume - $stats['mean']) / $stats['mean']) * 100, 2)
            ];
        }

        return null;
    }

    /**
     * Detect trend anomalies (rapid acceleration)
     */
    private function detectTrendAnomaly($currentPrice): ?array
    {
        $trend24h = $currentPrice->getTrend24h();
        $trend7d = $currentPrice->getTrend7d();

        if ($trend24h === null || $trend7d === null) {
            return null;
        }

        $trend24h = (float) $trend24h;
        $trend7d = (float) $trend7d;

        // Detect rapid acceleration: 24h trend much stronger than 7d trend
        // Example: 7d trend is +5%, but 24h trend is +25% (5x acceleration)
        if (abs($trend7d) > 0.1) { // Avoid division by near-zero
            $acceleration = $trend24h / $trend7d;

            // Alert if 24h trend is 3x+ the 7d trend (rapid acceleration)
            if (abs($acceleration) >= 3.0) {
                return [
                    'type' => 'trend_acceleration',
                    'acceleration_factor' => round($acceleration, 2),
                    'trend_24h' => $trend24h,
                    'trend_7d' => $trend7d,
                    'direction' => $trend24h > 0 ? 'upward' : 'downward'
                ];
            }
        }

        return null;
    }

    /**
     * Calculate mean and standard deviation
     */
    private function calculateStatistics(array $values): array
    {
        $count = count($values);
        $mean = array_sum($values) / $count;

        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        $variance = $variance / $count;
        $stddev = sqrt($variance);

        return [
            'mean' => $mean,
            'stddev' => $stddev,
            'count' => $count
        ];
    }

    /**
     * Calculate Z-score
     */
    private function calculateZScore(float $value, float $mean, float $stddev): float
    {
        if ($stddev == 0) {
            return 0; // No variation in data
        }

        return ($value - $mean) / $stddev;
    }
}
```

### Repository Updates

**Add method to ItemPriceRepository**
Location: `src/Repository/ItemPriceRepository.php`

```php
/**
 * Find recent prices for statistical baseline
 *
 * @param Item $item
 * @param int $days Number of days to look back
 * @return ItemPrice[]
 */
public function findRecentPrices(Item $item, int $days): array
{
    $startDate = new \DateTime(sprintf('-%d days', $days));

    return $this->createQueryBuilder('ip')
        ->where('ip.item = :item')
        ->andWhere('ip.priceDate >= :startDate')
        ->setParameter('item', $item)
        ->setParameter('startDate', $startDate)
        ->orderBy('ip.priceDate', 'ASC')
        ->getQuery()
        ->getResult();
}
```

### Processor Implementation

**PriceAnomalyProcessor**
Location: `src/Service/QueueProcessor/PriceAnomalyProcessor.php`

```php
namespace App\Service\QueueProcessor;

use App\Entity\ProcessQueue;
use App\Service\PriceAnomalyDetector;
use App\Service\DiscordWebhookService;
use Psr\Log\LoggerInterface;

class PriceAnomalyProcessor implements ProcessorInterface
{
    public function __construct(
        private PriceAnomalyDetector $anomalyDetector,
        private DiscordWebhookService $discordService,
        private LoggerInterface $logger
    ) {}

    public function process(ProcessQueue $queueItem): void
    {
        $item = $queueItem->getItem();

        $this->logger->debug('Checking for price anomalies', [
            'item_id' => $item->getId(),
            'item_name' => $item->getName()
        ]);

        try {
            $anomalies = $this->anomalyDetector->detectAnomalies($item);

            if ($anomalies['has_anomaly']) {
                $this->sendAnomalyNotification($item, $anomalies);

                $this->logger->info('Price anomaly detected', [
                    'item_id' => $item->getId(),
                    'item_name' => $item->getName(),
                    'anomalies' => $anomalies
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to detect price anomalies', [
                'item_id' => $item->getId(),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function sendAnomalyNotification(
        \App\Entity\Item $item,
        array $anomalies
    ): void {
        $message = $this->buildDiscordMessage($item, $anomalies);
        $this->discordService->sendMessage('price_alerts', $message);
    }

    private function buildDiscordMessage(
        \App\Entity\Item $item,
        array $anomalies
    ): string {
        $parts = [];
        $parts[] = "**Price Anomaly Detected** :chart_with_upwards_trend:";
        $parts[] = "";
        $parts[] = sprintf("**Item:** %s", $item->getName());
        $parts[] = sprintf("**Item ID:** %d", $item->getId());

        $currentPrice = $item->getCurrentPrice();
        if ($currentPrice) {
            $parts[] = sprintf("**Current Price:** $%.2f", (float) $currentPrice->getMedianPrice());
            $parts[] = sprintf("**Volume:** %s", number_format($currentPrice->getVolume()));
        }

        $parts[] = "";
        $parts[] = "**Anomalies Detected:**";

        if ($anomalies['price_anomaly']) {
            $pa = $anomalies['price_anomaly'];
            $icon = $pa['type'] === 'price_spike' ? ':arrow_up:' : ':arrow_down:';
            $parts[] = sprintf(
                "%s **%s**: %.2f%% deviation (Z-score: %.2f)",
                $icon,
                ucwords(str_replace('_', ' ', $pa['type'])),
                $pa['deviation_percent'],
                $pa['z_score']
            );
            $parts[] = sprintf(
                "   Mean: $%.2f | Current: $%.2f",
                $pa['mean_price'],
                $pa['current_price']
            );
        }

        if ($anomalies['volume_anomaly']) {
            $va = $anomalies['volume_anomaly'];
            $icon = $va['type'] === 'volume_spike' ? ':arrow_up:' : ':arrow_down:';
            $parts[] = sprintf(
                "%s **%s**: %.2f%% deviation (Z-score: %.2f)",
                $icon,
                ucwords(str_replace('_', ' ', $va['type'])),
                $va['deviation_percent'],
                $va['z_score']
            );
            $parts[] = sprintf(
                "   Mean: %s | Current: %s",
                number_format($va['mean_volume']),
                number_format($va['current_volume'])
            );
        }

        if ($anomalies['trend_anomaly']) {
            $ta = $anomalies['trend_anomaly'];
            $icon = $ta['direction'] === 'upward' ? ':rocket:' : ':chart_with_downwards_trend:';
            $parts[] = sprintf(
                "%s **Trend Acceleration**: %.1fx %s acceleration",
                $icon,
                abs($ta['acceleration_factor']),
                $ta['direction']
            );
            $parts[] = sprintf(
                "   24h: %.2f%% | 7d: %.2f%%",
                $ta['trend_24h'],
                $ta['trend_7d']
            );
        }

        return implode("\n", $parts);
    }

    public function getProcessType(): string
    {
        return ProcessQueue::TYPE_PRICE_UPDATED;
    }
}
```

**IMPORTANT**: This processor shares the `PRICE_UPDATED` type with the PriceTrendProcessor (Task 63). The queue system will execute BOTH processors for each PRICE_UPDATED event. This is intentional - we want to calculate trends AND check for anomalies on every price update.

### Discord Webhook Configuration

Create a new webhook for price alerts:

```sql
INSERT INTO discord_webhook (identifier, display_name, webhook_url, description, is_enabled, created_at, updated_at)
VALUES (
    'price_alerts',
    'Price Alerts',
    'https://discord.com/api/webhooks/YOUR_WEBHOOK_ID/YOUR_WEBHOOK_TOKEN',
    'Notifications for significant price and volume anomalies',
    1,
    NOW(),
    NOW()
);
```

## Implementation Steps

1. **Create PriceAnomalyDetector Service**
   - Create `src/Service/PriceAnomalyDetector.php`
   - Implement statistical calculations (mean, stddev, z-score)
   - Implement `detectAnomalies()` method
   - Implement `detectPriceAnomaly()` method
   - Implement `detectVolumeAnomaly()` method
   - Implement `detectTrendAnomaly()` method
   - Add constants for thresholds and parameters

2. **Update ItemPriceRepository**
   - Add `findRecentPrices()` method
   - Test query to verify it returns correct data

3. **Create PriceAnomalyProcessor**
   - Create `src/Service/QueueProcessor/PriceAnomalyProcessor.php`
   - Implement ProcessorInterface
   - Implement `buildDiscordMessage()` for formatted notifications
   - Add logging for detected anomalies

4. **Create price_alerts Discord Webhook**
   - Add webhook via admin panel or SQL insert
   - Verify webhook URL is valid
   - Test with manual message: `docker compose exec php php bin/console app:discord:test-webhook price_alerts "Test alert"`

5. **Test Anomaly Detection**
   - Run sync to generate PRICE_UPDATED queue items
   - Run queue processor: `docker compose exec php php bin/console app:queue:process --type=PRICE_UPDATED`
   - Verify anomalies detected for items with unusual movements
   - Check Discord channel for notifications
   - Review logs for detection details

6. **Test Edge Cases**
   - New items (insufficient data): Should skip gracefully
   - Stable items (no anomalies): Should process but not alert
   - Force anomaly: Manually insert extreme price, verify detection

7. **Monitor and Tune**
   - Run for a few days and review alerts
   - Adjust Z_SCORE_THRESHOLD if too many/too few alerts
   - Adjust MIN_DATA_POINTS if needed

## Edge Cases & Error Handling

### Insufficient Historical Data
- **Scenario**: New item or item with few price records
- **Handling**: Return no anomalies if less than MIN_DATA_POINTS (7)
- **Reasoning**: Can't calculate reliable statistics without enough data

### Zero Standard Deviation
- **Scenario**: Item has exact same price for 30 days
- **Handling**: Z-score returns 0 (no variation = no anomaly)
- **Example**: Some items are price-stable for long periods

### Zero or Negative Prices
- **Scenario**: Data error or market manipulation
- **Handling**: Filter out zero/negative values before calculating statistics
- **Impact**: Protects against garbage data skewing calculations

### Both Processors on Same Queue Item
- **Scenario**: PRICE_UPDATED triggers both PriceTrendProcessor and PriceAnomalyProcessor
- **Handling**: Both processors execute independently
- **Order**: Doesn't matter - each reads from database
- **Dependency**: AnomalyProcessor benefits from trends calculated by TrendProcessor

### Discord API Failures
- **Scenario**: Discord webhook returns error
- **Handling**: DiscordWebhookService handles retry logic
- **Fallback**: Error logged, queue item marked as failed
- **Recovery**: Can manually retry failed items

### High-Volume Items vs. Low-Volume Items
- **Scenario**: Popular items have high volume, niche items have low volume
- **Handling**: Z-score is relative to each item's baseline (self-adjusting)
- **Example**: Volume spike for AK-47 might be 100k→200k, for niche item might be 10→20

### Flash Crashes and Recovery
- **Scenario**: Price spikes down then immediately recovers
- **Handling**: Each price update is evaluated independently
- **Result**: Get alerted on drop AND recovery (both are anomalies)
- **Benefit**: Shows full picture of market manipulation attempts

## Dependencies

### Blocking Dependencies
- Task 61: Queue Processing System Foundation (MUST be completed)
- Task 62: Queue Processor Command and Infrastructure (MUST be completed)

### Related Tasks (same feature)
- Task 63: Price Trend Calculation Processor (parallel - separate processor, but anomaly detection uses trends)
- Task 65: New Item Notification Processor (parallel - separate processor)

### Can Be Done in Parallel With
- Task 63 (though anomaly detection benefits from having trends calculated)
- Task 65 (completely independent)

### External Dependencies
- DiscordWebhookService (already exists)
- ItemPrice entity with trend columns (from Task 63)
- price_alerts Discord webhook (created in this task)

## Acceptance Criteria

- [ ] PriceAnomalyDetector service created with statistical methods
- [ ] Z-score calculation implemented correctly
- [ ] Price anomaly detection implemented with Z-score threshold
- [ ] Volume anomaly detection implemented with Z-score threshold
- [ ] Trend acceleration detection implemented
- [ ] ItemPriceRepository has findRecentPrices() method
- [ ] PriceAnomalyProcessor created implementing ProcessorInterface
- [ ] Processor auto-registered via compiler pass
- [ ] price_alerts Discord webhook created in database
- [ ] Discord message formatting includes all relevant details
- [ ] Manual verification: Run queue processor and verify anomaly detection works
- [ ] Manual verification: Items with significant price spikes trigger alerts
- [ ] Manual verification: Items with significant volume spikes trigger alerts
- [ ] Manual verification: Stable items don't trigger false alerts
- [ ] Manual verification: Discord notifications sent to price_alerts channel
- [ ] Manual verification: Notifications include item name, metrics, and context
- [ ] Items with insufficient data handled gracefully (no alerts, no errors)
- [ ] Logging includes anomaly types, Z-scores, and deviations
- [ ] Integration verified: Works with queue system and Discord service

## Notes & Considerations

### Why Z-Score Over Fixed Thresholds
- **Adaptive**: Each item has its own baseline (volatile items vs. stable items)
- **Self-tuning**: Automatically adjusts to market conditions
- **Statistically sound**: Based on proven statistical methods
- **No manual tuning**: Don't need to set thresholds per-item or per-category

### Why 2.0 Standard Deviations
- **Statistical basis**: ~95% of data falls within 2 standard deviations
- **Balanced**: Not too sensitive (avoids noise) but not too insensitive (catches real anomalies)
- **Industry standard**: Commonly used threshold in financial anomaly detection
- **Tunable**: Can adjust if too many/too few alerts in production

### Why 30-Day Baseline
- **Sufficient history**: Captures normal market cycles
- **Recent context**: Not too far in past (seasonal changes)
- **Practical**: Most items have 30 days of data after initial import
- **Balance**: Long enough for statistics, short enough to be relevant

### Multiple Processors, Same Queue Type
This is a powerful pattern:
- PRICE_UPDATED event triggers multiple processors
- Each processor performs different analysis
- Processors are independent and can be added/removed easily
- Example: Could add a third processor for price predictions

### Alert Fatigue Considerations
- If too many alerts: Increase Z_SCORE_THRESHOLD to 2.5 or 3.0
- If too few alerts: Decrease to 1.5
- Monitor alert volume over first week and adjust
- Future: Could add cooldown period (don't alert on same item within 24h)

### Future Enhancements (NOT in MVP)
- Alert severity levels (warning/critical based on Z-score magnitude)
- Cooldown period to prevent spam on same item
- Alert aggregation (daily summary instead of per-item)
- Machine learning models for more sophisticated detection
- Historical anomaly tracking (database table)
- Anomaly correlation across multiple items (market-wide events)

## Related Tasks

- Task 61: Queue Processing System Foundation (blocking)
- Task 62: Queue Processor Command and Infrastructure (blocking)
- Task 63: Price Trend Calculation Processor (parallel, but provides trends used here)
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
