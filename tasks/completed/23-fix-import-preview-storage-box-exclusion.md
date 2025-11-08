# Fix Import Preview to Exclude Storage Box Items

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-07

## Overview

Fix the inventory import preview to exclude items in storage boxes when determining which items to remove. Currently, the import system shows all items in storage boxes (both Steam-imported and manual) as "to be removed" because the comparison logic includes them, even though the deletion logic correctly excludes them.

## Problem Statement

When a user imports their Steam inventory:

1. The system fetches ALL ItemUser records for the user (including items in storage boxes)
2. It compares the Steam JSON against this full list
3. Since the Steam JSON only contains main inventory items (not items inside storage boxes), all boxed items appear as "items to remove" in the preview
4. However, the actual deletion query (line 267) correctly excludes boxed items with `->andWhere('iu.storageBox IS NULL')`

This creates a confusing UX where the preview shows boxed items as "to be removed", but they're never actually deleted. The preview should match the deletion behavior.

## Requirements

### Functional Requirements
- Import preview must ONLY show main inventory items (where `storageBox IS NULL`) as candidates for removal
- Items in storage boxes (both Steam-imported and manual) must be completely ignored during import preview comparison
- The preview's "items to remove" count and list must accurately reflect what will actually be deleted

### Non-Functional Requirements
- No database schema changes required
- No changes to deletion logic (it already works correctly)
- Maintains backward compatibility with existing import workflows

## Technical Approach

### Service Layer

**File**: `src/Service/InventoryImportService.php`

**Change Required**: Modify the `findUserInventory` call at line 108 to exclude items in storage boxes.

**Current Code** (line 108):
```php
$currentInventory = $this->itemUserRepository->findUserInventory($user->getId());
```

**Solution Options**:

**Option A**: Add filter parameter to existing method call
```php
$currentInventory = $this->itemUserRepository->findUserInventory(
    $user->getId(),
    ['storage_box_null' => true]  // New filter to exclude boxed items
);
```

**Option B**: Create dedicated repository method
```php
$currentInventory = $this->itemUserRepository->findMainInventoryOnly($user->getId());
```

**Option C**: Use existing filters more explicitly
```php
// Add a filter to the repository method that checks storageBox IS NULL
$currentInventory = $this->itemUserRepository->findUserInventory(
    $user->getId(),
    ['exclude_storage' => true]
);
```

**Recommended**: Option A or B. Option A requires updating the repository method to support a new filter. Option B is cleaner and more explicit.

### Repository Layer

**File**: `src/Repository/ItemUserRepository.php`

**If using Option A**: Add new filter handling to `findUserInventory` method (around line 66):
```php
if (isset($filters['exclude_storage']) && $filters['exclude_storage'] === true) {
    $qb->andWhere('iu.storageBox IS NULL');
}
```

**If using Option B**: Add new method:
```php
/**
 * Find user's main inventory (excludes items in storage boxes)
 *
 * @return ItemUser[]
 */
public function findMainInventoryOnly(int $userId): array
{
    return $this->createQueryBuilder('iu')
        ->where('iu.user = :userId')
        ->andWhere('iu.storageBox IS NULL')  // Only main inventory
        ->setParameter('userId', $userId)
        ->orderBy('iu.createdAt', 'DESC')
        ->getQuery()
        ->getResult();
}
```

## Implementation Steps

1. **Update ItemUserRepository** (Option B recommended):
   - Add `findMainInventoryOnly` method to `src/Repository/ItemUserRepository.php`
   - Include query condition `iu.storageBox IS NULL`
   - Add PHPDoc documentation

2. **Update InventoryImportService**:
   - Replace line 108: `$currentInventory = $this->itemUserRepository->findUserInventory($user->getId());`
   - With: `$currentInventory = $this->itemUserRepository->findMainInventoryOnly($user->getId());`
   - Verify that the `getItemsToRemove` method (lines 677-686) correctly uses this filtered list

3. **Add logging for transparency**:
   - Update logging at line 174-179 to include count of excluded boxed items
   - Example:
     ```php
     $boxedItemsCount = $this->itemUserRepository->countUserItems($user->getId()) - count($currentInventory);
     $this->logger->info('Import preview comparison results', [
         'total_items_in_import' => count($mappedItems),
         'items_to_add_count' => count($itemsToAddData),
         'items_to_remove_count' => count($itemsToRemoveData),
         'current_inventory_count' => count($currentInventory),
         'boxed_items_excluded' => $boxedItemsCount,
     ]);
     ```

4. **Manual verification**:
   - Create test scenario with items in storage boxes
   - Import Steam inventory
   - Verify preview does NOT show boxed items as "to be removed"
   - Verify actual import does NOT delete boxed items (already works, but re-verify)

## Edge Cases & Error Handling

- **Manual storage boxes**: Items in manual boxes (no assetId) must also be excluded
  - The `storageBox IS NULL` condition handles both Steam and manual boxes equally

- **Empty storage boxes**: Boxes with no items don't affect the import
  - No special handling needed

- **Items with null assetId in main inventory**: Should still appear in preview comparison
  - Not affected by this change

- **Concurrent modifications**: If items are moved during import preview
  - Existing session-based workflow handles this (data stored in session at preview time)

## Dependencies

### Blocking Dependencies
- None

### Related Tasks
- None (standalone bugfix)

### External Dependencies
- None

## Acceptance Criteria

- [ ] Repository method added or updated to exclude storage box items
- [ ] InventoryImportService updated to use filtered inventory query
- [ ] Manual verification: Import preview with boxed items shows correct "items to remove" count
- [ ] Manual verification: Import preview does NOT list boxed items in removal section
- [ ] Manual verification: Actual import does NOT delete boxed items (regression check)
- [ ] Manual verification: Test with both Steam-imported and manual storage boxes
- [ ] Logging includes count of excluded boxed items for debugging
- [ ] Code change limited to 2 files: InventoryImportService.php and ItemUserRepository.php

## Notes & Considerations

### Why This Bug Exists
The deletion logic was correctly implemented with `storageBox IS NULL` protection, but the preview comparison logic fetches ALL items. This creates a mismatch between what's shown in the preview and what actually happens.

### Performance Impact
Minimal - actually improves performance slightly by reducing the size of the array used for comparison.

### Alternative Approaches Rejected

**Filtering after fetch**: Could filter the array after fetching all items
```php
$currentInventory = array_filter(
    $this->itemUserRepository->findUserInventory($user->getId()),
    fn($item) => $item->getStorageBox() === null
);
```
**Rejected because**: Less efficient (fetches all items then filters), and the database should handle filtering.

### Future Improvements
Consider adding a dedicated inventory scope/filter system to the repository to make these kinds of queries more reusable (e.g., `findUserInventory($userId, scope: 'main_only')`).

### Testing Notes
Since this project does not use automated tests, manual verification is critical:
1. Create user with items in multiple storage boxes
2. Move some items to manual storage box
3. Import Steam inventory
4. Verify preview shows only changes to main inventory
5. Confirm import completes successfully
6. Verify boxed items remain untouched

## Related Files

- `src/Service/InventoryImportService.php:108` - Main fix location
- `src/Repository/ItemUserRepository.php` - Add new method or filter
- `src/Entity/ItemUser.php:43-45` - storageBox relationship definition
