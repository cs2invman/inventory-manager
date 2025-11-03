# Update Import Execution to Respect Item Selection

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-03
**Part of**: Selective Inventory Import (6/6)
**Depends on**: Task 1005 (Checkbox Selection Controls)

## Overview

Update the import execution logic to only add/remove items that were checked in the preview, instead of adding/removing all items. This completes the selective import feature.

## Problem Statement

Currently, `InventoryImportService::executeImport()`:
- Deletes ALL items where `storageBox IS NULL`
- Inserts ALL items from the session

**Desired behavior:**
- Only delete items whose IDs are in the selected list
- Only insert items whose IDs are in the selected list
- Items in storage boxes remain untouched (existing behavior)

## Requirements

### Functional Requirements

1. **Update controller to accept selected IDs**
   - Accept `selected_items[]` from POST request
   - Parse IDs to separate "add" vs "remove" (based on prefix)
   - Validate IDs exist in session data

2. **Update session storage**
   - Store default selection state (all selected)
   - Update with user's selection before execution

3. **Update executeImport() method**
   - Accept selected add/remove IDs
   - Only delete items with matching assetIds from remove list
   - Only insert items with matching identifiers from add list
   - Update success message with actual counts

4. **Handle edge cases**
   - Empty selection (should not happen due to JS validation)
   - Invalid IDs (not in session)
   - Session expiration

### Non-Functional Requirements

- **Data integrity**: Ensure correct items are added/removed
- **Performance**: Batch operations for efficiency
- **Safety**: Validate all IDs before database operations
- **Logging**: Log which items were added/removed for audit

## Technical Approach

### 1. Update InventoryImportController

**File**: `src/Controller/InventoryImportController.php`

Update the `confirm()` method:

```php
#[Route('/confirm', name: 'inventory_import_confirm', methods: ['POST'])]
public function confirm(Request $request): Response
{
    $sessionKey = $request->request->get('session_key');
    $selectedItems = $request->request->all('selected_items'); // array of IDs

    if (empty($sessionKey)) {
        $this->addFlash('error', 'Invalid session data. Please try importing again.');
        return $this->redirectToRoute('inventory_import_form');
    }

    // Validate that at least some items are selected
    if (empty($selectedItems)) {
        $this->addFlash('error', 'No items selected for import.');
        return $this->redirectToRoute('inventory_import_form');
    }

    // Separate add vs remove IDs based on prefix
    $selectedAddIds = [];
    $selectedRemoveIds = [];

    foreach ($selectedItems as $itemId) {
        if (str_starts_with($itemId, 'add-')) {
            $selectedAddIds[] = $itemId;
        } elseif (str_starts_with($itemId, 'remove-')) {
            $selectedRemoveIds[] = $itemId;
        }
    }

    try {
        $user = $this->getUser();
        $result = $this->importService->executeImport(
            $user,
            $sessionKey,
            $selectedAddIds,
            $selectedRemoveIds
        );

        if ($result->isSuccess()) {
            $this->addFlash('success', sprintf(
                'Import complete! Added %d items, removed %d items.',
                $result->addedCount ?? 0,
                $result->removedCount ?? 0
            ));

            if ($result->hasSkippedItems()) {
                $this->addFlash('warning', sprintf(
                    '%d items were skipped due to errors.',
                    count($result->skippedItems)
                ));
            }
        } else {
            $this->addFlash('error', 'Import failed. Please try again.');
            foreach ($result->errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->redirectToRoute('app_dashboard');
    } catch (\Exception $e) {
        $this->addFlash('error', 'Import failed: ' . $e->getMessage());
        return $this->redirectToRoute('inventory_import_form');
    }
}
```

### 2. Update ImportResult DTO

**File**: `src/DTO/ImportResult.php`

Add separate counts for added vs removed:

```php
public function __construct(
    public readonly int $totalProcessed,
    public readonly int $successCount,
    public readonly int $errorCount,
    public readonly array $errors,
    public readonly array $skippedItems,
    public readonly int $addedCount = 0,  // NEW
    public readonly int $removedCount = 0,  // NEW
) {
}
```

### 3. Update InventoryImportService

**File**: `src/Service/InventoryImportService.php`

**A. Update method signature:**

```php
public function executeImport(
    User $user,
    string $sessionKey,
    array $selectedAddIds = [],
    array $selectedRemoveIds = []
): ImportResult
```

