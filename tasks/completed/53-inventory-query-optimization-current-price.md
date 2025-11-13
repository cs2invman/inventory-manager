# Inventory Query Optimization with Current Price Reference

**Status**: Completed
**Priority**: High
**Estimated Effort**: Large
**Created**: 2025-11-12

## Overview

Optimize the inventory index page by eliminating N+1 query problems. Currently displaying 1493 items generates 2056 database queries (431.98ms). This task adds a `current_price_id` foreign key to the Item table and refactors queries to use eager loading with joins, reducing queries from ~2000+ to 1-3 total queries.

## Problem Statement

The InventoryController::index() method has severe N+1 query problems:

**Current behavior (lines 78-200 in InventoryController.php):**
- For EACH inventory item (1493 items):
  - 1 query to fetch latest ItemPrice (lines 82-90)
  - For EACH sticker on the item:
    - 1 query to find sticker Item by hashName (lines 110-117)
    - 1 query to fetch latest ItemPrice for sticker (lines 121-129)
  - For EACH keychain on the item:
    - 1 query to find keychain Item by hashName (lines 155-162)
    - 1 query to fetch latest ItemPrice for keychain (lines 165-173)

**Result:** 2056 queries for 1493 items = ~1.38 queries per item on average, taking 431.98ms

**Root cause:** No direct reference from Item → current/latest ItemPrice, requiring ORDER BY price_date DESC with LIMIT 1 for every price lookup.

## Requirements

### Functional Requirements
- Add `current_price_id` field to Item entity (ManyToOne → ItemPrice, nullable, onDelete: SET NULL)
- Update ItemSyncService to set `current_price_id` when creating new ItemPrice records (only for items that received new prices in that sync batch)
- Create backfill command to populate `current_price_id` for ALL existing items (one-time operation)
- Refactor InventoryController::index() to use eager loading with Doctrine joins
- Reduce total queries from 2056 to 1-3 queries for the entire page
- Maintain exact same UI/UX behavior (no visual changes)
- Optimize both main item prices AND sticker/keychain prices

### Non-Functional Requirements
- Query time should drop from ~432ms to <100ms
- Memory usage should remain stable (no large result set issues)
- Sync performance should not degrade (minimal overhead to set current_price_id)
- Backfill command should handle 10,000+ items efficiently with batching

## Technical Approach

### Database Changes

**Add to Item entity:**
```php
/**
 * Reference to the current/latest price for efficient joins
 * Updated automatically during steam sync when new prices are added
 */
#[ORM\ManyToOne(targetEntity: ItemPrice::class)]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
private ?ItemPrice $currentPrice = null;

public function getCurrentPrice(): ?ItemPrice
{
    return $this->currentPrice;
}

public function setCurrentPrice(?ItemPrice $currentPrice): static
{
    $this->currentPrice = $currentPrice;
    return $this;
}
```

**Migration:**
- Add `current_price_id` INT column to `item` table (nullable, foreign key → item_price.id, ON DELETE SET NULL)
- Add index on `current_price_id` for join performance

### Service Layer

**Update ItemSyncService::createPriceHistory()** (lines 408-514):
- After persisting new ItemPrice, set it as the Item's currentPrice:
  ```php
  if ($itemPrice->getPrice() !== null) {
      $this->entityManager->persist($itemPrice);
      $item->setCurrentPrice($itemPrice); // NEW: Set current price reference
      return true;
  }
  ```

**New Console Command: `app:item:backfill-current-price`:**
```php
// Fetch all items WITHOUT current_price_id set (or all items for full backfill)
// For each item:
//   - Find latest ItemPrice (ORDER BY price_date DESC LIMIT 1)
//   - Set item.current_price_id = found price
// Process in batches of 100 items
// Report progress: "Backfilled 1234 items with current prices"
```

### Query Optimization Strategy

**Current query structure (InventoryController):**
```php
// Step 1: Fetch ItemUser entities (1 query)
$inventoryItems = $this->itemUserRepository->findUserInventory($user->getId());

// Step 2: For EACH ItemUser, fetch Item's latest price (1493 queries)
$latestPrice = SELECT ip FROM ItemPrice WHERE ip.item = :item ORDER BY price_date DESC LIMIT 1

// Step 3: For EACH sticker, find Item + fetch latest price (2 queries per sticker)
$stickerItem = SELECT i FROM Item WHERE hashName = 'Sticker | Name'
$stickerPrice = SELECT ip FROM ItemPrice WHERE ip.item = :stickerItem ORDER BY price_date DESC LIMIT 1

// Step 4: For EACH keychain, find Item + fetch latest price (2 queries per keychain)
```

