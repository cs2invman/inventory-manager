# Simplify Items Page to Use Pre-Calculated Trends

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-20

## Overview

Refactor the items browser page (`/items`) to use the pre-calculated trend values stored in the `item_price` table (`trend24h`, `trend7d`, `trend30d`) instead of calculating trends on-the-fly. This simplifies the codebase by removing complicated trend calculation logic and improves performance.

## Problem Statement

The items page currently calculates price trends dynamically using the `getPriceTrend()` method in `ItemPriceRepository`. For each item displayed:
1. It fetches the oldest price from N days ago
2. Fetches the latest price
3. Calculates percentage change: `((newPrice - oldPrice) / oldPrice) * 100`

This happens at lines 569-570 in `ItemRepository::findWithLatestPriceAndTrend()`:
```php
'trend7d' => $priceRepo->getPriceTrend($itemId, 7),
'trend30d' => $priceRepo->getPriceTrend($itemId, 30),
```

**Problems with current approach:**
- **Performance**: Multiple database queries per item to calculate trends
- **Complexity**: Complicated logic spread across multiple methods
- **Redundancy**: We're calculating values that are already stored in the database
- **Inconsistency**: Old code calculating trends while new processor stores pre-calculated values

**Now that we have the PriceTrendProcessor** (Task #68), trends are automatically calculated and stored when prices update. We should use these pre-calculated values directly.

## Requirements

### Functional Requirements
- Display trend7d and trend30d from `item_price.trend7d` and `item_price.trend30d` columns
- Remove on-the-fly trend calculation logic
- Maintain existing UI behavior (trend display, sorting, filtering)
- Handle null trends gracefully (items without sufficient history)

### Non-Functional Requirements
- Improved query performance (no per-item trend calculations)
- Simplified codebase (remove unnecessary methods)
- Maintain compatibility with existing features (sorting by trends, etc.)

## Technical Approach

### Database Query Changes

**ItemRepository::findWithLatestPriceAndTrend()** (line 494)

Current approach:
```php
$sql = '
    SELECT
        ip.item_id,
        ip.price,
        ip.sold_total,
        ip.sold7d,
        ip.sold30d,
        ip.volume_buy_orders,
        ip.volume_sell_orders,
        ip.price_date
    FROM item_price ip
    ...
';
```

**Change to:**
```php
$sql = '
    SELECT
        ip.item_id,
        ip.price,
        ip.sold_total,
        ip.sold7d,
        ip.sold30d,
        ip.volume_buy_orders,
        ip.volume_sell_orders,
        ip.price_date,
        ip.trend7d,
        ip.trend30d
    FROM item_price ip
    ...
';
```

Then update result building (lines 560-574):
```php
$results[] = [
    'item' => $item,
    'latestPrice' => $priceData ? (float) $priceData['price'] : null,
    'volume' => $priceData ? (int) $priceData['sold_total'] : null,
    'sold7d' => $priceData ? (int) $priceData['sold7d'] : null,
    'sold30d' => $priceData ? (int) $priceData['sold30d'] : null,
    'volumeBuyOrders' => $priceData ? (int) $priceData['volume_buy_orders'] : null,
    'volumeSellOrders' => $priceData ? (int) $priceData['volume_sell_orders'] : null,
    'priceDate' => $priceData ? new \DateTimeImmutable($priceData['price_date']) : null,
    'trend7d' => $priceData && $priceData['trend7d'] !== null ? (float) $priceData['trend7d'] : null,
    'trend30d' => $priceData && $priceData['trend30d'] !== null ? (float) $priceData['trend30d'] : null,
];
```

### Remove Unused Code

**ItemPriceRepository::getPriceTrend()** (lines 170-199)
- This method is no longer needed for the items page
- Check if it's used elsewhere before deleting:
  - `findSignificantPriceChanges()` at line 223 - update this method too
  - Search for other usages: `grep -r "getPriceTrend" src/`

**Options:**
1. **If only used by items page:** Delete the method entirely
2. **If used elsewhere:** Keep it but document that new code should use stored trends

### Update Trend Sorting

**ItemRepository::findItemIdsSortedByTrend()** (line 707)

Currently builds complex SQL to calculate trends on-the-fly for sorting.

**Simplify to:**
```php
public function findItemIdsSortedByTrend(
    array $filters = [],
    int $days = 7,
    string $sortDirection = 'ASC',
    int $limit = 25,
    int $offset = 0
): array {
    $conn = $this->getEntityManager()->getConnection();

    // Determine which trend column to sort by
    $trendColumn = $days === 7 ? 'ip.trend7d' : 'ip.trend30d';

    // Build WHERE clause (same as before for filters)
    // ... existing filter logic ...

    // Build SQL with simpler JOIN using stored trend
    $sql = "
        SELECT DISTINCT i.id
        FROM item i
        INNER JOIN (
            SELECT item_id, MAX(price_date) as max_date
            FROM item_price
            GROUP BY item_id
        ) latest ON i.id = latest.item_id
        INNER JOIN item_price ip ON i.id = ip.item_id AND ip.price_date = latest.max_date
        WHERE " . implode(' AND ', $whereClauses) . "
        ORDER BY {$trendColumn} " . ($sortDirection === 'ASC' ? 'ASC' : 'DESC') . " NULLS LAST
        LIMIT :limit OFFSET :offset
    ";

    // Execute and return item IDs
}
```

### Clean Up Service Layer

**ItemTableService::getItemsWithTrendSorting()** (lines 127-178)
- This method fetches large batches and sorts in PHP
- With stored trends, this complexity is no longer needed
- Can be deleted if trend sorting is handled by repository SQL

## Implementation Steps

1. **Update ItemRepository::findWithLatestPriceAndTrend()**
   - Add `ip.trend7d, ip.trend30d` to SELECT clause (line 524)
   - Update result array building to use fetched trend values (lines 569-570)
   - Remove calls to `$priceRepo->getPriceTrend()`

2. **Simplify ItemRepository::findItemIdsSortedByTrend()**
   - Replace complex trend calculation SQL with simple `ORDER BY ip.trend7d` or `ip.trend30d`
   - Use `NULLS LAST` to handle items without trends

3. **Test trend display**
   - Browse to `/items`
   - Verify trends display correctly (green for positive, red for negative)
   - Check items with no trends show "--"
   - Test sorting by 7d trend and 30d trend

4. **Test trend sorting**
   - Sort by "7d Trend" (ascending and descending)
   - Sort by "30d Trend" (ascending and descending)
   - Verify items without trends appear last
   - Verify sorting works with filters active

5. **Check for other usages of getPriceTrend()**
   - Search codebase: `grep -rn "getPriceTrend" src/`
   - Update or document any other usages found

6. **Clean up unused code** (if applicable)
   - Remove `ItemTableService::getItemsWithTrendSorting()` if no longer used
   - Remove or deprecate `ItemPriceRepository::getPriceTrend()` if no longer needed
   - Update `findSignificantPriceChanges()` if it uses `getPriceTrend()`

7. **Manual verification**
   - Test with various filters (category, rarity, price range)
   - Test sorting by each column (especially trend columns)
   - Test pagination with trend sorting
   - Verify performance improvement (fewer queries per page load)

## Edge Cases & Error Handling

### Items Without Trends
- **Scenario**: Newly added items or items without sufficient price history
- **Current state**: `trend7d` and `trend30d` are `NULL` in database
- **Handling**: Already handled - fetching null from database, frontend displays "--"
- **No changes needed**: Existing null handling works

### Items Not Yet Processed
- **Scenario**: Item added to queue but PriceTrendProcessor hasn't run yet
- **Handling**: Trends will be NULL, display "--" until processor runs
- **User experience**: No different from current behavior (items without history)

### Sorting with NULLs
- **Scenario**: User sorts by trend, some items have NULL trends
- **Handling**: Use `NULLS LAST` in SQL so items with trends appear first
- **Behavior**: Items without trends appear at end of list regardless of sort direction

## Dependencies

### Blocking Dependencies
- Task 68: Price Trend Calculation Processor (MUST be completed - trends must be in database)

### External Dependencies
- `item_price.trend7d`, `item_price.trend30d`, `item_price.trend24h` columns (added in Task 68)
- PriceTrendProcessor must be running to keep trends up-to-date

## Acceptance Criteria

- [ ] ItemRepository::findWithLatestPriceAndTrend() uses stored trend columns from database
- [ ] No calls to ItemPriceRepository::getPriceTrend() from items page code path
- [ ] ItemRepository::findItemIdsSortedByTrend() uses stored trends for sorting
- [ ] Manual verification: Browse /items page, trends display correctly
- [ ] Manual verification: Sort by "7d Trend" ascending - items sorted correctly, nulls last
- [ ] Manual verification: Sort by "7d Trend" descending - items sorted correctly, nulls last
- [ ] Manual verification: Sort by "30d Trend" ascending - items sorted correctly, nulls last
- [ ] Manual verification: Sort by "30d Trend" descending - items sorted correctly, nulls last
- [ ] Manual verification: Items without trends show "--" in trend columns
- [ ] Manual verification: Positive trends show green with "+" prefix
- [ ] Manual verification: Negative trends show red with "-" sign
- [ ] Manual verification: Trend sorting works with active filters (category, rarity, price)
- [ ] Manual verification: Pagination works correctly when sorted by trends
- [ ] Code review: Removed or documented getPriceTrend() method appropriately
- [ ] Code review: No other code still calculating trends on-the-fly

## Notes & Considerations

### Performance Improvement
- **Before**: N database queries to calculate trends (2 queries per item × items per page)
- **After**: 0 extra queries (trends included in main price query)
- **Example**: Page with 25 items = 50 fewer queries per page load

### Code Simplification
- Removes ~30 lines of trend calculation logic from `getPriceTrend()`
- Simplifies `findItemIdsSortedByTrend()` by using stored values
- May allow removing `getItemsWithTrendSorting()` entirely

### Trend Freshness
- Trends are as fresh as the last PriceTrendProcessor run
- Should be acceptable since prices update daily
- If real-time trends needed, would require different architecture

### Migration Path
- No data migration needed (trends already being calculated by processor)
- Old and new code can coexist briefly during deployment
- Safe to deploy - worst case is trends show NULL until processor runs

### What NOT to Remove
- Keep `trend24h` column even though UI only shows 7d and 30d
- Keep processor calculating all three trends (might add 24h to UI later)
- Keep frontend formatTrend() function (still needed for display logic)

## Related Tasks

- Task 68: Price Trend Calculation Processor (blocking - provides the stored trends)

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