**B. Update deletion logic:**

```php
// Extract assetIds from selected remove IDs
$assetIdsToRemove = [];
$itemsToRemove = $sessionData['items_to_remove'] ?? [];

foreach ($selectedRemoveIds as $selectedId) {
    // ID format: "remove-{assetId}"
    $assetId = str_replace('remove-', '', $selectedId);

    // Validate that this assetId exists in items_to_remove
    $found = false;
    foreach ($itemsToRemove as $itemData) {
        if ($itemData['assetId'] === $assetId) {
            $assetIdsToRemove[] = $assetId;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $this->logger->warning('Selected remove ID not found in session', [
            'selected_id' => $selectedId,
            'asset_id' => $assetId
        ]);
    }
}

// Delete only selected items
if (!empty($assetIdsToRemove)) {
    $qb = $this->entityManager->createQueryBuilder();
    $qb->delete(ItemUser::class, 'iu')
        ->where('iu.user = :user')
        ->andWhere('iu.storageBox IS NULL')  // NEVER touch items in storage
        ->andWhere('iu.assetId IN (:asset_ids)')
        ->setParameter('user', $user)
        ->setParameter('asset_ids', $assetIdsToRemove);

    $deletedCount = $qb->getQuery()->execute();

    $this->logger->info('Deleted selected inventory items during import', [
        'user_id' => $user->getId(),
        'deleted_count' => $deletedCount,
        'selected_count' => count($assetIdsToRemove),
    ]);
} else {
    $deletedCount = 0;
}
```

**C. Update insertion logic:**

```php
$addedCount = 0;

// Create new ItemUser entities from selected items only
foreach ($mappedItems as $index => $mappedItem) {
    $data = $mappedItem['data'];
    $assetId = $data['asset_id'];

    // Check if this item was selected for addition
    $itemId = 'add-' . $assetId;
    if (!in_array($itemId, $selectedAddIds)) {
        continue; // Skip unselected items
    }

    $totalProcessed++;

    try {
        $itemUser = new ItemUser();
        $itemUser->setUser($user);
        $itemUser->setItem($mappedItem['item']);

        // Map all fields (existing code)
        if (isset($data['asset_id'])) {
            $itemUser->setAssetId($data['asset_id']);
        }
        // ... rest of field mapping ...

        $this->entityManager->persist($itemUser);
        $successCount++;
        $addedCount++;
    } catch (\Exception $e) {
        $errorCount++;
        $errors[] = sprintf(
            'Failed to import item %s: %s',
            $data['name'] ?? $data['asset_id'] ?? 'unknown',
            $e->getMessage()
        );
        $this->logger->error('Failed to import item', [
            'asset_id' => $data['asset_id'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
```

**D. Update return statement:**

```php
return new ImportResult(
    totalProcessed: $totalProcessed,
    successCount: $successCount,
    errorCount: $errorCount,
    errors: $errors,
    skippedItems: $skippedItems,
    addedCount: $addedCount,
    removedCount: $deletedCount,
);
```

### 4. Update Session Storage (Optional Enhancement)

**File**: `src/Service/InventoryImportService.php`

Store default selection state in session:

```php
private function storeInSession(...): string
{
    // ... existing code ...

    $serializableData = [
        'items' => [...],
        'storage_boxes' => [...],
        'items_to_add' => $itemsToAddData,
        'items_to_remove' => $itemsToRemoveData,
        // Default: all items selected
        'selected_add_ids' => array_map(fn($item) => 'add-' . $item['assetId'], $itemsToAddData),
        'selected_remove_ids' => array_map(fn($item) => 'remove-' . $item['assetId'], $itemsToRemoveData),
    ];

    // ... rest of code ...
}
```

## Implementation Steps

1. **Update ImportResult DTO** (15 minutes)
   - Add `addedCount` and `removedCount` properties
   - Update constructor
   - Add PHPDoc

2. **Update InventoryImportController** (45 minutes)
   - Modify `confirm()` method to accept `selected_items[]`
   - Parse IDs to separate add vs remove
   - Validate selection is not empty
   - Pass selection arrays to `executeImport()`
   - Update success flash message to show separate counts

3. **Update executeImport() signature** (15 minutes)
   - Add `$selectedAddIds` and `$selectedRemoveIds` parameters
   - Update PHPDoc