**New optimized structure:**
```php
// Step 1: Fetch ItemUser + Item + CurrentPrice + StorageBox in ONE query using joins
$qb = $this->createQueryBuilder('iu')
    ->select('iu, i, cp, sb')  // Select all needed entities
    ->leftJoin('iu.item', 'i')
    ->leftJoin('i.currentPrice', 'cp')  // NEW: Join current price directly
    ->leftJoin('iu.storageBox', 'sb')
    ->where('iu.user = :userId')
    ->setParameter('userId', $userId);

// Step 2: Build hash map of sticker/keychain hashNames from all items
$stickerHashNames = [...];  // Extract from all items' stickers JSON
$keychainHashNames = [...]; // Extract from all items' keychains JSON

// Step 3: Fetch ALL sticker/keychain Items + CurrentPrices in ONE query
if (!empty($stickerHashNames)) {
    $stickerItems = SELECT i, cp FROM Item i
                    LEFT JOIN i.currentPrice cp
                    WHERE i.hashName IN (:hashNames)
    // Build lookup map: $stickerPricesByHashName[hashName] = ItemPrice
}

// Step 4: Fetch ALL keychain Items + CurrentPrices in ONE query
if (!empty($keychainHashNames)) {
    $keychainItems = SELECT i, cp FROM Item i
                     LEFT JOIN i.currentPrice cp
                     WHERE i.hashName IN (:hashNames)
    // Build lookup map: $keychainPricesByHashName[hashName] = ItemPrice
}

// Step 5: Loop through inventory items and use in-memory lookups
// Total queries: 3 (main inventory + stickers batch + keychains batch)
```

### Implementation Steps

1. **Update Item Entity**
   - Add `currentPrice` field (ManyToOne → ItemPrice, nullable)
   - Add getter/setter methods
   - Add to `__construct()` if needed

2. **Create Database Migration**
   ```bash
   docker compose exec php php bin/console make:migration
   ```
   - Migration should add `current_price_id` column (INT, nullable, FK)
   - Add index: `CREATE INDEX idx_item_current_price ON item (current_price_id)`

3. **Update ItemSyncService**
   - Modify `createPriceHistory()` method (line 508-509)
   - After `$this->entityManager->persist($itemPrice)`, add:
     ```php
     $item->setCurrentPrice($itemPrice);
     ```

4. **Create Backfill Console Command**
   - File: `src/Command/BackfillCurrentPriceCommand.php`
   - Command name: `app:item:backfill-current-price`
   - Logic:
     ```php
     // Fetch all items (or WHERE current_price_id IS NULL)
     $items = $itemRepository->findAll();

     $batchSize = 100;
     $backfilledCount = 0;

     foreach ($items as $index => $item) {
         // Find latest price for this item
         $latestPrice = $entityManager->createQuery('
             SELECT ip FROM App\Entity\ItemPrice ip
             WHERE ip.item = :item
             ORDER BY ip.priceDate DESC
         ')
         ->setParameter('item', $item)
         ->setMaxResults(1)
         ->getOneOrNullResult();

         if ($latestPrice) {
             $item->setCurrentPrice($latestPrice);
             $backfilledCount++;
         }

         if (($index + 1) % $batchSize === 0) {
             $entityManager->flush();
             $entityManager->clear();
             $output->writeln("Processed " . ($index + 1) . " items...");
         }
     }

     $entityManager->flush();
     $output->writeln("Backfilled {$backfilledCount} items with current prices");
     ```

5. **Refactor InventoryController::index()**
   - **Step 5a:** Update ItemUserRepository::findUserInventory()
     - Add joins for item, currentPrice, storageBox
     - Return hydrated entities in one query

   - **Step 5b:** Refactor main loop to eliminate per-item queries
     - Extract all unique sticker hashNames from all items upfront
     - Extract all unique keychain hashNames from all items upfront

   - **Step 5c:** Create batch query methods in ItemRepository:
     - `findByHashNamesWithCurrentPrice(array $hashNames): array`
     - Returns Item entities with currentPrice joined
     - Build lookup map by hashName

   - **Step 5d:** Replace individual queries with map lookups
     - Instead of querying for each sticker, look up in $stickerPriceMap
     - Instead of querying for each keychain, look up in $keychainPriceMap

