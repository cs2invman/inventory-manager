# Enhance Item and ItemPrice Entities with Market Activity Data

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium (2-3 hours)
**Created**: 2025-11-10

## Overview

Expand the database schema to capture comprehensive market activity data from SteamWebAPI, including sales volumes, buy/sell order data, and time-series median prices. This enables future alerting features for significant market changes.

## Problem Statement

Currently, the ItemPrice entity only stores basic price data (price, volume, median_price, lowest_price, highest_price). The SteamWebAPI provides much richer market data including:
- Time-series sales data (sold today, 7d, 30d, total)
- Buy/sell order volumes and prices
- Time-series median prices (24h, 7d, 30d)
- Item stability indicators

We need to capture this data to:
1. Monitor market trends over time
2. Build alerting for significant price/volume changes
3. Provide users with richer market insights in the future

## Requirements

### Functional Requirements

**ItemPrice Entity Changes:**
- Rename `volume` → `sold_total` (preserve existing data)
- Add `sold_30d`, `sold_7d`, `sold_today` (integer fields)
- Add `volume_buy_orders`, `volume_sell_orders` (integer fields)
- Add `price_buy_order` (decimal)
- Add `price_median`, `price_median_24h`, `price_median_7d`, `price_median_30d` (decimal fields)
- Remove `lowest_price` and `highest_price` fields

**Item Entity Changes:**
- Add `unstable` (boolean, default false)
- Add `unstable_reason` (string, nullable)
- Note: `collection` field already exists, just needs sync mapping update

**Sync Service Updates:**
- Map all new ItemPrice fields from API data
- Map Item.unstable and unstable_reason
- Update collection mapping to use `tag7`
- Remove mapping for deprecated fields

**Data Reprocessing:**
- Run sync on all existing JSON files in `var/data/steam-items/processed/`
- Verify new fields are populated correctly

### Non-Functional Requirements

- Preserve all existing price history data during migration
- Maintain backward compatibility with existing queries
- Keep memory-efficient batch processing (25 items per batch)
- Handle null values gracefully for items without complete data

## Technical Approach

### Database Changes

**ItemPrice entity modifications:**
1. Rename column: `volume` → `sold_total`
2. Add integer fields: `sold_30d`, `sold_7d`, `sold_today`, `volume_buy_orders`, `volume_sell_orders`
3. Add decimal fields: `price_buy_order`, `price_median`, `price_median_24h`, `price_median_7d`, `price_median_30d`
4. Remove fields: `lowest_price`, `highest_price`

**Item entity modifications:**
1. Add boolean field: `unstable` (default: false)
2. Add string field: `unstable_reason` (nullable, length: 255)

### Service Layer

**ItemSyncService changes:**

Update `createPriceHistory()` method to map new fields:
```php
// Sales volume fields
if (isset($priceData['soldtotal'])) {
    $itemPrice->setSoldTotal((int) $priceData['soldtotal']);
}
if (isset($priceData['sold30d'])) {
    $itemPrice->setSold30d((int) $priceData['sold30d']);
}
if (isset($priceData['sold7d'])) {
    $itemPrice->setSold7d((int) $priceData['sold7d']);
}
if (isset($priceData['soldtoday'])) {
    $itemPrice->setSoldToday((int) $priceData['soldtoday']);
}

// Order volume fields
if (isset($priceData['buyordervolume'])) {
    $itemPrice->setVolumeBuyOrders((int) $priceData['buyordervolume']);
}
if (isset($priceData['offervolume'])) {
    $itemPrice->setVolumeSellOrders((int) $priceData['offervolume']);
}

// Price fields
if (isset($priceData['buyorderprice'])) {
    $itemPrice->setPriceBuyOrder((string) $priceData['buyorderprice']);
}
if (isset($priceData['pricemedian'])) {
    $itemPrice->setPriceMedian((string) $priceData['pricemedian']);
}
if (isset($priceData['pricemedian24h'])) {
    $itemPrice->setPriceMedian24h((string) $priceData['pricemedian24h']);
}
if (isset($priceData['pricemedian7d'])) {
    $itemPrice->setPriceMedian7d((string) $priceData['pricemedian7d']);
}
if (isset($priceData['pricemedian30d'])) {
    $itemPrice->setPriceMedian30d((string) $priceData['pricemedian30d']);
}

// Remove old field mappings
// DELETE: setLowestPrice() and setHighestPrice() calls
```