4. **Update deletion logic** (1 hour)
   - Extract assetIds from selected remove IDs
   - Validate IDs exist in session data
   - Build assetIds array for deletion
   - Update DELETE query to use IN clause with assetIds
   - Log deleted count

5. **Update insertion logic** (1 hour)
   - Loop through mapped items
   - Check if item ID is in selected add IDs
   - Skip unselected items
   - Track added count separately
   - Update logging

6. **Update return statement** (15 minutes)
   - Return ImportResult with separate added/removed counts
   - Update error handling

7. **Test with selective import** (1 hour)
   - Import with some items unchecked
   - Verify only selected items are added
   - Verify only selected items are removed
   - Check database to confirm
   - Verify success message shows correct counts

8. **Test edge cases** (30 minutes)
   - Test with no items selected (should be blocked by JS)
   - Test with invalid IDs (should log warning and skip)
   - Test with session expired
   - Test with only add selections
   - Test with only remove selections

## Edge Cases & Error Handling

### Edge Case 1: Empty Selection
**Scenario**: User submits with no items selected (JS validation failed or disabled).

**Handling**:
- Controller validates `$selectedItems` is not empty
- Show flash error: "No items selected for import."
- Redirect back to import form

### Edge Case 2: Invalid Item IDs
**Scenario**: Selected IDs don't exist in session data.

**Handling**:
- Log warning for each invalid ID
- Skip invalid IDs (don't fail entire import)
- Continue with valid IDs
- Report skipped items in result

### Edge Case 3: Session Expired
**Scenario**: Session data not found when executing import.

**Handling**:
- Existing error handling already covers this
- Show error message and redirect to import form

### Edge Case 4: Partial Success
**Scenario**: Some items fail to add due to database errors.

**Handling**:
- Transaction ensures consistency
- If any error occurs during transaction, rollback entire import
- Report all errors to user

### Edge Case 5: All Items in Same Category Deselected
**Scenario**: User deselects all items to add, only removes items (or vice versa).

**Handling**:
- This is valid - allow pure addition or pure removal
- Counts will show 0 for the empty category
- Success message: "Import complete! Added 0 items, removed 15 items."

## Acceptance Criteria

- [ ] InventoryImportController accepts `selected_items[]` from POST
- [ ] Controller separates add vs remove IDs based on prefix
- [ ] Controller validates selection is not empty
- [ ] executeImport() accepts selected add/remove ID arrays
- [ ] Only items in selected add list are inserted to database
- [ ] Only items in selected remove list are deleted from database
- [ ] Items in storage boxes are never deleted (existing behavior preserved)
- [ ] ImportResult includes separate addedCount and removedCount
- [ ] Success message shows separate counts: "Added X items, removed Y items"
- [ ] Invalid IDs are logged and skipped without failing import
- [ ] Import with no items selected shows error message
- [ ] Import with only additions works correctly (0 removed)
- [ ] Import with only removals works correctly (0 added)
- [ ] Database accurately reflects selected changes
- [ ] Transaction rollback works if any errors occur
- [ ] Logging includes which items were added/removed

## Notes & Considerations

### ID Format

Item IDs have prefixes to distinguish add vs remove:
- **Add**: `add-{assetId}` (e.g., "add-123456789")
- **Remove**: `remove-{assetId}` (e.g., "remove-987654321")

This allows using a single `selected_items[]` form field and parsing on the server.

### Why assetId for Matching?

Using assetId (Steam's unique identifier) ensures accurate matching:
- More reliable than item name or database ID
- Directly corresponds to Steam inventory items
- Prevents accidental deletion of wrong items

### Transaction Safety

The entire import (deletes + inserts) happens in a database transaction:
- If any error occurs, everything rolls back
- Database remains consistent
- No partial imports

### Performance with Many Items

Even with 500+ items:
- `IN (:asset_ids)` query is efficient
- PHP array operations are fast
- Transaction overhead is minimal

## Dependencies

- **Task 1005**: Checkbox selection must be implemented

## Next Tasks

After this task is complete, the selective inventory import feature is **COMPLETE**! 🎉

Optional future enhancements:
- Add import history tracking
- Add "undo" feature for imports
- Add conflict resolution for updated items
- Add pagination for large inventories

## Related Files

- `src/Controller/InventoryImportController.php`
- `src/Service/InventoryImportService.php`
- `src/DTO/ImportResult.php`
- `src/Entity/ItemUser.php`