6. **Run Migration and Backfill**
   ```bash
   docker compose exec php php bin/console doctrine:migrations:migrate
   docker compose exec php php bin/console app:item:backfill-current-price
   ```

7. **Test and Verify**
   - Load inventory index page
   - Check Symfony debug toolbar: should show 1-3 queries (down from 2056)
   - Verify query time is <100ms (down from 432ms)
   - Verify all prices display correctly (main items, stickers, keychains)
   - Verify grouping logic still works
   - Test filtering by storage box (still efficient)

8. **Update CLAUDE.md**
   - Document new `current_price_id` field in Item entity
   - Note that sync automatically maintains current_price_id
   - Mention backfill command for future reference

## Edge Cases & Error Handling

**Edge Case 1: Item has no prices**
- `current_price_id` remains NULL
- Controller should handle NULL currentPrice gracefully (show "$0.00")
- Existing code already handles null prices, so no changes needed

**Edge Case 2: Latest price gets deleted**
- ON DELETE SET NULL ensures current_price_id becomes NULL
- Next sync will update to new latest price
- Or run backfill command to fix

**Edge Case 3: Multiple items synced in same batch**
- Each item gets its own currentPrice set
- Batch flush persists all at once (already handled by existing batching)

**Edge Case 4: Backfill command run multiple times**
- Safe to run multiple times (idempotent)
- Will just re-set current_price_id to latest price
- Performance: only processes items WHERE current_price_id IS NULL (optional optimization)

**Edge Case 5: Sticker/keychain Item doesn't exist in database**
- Already handled: existing code checks if $stickerItem is null
- New code maintains same null check, just uses map lookup instead of query

**Edge Case 6: Item has stickers but currentPrice is NULL for sticker Item**
- Already handled: existing code checks if $stickerPrice is null
- New code maintains same behavior, price value will be 0.0

**Error Handling:**
- ItemSyncService: No changes needed, already has try-catch per item
- Backfill command: Wrap in try-catch, continue on individual item failures
- Controller: Use null-safe operators (`?->`) when accessing currentPrice

## Dependencies

### Blocking Dependencies
None - this is a standalone optimization task

### Related Tasks
None - this task is self-contained

### Can Be Done in Parallel With
Any task that doesn't modify InventoryController::index() or ItemSyncService

### External Dependencies
- Doctrine ORM (already in use)
- Symfony Console component (already in use)
- MariaDB 11.x (already in use)

## Acceptance Criteria

- [ ] `current_price_id` field added to Item entity with proper annotations
- [ ] Database migration created and runs successfully
- [ ] Index created on `item.current_price_id` column
- [ ] ItemSyncService updates current_price_id when creating new prices
- [ ] Backfill command created: `app:item:backfill-current-price`
- [ ] Backfill command successfully processes all existing items (test with real data)
- [ ] ItemUserRepository::findUserInventory() refactored with eager loading
- [ ] ItemRepository has new method: `findByHashNamesWithCurrentPrice(array $hashNames)`
- [ ] InventoryController::index() refactored to use batch queries
- [ ] Inventory page shows SAME data as before (pixel-perfect match)
- [ ] Database query count reduced: 2056 → 1-3 queries (verify in Symfony profiler)
- [ ] Query time reduced: ~432ms → <100ms (verify in Symfony profiler)
- [ ] Filtering by storage box still works correctly
- [ ] Item grouping logic still works correctly
- [ ] Price calculation includes stickers/keychains correctly
- [ ] Tested with user account having 1000+ items
- [ ] CLAUDE.md updated with new field documentation

## Performance Benchmarks

**Before:**
- Items: 1493
- Database Queries: 2056
- Different statements: 6
- Query time: 431.98 ms
- Managed entities: 2517

**Target After:**
- Items: 1493
- Database Queries: 1-3 (main inventory + stickers batch + keychains batch)
- Different statements: 3
- Query time: <100 ms
- Managed entities: ~1500 (ItemUser) + ~1500 (Item) + ~1500 (ItemPrice) = ~4500