Update `mapItemFields()` method for Item stability:
```php
// Collection mapping (already exists in entity)
if (isset($data['tag7'])) {
    $item->setCollection($data['tag7']);
}

// Stability indicators
if (isset($data['unstable'])) {
    $item->setUnstable((bool) $data['unstable']);
}
if (isset($data['unstablereason'])) {
    $item->setUnstableReason($data['unstablereason']);
}
```

### Configuration

No new environment variables or Docker configuration needed.

## Implementation Steps

### 1. Update ItemPrice Entity

- Open `src/Entity/ItemPrice.php`
- Rename property `$volume` → `$soldTotal` with proper annotations
- Update getter/setter: `getVolume()`/`setVolume()` → `getSoldTotal()`/`setSoldTotal()`
- Add new integer properties:
  - `$sold30d`, `$sold7d`, `$soldToday`
  - `$volumeBuyOrders`, `$volumeSellOrders`
- Add new decimal properties (precision: 10, scale: 2, nullable: true):
  - `$priceBuyOrder`
  - `$priceMedian`, `$priceMedian24h`, `$priceMedian7d`, `$priceMedian30d`
- Remove properties: `$lowestPrice`, `$highestPrice`
- Add/update all getters/setters (including `*AsFloat()` methods for decimal fields)

### 2. Update Item Entity

- Open `src/Entity/Item.php`
- Add boolean property: `$unstable` (default: false)
- Add string property: `$unstableReason` (nullable, length: 255)
- Add getters/setters: `isUnstable()`, `setUnstable()`, `getUnstableReason()`, `setUnstableReason()`
- Verify `$collection` property exists (already added in previous work)

### 3. Create Migration

```bash
docker compose exec php php bin/console make:migration
```

Review the generated migration and verify:
- Correct column rename: `volume` → `sold_total`
- All new columns added with correct types and defaults
- Deprecated columns removed: `lowest_price`, `highest_price`

Then run:
```bash
docker compose exec php php bin/console doctrine:migrations:migrate
```

### 4. Update ItemSyncService

- Open `src/Service/ItemSyncService.php`
- In `createPriceHistory()` method:
  - Replace `setVolume()` calls with `setSoldTotal()`
  - Add mapping for all new sales volume fields (sold30d, sold7d, soldtoday)
  - Add mapping for order volume fields (buyordervolume, offervolume)
  - Add mapping for buy order price (buyorderprice)
  - Add mapping for median price time-series (pricemedian, pricemedian24h, pricemedian7d, pricemedian30d)
  - Remove `setLowestPrice()` and `setHighestPrice()` calls
- In `mapItemFields()` method:
  - Verify collection mapping uses `tag7` (should already exist)
  - Add mapping for `unstable` field
  - Add mapping for `unstablereason` field
- Update `hasPriceData()` method to check for new fields instead of removed ones

### 5. Test Individual Item Sync

Test with a single JSON file first:
```bash
# Copy one file from processed back to import for testing
docker compose exec php php bin/console app:steam:sync-items
```

Verify:
- No errors during sync
- New fields are populated in database
- Existing price history data is preserved
- Check a few records manually in database

### 6. Reprocess All Historical Data

Move all JSON files back to import directory and reprocess:
```bash
docker compose exec php bash -c 'mv var/data/steam-items/processed/*.json var/data/steam-items/import/ 2>/dev/null || true'
docker compose exec php php bin/console app:steam:sync-items
```

