# Add Items-to-Add vs Items-to-Remove Comparison Logic

**Status**: Completed
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-03
**Part of**: Selective Inventory Import (3/6)
**Depends on**: None (independent backend work)

## Overview

Add logic to `InventoryImportService` to calculate which specific items should be added (new items in import) and which should be removed (existing items not in import) instead of doing a full delete-and-replace.

## Problem Statement

Currently, `InventoryImportService::prepareImportPreview()` only calculates counts of items to add/remove, and generates aggregate statistics (by rarity, by type, notable items).

**Current behavior:**
```php
$itemsToAdd = count(array_diff($newAssetIds, $currentAssetIds)); // just a count
$itemsToRemove = count($currentInventory); // deletes everything
```

**Desired behavior:**
- Calculate actual items to add (items in new import that don't exist in current inventory)
- Calculate actual items to remove (items in current inventory that don't exist in new import)
- Return full item data for both lists, not just counts
- Include price lookups for preview display

## Requirements

### Functional Requirements

1. **Add helper method: `getItemsToAdd()`**
   - Compare new import items with current inventory
   - Return full mapped item data for items that are new
   - Match by assetId

2. **Add helper method: `getItemsToRemove()`**
   - Compare current inventory with new import items
   - Return full ItemUser entities for items that will be removed
   - Match by assetId

3. **Update `prepareImportPreview()`**
   - Call `getItemsToAdd()` and `getItemsToRemove()`
   - Look up prices for all items (both add and remove lists)
   - Build enriched data arrays with full item details
   - Store in session for later use

4. **Update session storage**
   - Store `items_to_add` array with full data
   - Store `items_to_remove` array with full data
   - Keep existing `items` array (all items from import)

### Non-Functional Requirements

- **Performance**: Efficient comparison even with 500+ items
- **Accuracy**: Correctly identify differences by assetId
- **Data integrity**: Preserve all item properties in returned data

## Technical Approach

### 1. Add Helper Methods to InventoryImportService

**File**: `src/Service/InventoryImportService.php`

Add two private methods:

```php
/**
 * Get items that will be added (exist in new import but not in current inventory)
 */
private function getItemsToAdd(array $mappedItems, array $currentInventory): array
{
    $newAssetIds = array_map(fn($m) => $m['data']['asset_id'], $mappedItems);
    $currentAssetIds = array_map(fn($item) => $item->getAssetId(), $currentInventory);

    $assetIdsToAdd = array_diff($newAssetIds, $currentAssetIds);

    // Return full mapped items that are new
    return array_filter($mappedItems, fn($m) => in_array($m['data']['asset_id'], $assetIdsToAdd));
}

/**
 * Get items that will be removed (exist in current inventory but not in new import)
 */
private function getItemsToRemove(array $mappedItems, array $currentInventory): array
{
    $newAssetIds = array_map(fn($m) => $m['data']['asset_id'], $mappedItems);
    $currentAssetIds = array_map(fn($item) => $item->getAssetId(), $currentInventory);

    $assetIdsToRemove = array_diff($currentAssetIds, $newAssetIds);

    // Return current ItemUser entities that are no longer present
    return array_filter($currentInventory, fn($item) => in_array($item->getAssetId(), $assetIdsToRemove));
}
```

### 2. Update `prepareImportPreview()` Method

**File**: `src/Service/InventoryImportService.php`

Replace the `generatePreviewStats()` call with:

```php
// Get actual items to add/remove (not just counts)
$itemsToAdd = $this->getItemsToAdd($mappedItems, $currentInventory);
$itemsToRemove = $this->getItemsToRemove($mappedItems, $currentInventory);

// Enrich items to add with price data
$itemsToAddData = [];
foreach ($itemsToAdd as $mappedItem) {
    $item = $mappedItem['item'];
    $data = $mappedItem['data'];

    // Look up latest price for this item
    // TODO: Use InventoryService or PriceHistoryService to get prices
    $latestPrice = $this->getLatestPriceForItem($item);

    $itemsToAddData[] = [
        'itemUser' => $this->createItemUserFromData($item, $data), // temporary object for display
        'item' => $item,
        'price' => $latestPrice,
        'priceValue' => $latestPrice?->getMedianPrice() ?? 0,
        'stickers' => $data['stickers'] ?? [],
        'keychain' => $data['keychain'] ?? null,
        'assetId' => $data['asset_id'],
    ];
}

// Enrich items to remove with price data
$itemsToRemoveData = [];
foreach ($itemsToRemove as $itemUser) {
    $item = $itemUser->getItem();

    // Look up latest price
    $latestPrice = $this->getLatestPriceForItem($item);

    $itemsToRemoveData[] = [
        'itemUser' => $itemUser,
        'item' => $item,
        'price' => $latestPrice,
        'priceValue' => $latestPrice?->getMedianPrice() ?? 0,
        'stickers' => $itemUser->getStickers() ?? [],
        'keychain' => $itemUser->getKeychain() ?? null,
        'assetId' => $itemUser->getAssetId(),
    ];
}
```

### 3. Update Session Storage

**File**: `src/Service/InventoryImportService.php`

Modify `storeInSession()` method:

```php
private function storeInSession(array $mappedItems, array $storageBoxesData, array $itemsToAddData, array $itemsToRemoveData): string
{
    $session = $this->requestStack->getSession();
    $sessionKey = self::SESSION_PREFIX . bin2hex(random_bytes(16));

    $serializableData = [
        'items' => [],
        'storage_boxes' => $storageBoxesData,
        'items_to_add' => $itemsToAddData,  // NEW
        'items_to_remove' => $itemsToRemoveData,  // NEW
    ];

    foreach ($mappedItems as $mappedItem) {
        $serializableData['items'][] = [
            'item_id' => $mappedItem['item']->getId(),
            'data' => $mappedItem['data'],
        ];
    }

    $session->set($sessionKey, $serializableData);

    return $sessionKey;
}
```

### 4. Add Helper Method for Price Lookup

**File**: `src/Service/InventoryImportService.php`

Add method to get latest price (or inject existing service):

```php
private function getLatestPriceForItem(Item $item): ?ItemPrice
{
    // Use existing price repository to get latest price
    return $this->itemPriceRepository->findLatestPriceForItem($item->getId());
}
```

May need to inject `ItemPriceRepository` in constructor.

### 5. Create Temporary ItemUser for Preview

Since items to add don't exist in database yet, create temporary ItemUser objects for display:

```php
private function createItemUserFromData(Item $item, array $data): ItemUser
{
    $itemUser = new ItemUser();
    $itemUser->setItem($item);
    $itemUser->setAssetId($data['asset_id']);
    $itemUser->setFloatValue($data['float_value'] ?? null);
    $itemUser->setPatternIndex($data['pattern_index'] ?? null);
    $itemUser->setIsStattrak($data['is_stattrak'] ?? false);
    $itemUser->setIsSouvenir($data['is_souvenir'] ?? false);
    $itemUser->setStickers($data['stickers'] ?? null);
    $itemUser->setKeychain($data['keychain'] ?? null);
    $itemUser->setNameTag($data['name_tag'] ?? null);

    // Don't persist - this is just for preview display
    return $itemUser;
}
```

## Implementation Steps

1. **Add `getItemsToAdd()` helper method** (30 minutes)
   - Compare assetIds to find new items
   - Return filtered mapped items array
   - Add PHPDoc comments

2. **Add `getItemsToRemove()` helper method** (30 minutes)
   - Compare assetIds to find removed items
   - Return filtered current inventory array
   - Add PHPDoc comments

3. **Add `createItemUserFromData()` helper method** (30 minutes)
   - Create temporary ItemUser objects for preview
   - Map all fields from parsed data
   - Don't persist to database

4. **Inject ItemPriceRepository** (15 minutes)
   - Add to constructor dependencies
   - Add property and parameter

5. **Add `getLatestPriceForItem()` helper method** (15 minutes)
   - Use ItemPriceRepository to fetch latest price
   - Return ItemPrice entity or null

6. **Update `prepareImportPreview()` method** (2 hours)
   - Call helper methods to get items to add/remove
   - Loop through items to add and enrich with price data
   - Loop through items to remove and enrich with price data
   - Build `$itemsToAddData` and `$itemsToRemoveData` arrays
   - Update `ImportPreview` DTO instantiation (next task will update DTO)

7. **Update `storeInSession()` method** (30 minutes)
   - Add parameters for items to add/remove data
   - Store both arrays in session
   - Update return structure

8. **Test with console debugging** (30 minutes)
   - Add temporary logging to see items to add/remove
   - Run import preview with test data
   - Verify correct items identified
   - Check prices are looked up correctly

## Edge Cases & Error Handling

### Edge Case 1: All Items Are New
**Scenario**: First-time import, current inventory is empty.

**Handling**:
- `getItemsToRemove()` returns empty array
- `getItemsToAdd()` returns all items
- This is correct behavior

### Edge Case 2: No Changes
**Scenario**: Import JSON contains exact same items as current inventory.

**Handling**:
- Both `getItemsToAdd()` and `getItemsToRemove()` return empty arrays
- Preview should show "No changes detected"
- This will be handled in next task (UI updates)

### Edge Case 3: Items in Storage Boxes
**Scenario**: Current inventory includes items in storage boxes.

**Handling**:
- `findUserInventory()` already filters by `storageBox IS NULL`
- Items in storage are not compared or affected
- Existing behavior is correct

### Edge Case 4: Price Data Missing
**Scenario**: Some items don't have price data in database.

**Handling**:
- `getLatestPriceForItem()` returns null
- Set `priceValue` to 0
- Component will display "N/A"

## Acceptance Criteria

- [x] `getItemsToAdd()` method added to InventoryImportService
- [x] `getItemsToRemove()` method added to InventoryImportService
- [x] `createItemUserFromData()` method added to create temporary ItemUser objects
- [x] `getLatestPriceForItem()` method added to fetch prices
- [x] ItemPriceRepository injected in InventoryImportService constructor
- [x] `prepareImportPreview()` updated to call helper methods
- [x] Items to add are enriched with price data
- [x] Items to remove are enriched with price data
- [x] Session storage updated to include both item arrays
- [x] Correct items identified in add/remove lists (verified via logging)
- [x] Price lookups work correctly
- [x] Items in storage boxes are not included in comparison
- [x] No errors when inventory is empty (first import)
- [x] No errors when there are no changes

## Notes & Considerations

### Why Temporary ItemUser Objects?

Items to add don't exist in the database yet, so we create temporary (non-persisted) ItemUser objects just for display purposes. These have all the necessary data for the preview but are never saved to the database.

### Performance with Large Inventories

The `array_diff()` and `array_filter()` operations are efficient even with 500+ items. PHP handles these operations quickly in memory.

### Price Lookup Optimization

If price lookups become a bottleneck with large inventories, consider:
- Batch loading all prices in a single query
- Caching price data

## Dependencies

- None (independent backend work)

## Next Tasks

After this task is complete:
- **Task 6-4**: Update ImportPreview DTO and display actual items in preview

## Related Files

- `src/Service/InventoryImportService.php`
- `src/Repository/ItemPriceRepository.php`
- `src/Entity/ItemUser.php`
- `src/Entity/ItemPrice.php`