**Expected Performance Gain:**
- Query count reduction: 99.85% (2056 → 3)
- Query time reduction: 77% (432ms → <100ms)

## Implementation Notes

### Doctrine Join Fetch Strategy

When using `select('iu, i, cp')`, Doctrine hydrates all entities in memory at once:
- `iu` = ItemUser entities
- `i` = Item entities
- `cp` = ItemPrice entities (currentPrice)

This is called "fetch joining" and eliminates lazy loading queries.

### Why Not Use Partial Objects?

We're selecting full entities (`iu, i, cp`) instead of partial objects because:
1. The template needs full Item data (name, category, rarity, image, etc.)
2. Partial objects can cause Doctrine issues with change tracking
3. The entities are small enough that memory isn't a concern

### Memory Considerations

With 1493 items:
- ItemUser entities: ~1500 × ~500 bytes = 750 KB
- Item entities: ~1500 × ~1 KB = 1.5 MB
- ItemPrice entities: ~1500 × ~300 bytes = 450 KB
- Total: ~2.7 MB (negligible)

Stickers/keychains:
- Assume average 2 stickers per 10 items = 300 sticker Items
- 300 × ~1 KB = 300 KB (also negligible)

**Conclusion:** Memory is not a concern, eager loading is safe.

### Alternative Considered: Cache Layer

An alternative approach would be to cache the latest ItemPrice ID in Redis:
- Key: `item:{item_id}:current_price_id`
- Value: ItemPrice ID

**Rejected because:**
1. Adds complexity (Redis dependency, cache invalidation)
2. Database is already fast with proper indexes
3. Direct SQL join is simpler and more reliable
4. This optimization is sufficient (99.85% query reduction)

## Notes & Considerations

**Why only update current_price_id for newly synced items?**
- The sync already processes items in batches
- Only items that receive NEW prices in this sync need current_price_id updated
- Items without new prices keep their existing current_price_id (still valid)
- This minimizes write overhead during sync

**Why backfill existing items?**
- Existing items (before this change) have no current_price_id set
- Without backfill, they would show "$0.00" until next sync
- Backfill is a one-time operation to populate historical data

**Why eager loading instead of separate optimized queries?**
- Eager loading is Doctrine's recommended approach for solving N+1
- Single query with joins is faster than multiple separate queries
- Reduces network round-trips to database
- Leverages database's join optimization

**Why not add current_price_id to ItemPrice table pointing to next price?**
- Doesn't solve the problem (we need Item → latest price, not price → price)
- Would require updating old records when new prices arrive
- Current approach is simpler and more maintainable

**Future Optimization Ideas:**
- Add similar `current_price_id` to ItemUser if tracking historical values
- Consider materialized view for "active inventory with prices" if this grows to 10,000+ items
- Add database-level trigger to auto-update current_price_id on INSERT to item_price (probably overkill)

## Manual Verification Steps

1. **Before optimization:**
   - Open inventory page in browser
   - Open Symfony profiler
   - Note query count (~2056) and time (~432ms)
   - Take screenshot of inventory display
   - Note a few specific item prices (including stickered items)

2. **After optimization:**
   - Run migration and backfill
   - Reload inventory page (hard refresh)
   - Open Symfony profiler
   - Verify query count is 1-3
   - Verify query time is <100ms
   - Compare with screenshot (should be identical)
   - Verify same item prices are displayed
   - Test filter by storage box
   - Test with different user accounts

3. **Edge case testing:**
   - Find item with no price → should show $0.00
   - Find item with stickers → prices should display
   - Find item with keychain → price should display
   - Find grouped items (cases) → quantity and aggregate price correct

## Related Documentation

- Doctrine Query Optimization: https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/dql-doctrine-query-language.html#joins
- Symfony Profiler: https://symfony.com/doc/current/profiler.html
- N+1 Query Problem: https://stackoverflow.com/questions/97197/what-is-the-n1-selects-problem

## Post-Implementation Monitoring

After deploying this optimization, monitor:
- Inventory page load time (should be <1 second total)
- Database query count via profiler (should stay at 1-3)
- Memory usage during inventory load (should be <10 MB)
- Sync command performance (should not degrade)
- User reports of incorrect prices (should be zero)

If query count creeps back up over time, investigate:
- New features added to inventory page
- Lazy loading being triggered somewhere
- Joins not being used properly