Monitor:
- Memory usage stays within limits
- All files process successfully
- Price records are created (watch for duplicate detection)

### 7. Verify Data Population

Check database for successful data population:
```sql
-- Verify ItemPrice new fields are populated
SELECT
    COUNT(*) as total,
    COUNT(sold_total) as has_sold_total,
    COUNT(sold_30d) as has_sold_30d,
    COUNT(price_buy_order) as has_buy_order,
    COUNT(price_median) as has_median
FROM item_price;

-- Check sample records
SELECT * FROM item_price ORDER BY created_at DESC LIMIT 5;

-- Verify Item stability fields
SELECT
    COUNT(*) as total,
    COUNT(CASE WHEN unstable = 1 THEN 1 END) as unstable_count
FROM item;

-- Check unstable items
SELECT name, unstable_reason FROM item WHERE unstable = 1 LIMIT 10;
```

## Edge Cases & Error Handling

### Missing API Data
- All new fields nullable or have defaults
- Sync service checks `isset()` before mapping
- No errors if API doesn't provide certain fields

### Duplicate Price Records
- Existing duplicate detection remains (by item + priceDate + source)
- Reprocessing will skip existing timestamps

### Memory Management
- Batch size stays at 25 items
- Entity manager cleared after each batch
- Explicit garbage collection
- Processed files moved to avoid re-import

### Volume Field Rename
- Migration handles rename automatically
- All existing `volume` data becomes `sold_total`
- Service updated to use new method names

### Backward Compatibility
- Remove getter/setter methods only after migration
- Any code using `getLowestPrice()` or `getHighestPrice()` will fail (intentional)
- Search codebase for usage before removing:
  ```bash
  docker compose exec php bash -c 'grep -r "getLowestPrice\|getHighestPrice" src/'
  ```

## Acceptance Criteria

- [ ] ItemPrice entity has all new fields with correct types
- [ ] ItemPrice.volume renamed to sold_total, data preserved
- [ ] ItemPrice.lowest_price and highest_price removed
- [ ] Item entity has unstable and unstable_reason fields
- [ ] Migration runs successfully without errors
- [ ] ItemSyncService maps all new fields from API data
- [ ] Sync service no longer references removed fields
- [ ] Test sync with single JSON file succeeds
- [ ] Reprocess all historical JSON files successfully
- [ ] Database verification shows new fields populated correctly
- [ ] No memory issues during bulk reprocessing
- [ ] Duplicate price records are still prevented
- [ ] All existing price history data is preserved

## Notes & Considerations

### API Field Mappings Reference

**ItemPrice mappings:**
- `soldtotal` → `sold_total`
- `sold30d` → `sold_30d`
- `sold7d` → `sold_7d`
- `soldtoday` → `sold_today`
- `buyordervolume` → `volume_buy_orders`
- `offervolume` → `volume_sell_orders`
- `buyorderprice` → `price_buy_order`
- `pricemedian` → `price_median`
- `pricemedian24h` → `price_median_24h`
- `pricemedian7d` → `price_median_7d`
- `pricemedian30d` → `price_median_30d`

**Item mappings:**
- `tag7` → `collection` (already exists, just needs sync update)
- `unstable` → `unstable` (boolean)
- `unstablereason` → `unstable_reason` (string, nullable)

### Future Improvements

Once this data is captured:
1. Build trend analysis queries (sales velocity, price volatility)
2. Create alerting system for significant changes
3. Add UI components to display market activity
4. Implement price prediction based on historical trends
5. Track buy/sell order spreads for arbitrage opportunities

### Performance Considerations

- New fields add minimal storage overhead (mostly integers)
- Indexes remain efficient (composite indexes unchanged)
- Query performance unaffected by additional fields
- Batch processing maintains memory efficiency

### Data Quality

- Not all items have complete data (expected)
- Some items may have null values for new fields
- Unstable flag helps identify items with unreliable pricing
- Time-series median prices help smooth out volatility

## Related Tasks

No blocking dependencies or related tasks.
