# Storage Box Import Integration

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-02
**Part of**: Task 2 - Storage Box Management System (Phase 2)

## Overview

Integrate storage box parsing into the inventory import system so that storage boxes are automatically created/updated when users import their inventory.

**Important Behaviors:**
- **Storage Box Types**: Supports both Steam-imported storage boxes (with assetId) and manually created storage boxes (without assetId). Manual boxes are used for tracking items lent to friends.
- **Storage Box Syncing**: Only Steam storage boxes (with assetId) are updated during import. Manual boxes are never modified.
- **Item Preservation**: **Items in ANY storage container (Steam or manual) are NEVER deleted during import**. Only items in the main inventory (storageBox = null) are replaced during import.
- **Rationale**: Items in storage represent either Steam-stored items or items lent to friends. These should remain organized and preserved across imports.

## Prerequisites

- Task 2-1 (Storage Box Database Setup) must be completed

## Goals

1. Create StorageBoxService for parsing and syncing storage boxes
2. Update InventoryImportService to detect and extract storage boxes from JSON
3. Sync storage boxes during import
4. Display storage box information in import preview

## Import Workflow

When a user imports their inventory:

1. **Storage Boxes are Synced First**
   - Parse storage boxes from JSON
   - Create new Steam storage boxes (with assetId)
   - Update existing Steam storage boxes (matched by assetId)
   - Manual storage boxes (assetId = null) are never modified

2. **Main Inventory is Replaced**
   - Delete ONLY items where `storageBox IS NULL`
   - Items in ANY storage box are preserved
   - Import new items from JSON into main inventory (storageBox = null)

3. **Storage Box Item Handling**
   - Items inside Steam storage boxes in the JSON are NOT imported as individual items
   - Storage boxes track their item count, but don't store actual item references
   - This is by design: storage boxes in Steam contain "stored" items not in active inventory
   - Manual storage boxes allow you to track items you've lent to friends

4. **Result**
   - Main inventory reflects current Steam active inventory
   - Items in storage boxes remain organized:
     - Steam storage box metadata (name, count) is synced
     - Manual storage box items (lent to friends) stay in friend boxes
   - Organization and lending tracking is preserved

## Implementation Steps

### 1. Create StorageBoxService

**File**: `src/Service/StorageBoxService.php`

```php
<?php

namespace App\Service;

use App\Entity\StorageBox;
use App\Entity\User;
use App\Repository\StorageBoxRepository;
use App\Repository\ItemUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class StorageBoxService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StorageBoxRepository $storageBoxRepository,
        private ItemUserRepository $itemUserRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Extract storage box data from Steam inventory JSON response
     */
    public function extractStorageBoxesFromJson(array $jsonData): array
    {
        $assets = $jsonData['assets'] ?? [];
        $descriptions = $jsonData['descriptions'] ?? [];

        $storageBoxes = [];

        // Find storage box descriptions
        foreach ($descriptions as $description) {
            if ($this->isStorageBox($description)) {
                $storageBoxes[] = $this->parseStorageBoxData($description, $assets);
            }
        }

        return $storageBoxes;
    }

    /**
     * Check if a description represents a storage box
     */
    private function isStorageBox(array $description): bool
    {
        // Check for Tool type
        $tags = $description['tags'] ?? [];
        foreach ($tags as $tag) {
            if (($tag['category'] ?? '') === 'Type'
                && ($tag['internal_name'] ?? '') === 'CSGO_Type_Tool'
                && ($description['market_hash_name'] ?? '') === 'Storage Unit') {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse storage box metadata from description
     */
    private function parseStorageBoxData(array $description, array $assets): array
    {
        $classId = $description['classid'];
        $instanceId = $description['instanceid'];

        // Find matching asset
        $assetId = null;
        foreach ($assets as $asset) {
            if ($asset['classid'] === $classId && $asset['instanceid'] === $instanceId) {
                $assetId = $asset['assetid'];
                break;
            }
        }

        // Extract name from nametag
        $name = 'Storage Unit';
        $itemCount = 0;
        $modificationDate = null;

        $descriptions = $description['descriptions'] ?? [];
        foreach ($descriptions as $desc) {
            $descName = $desc['name'] ?? '';
            $value = $desc['value'] ?? '';

            if ($descName === 'nametag') {
                // Parse: "Name Tag: ''SOUVENIRS''"
                if (preg_match("/Name Tag:\s*['\"]([^'\"]+)['\"]/", $value, $matches)) {
                    $name = $matches[1];
                }
            } elseif ($descName === 'attr: items count') {
                // Parse: "Number of Items: 73"
                if (preg_match('/(\d+)/', $value, $matches)) {
                    $itemCount = (int) $matches[1];
                }
            } elseif ($descName === 'attr: modification date') {
                // Parse: "Modification Date: Sep 11, 2025 (22:25:42) GMT"
                if (preg_match('/Modification Date:\s*(.+)\s+GMT/', $value, $matches)) {
                    try {
                        $modificationDate = new \DateTimeImmutable($matches[1] . ' GMT');
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to parse storage box modification date', [
                            'value' => $matches[1],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        return [
            'asset_id' => $assetId,
            'name' => $name,
            'item_count' => $itemCount,
            'modification_date' => $modificationDate,
        ];
    }

    /**
     * Sync storage boxes for a user (create new, update existing)
     *
     * IMPORTANT BEHAVIORS:
     * - Only syncs Steam-imported boxes (with assetId). Manual boxes are never touched.
     * - Items in storage boxes are NEVER deleted by the import process.
     * - Import only replaces items in main inventory (storageBox = null).
     */
    public function syncStorageBoxes(User $user, array $storageBoxesData): void
    {
        foreach ($storageBoxesData as $data) {
            if (empty($data['asset_id'])) {
                $this->logger->warning('Storage box missing assetId, skipping', ['data' => $data]);
                continue;
            }

            // Find existing Steam box or create new
            $storageBox = $this->storageBoxRepository->findByAssetId($user, $data['asset_id']);

            if ($storageBox === null) {
                $storageBox = new StorageBox();
                $storageBox->setUser($user);
                $storageBox->setAssetId($data['asset_id']);
                $this->logger->info('Creating new Steam storage box', [
                    'user_id' => $user->getId(),
                    'asset_id' => $data['asset_id'],
                    'name' => $data['name']
                ]);
            }

            // Update fields (only for Steam boxes - manual boxes are never touched)
            $storageBox->setName($data['name']);
            $storageBox->setItemCount($data['item_count']);
            if ($data['modification_date'] !== null) {
                $storageBox->setModificationDate($data['modification_date']);
            }

            $this->entityManager->persist($storageBox);
        }

        $this->entityManager->flush();
    }

    /**
     * Create a manual storage box (for tracking items lent to friends)
     * Manual boxes have no assetId and are never affected by imports
     */
    public function createManualBox(User $user, string $name): StorageBox
    {
        $storageBox = new StorageBox();
        $storageBox->setUser($user);
        $storageBox->setAssetId(null);  // No assetId = manual box
        $storageBox->setName($name);
        $storageBox->setItemCount(0);

        $this->entityManager->persist($storageBox);
        $this->entityManager->flush();

        $this->logger->info('Created manual storage box', [
            'user_id' => $user->getId(),
            'name' => $name
        ]);

        return $storageBox;
    }

    /**
     * Validate that a storage box's reported item count matches actual items in DB
     */
    public function validateItemCount(StorageBox $box): bool
    {
        $actualCount = $this->itemUserRepository->count(['storageBox' => $box]);
        $reportedCount = $box->getItemCount();

        if ($actualCount !== $reportedCount) {
            $this->logger->warning('Storage box item count mismatch', [
                'storage_box_id' => $box->getId(),
                'storage_box_name' => $box->getName(),
                'reported_count' => $reportedCount,
                'actual_count' => $actualCount
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get all items in a storage box
     */
    public function getItemsInStorageBox(StorageBox $box): array
    {
        return $this->itemUserRepository->findBy(['storageBox' => $box]);
    }

    /**
     * Get all manual storage boxes for a user (for friend lending tracking)
     */
    public function getManualBoxes(User $user): array
    {
        return $this->storageBoxRepository->findManualBoxes($user);
    }

    /**
     * Get all Steam-imported storage boxes for a user
     */
    public function getSteamBoxes(User $user): array
    {
        return $this->storageBoxRepository->findSteamBoxes($user);
    }

    /**
     * Rename a manual storage box
     */
    public function renameManualBox(StorageBox $box, string $newName): void
    {
        if ($box->isSteamBox()) {
            throw new \InvalidArgumentException('Cannot rename Steam-imported storage boxes');
        }

        $box->setName($newName);
        $this->entityManager->flush();

        $this->logger->info('Renamed manual storage box', [
            'box_id' => $box->getId(),
            'old_name' => $box->getName(),
            'new_name' => $newName
        ]);
    }

    /**
     * Delete a manual storage box (moves items back to main inventory)
     */
    public function deleteManualBox(StorageBox $box): void
    {
        if ($box->isSteamBox()) {
            throw new \InvalidArgumentException('Cannot delete Steam-imported storage boxes');
        }

        // Move items back to main inventory (set storageBox to null)
        $items = $this->getItemsInStorageBox($box);
        foreach ($items as $item) {
            $item->setStorageBox(null);
        }

        $this->entityManager->remove($box);
        $this->entityManager->flush();

        $this->logger->info('Deleted manual storage box', [
            'box_id' => $box->getId(),
            'name' => $box->getName(),
            'items_moved' => count($items)
        ]);
    }
}
```

### 2. Update InventoryImportService

**File**: `src/Service/InventoryImportService.php`

Add StorageBoxService to constructor:

```php
public function __construct(
    private EntityManagerInterface $entityManager,
    private ItemRepository $itemRepository,
    private ItemUserRepository $itemUserRepository,
    private RequestStack $requestStack,
    private LoggerInterface $logger,
    private StorageBoxService $storageBoxService  // NEW
) {}
```

Update `shouldSkipItem()` to NOT skip storage boxes:

```php
private function shouldSkipItem(array $description): bool
{
    $itemType = $this->extractItemType($description);

    // Skip non-tradeable item types (but NOT storage boxes)
    $skipTypes = [
        // 'CSGO_Type_Tool',           // REMOVED - we want storage boxes now
        'CSGO_Type_Collectible',       // Medals, Coins, Badges
        'CSGO_Type_MusicKit',          // Music Kits
        'CSGO_Type_Spray',             // Graffiti
        'Type_Collectible',            // Alternative collectible type
        'Type_Spray',                  // Alternative spray type
    ];

    // Check if it's a storage box - if so, DON'T skip
    if ($itemType === 'CSGO_Type_Tool') {
        $marketHashName = $description['market_hash_name'] ?? '';
        if ($marketHashName === 'Storage Unit') {
            return false;  // Don't skip storage boxes
        }
        return true;  // Skip other tools
    }

    if (in_array($itemType, $skipTypes)) {
        return true;
    }

    // Additional check: Skip items with "Collectible" tag category
    if (isset($description['tags']) && is_array($description['tags'])) {
        foreach ($description['tags'] as $tag) {
            $category = $tag['category'] ?? '';
            $internalName = $tag['internal_name'] ?? '';

            if ($category === 'Type' && in_array($internalName, $skipTypes)) {
                return true;
            }
        }
    }

    return false;
}
```

Update `prepareImportPreview()` to extract and sync storage boxes:

```php
public function prepareImportPreview(User $user, string $tradeableJson, string $tradeLockedJson): ImportPreview
{
    $errors = [];
    $parsedItems = [];
    $unmatchedItems = [];
    $storageBoxesData = [];  // NEW

    // Parse both JSON inputs
    try {
        $tradeableData = json_decode($tradeableJson, true, 512, JSON_THROW_ON_ERROR);
        $parsedTradeableItems = $this->parseInventoryResponse($tradeableData);
        $parsedItems = array_merge($parsedItems, $parsedTradeableItems);

        // Extract storage boxes
        $storageBoxesData = array_merge(
            $storageBoxesData,
            $this->storageBoxService->extractStorageBoxesFromJson($tradeableData)
        );
    } catch (\JsonException $e) {
        $errors[] = 'Invalid JSON in tradeable items: ' . $e->getMessage();
    }

    try {
        $tradeLockedData = json_decode($tradeLockedJson, true, 512, JSON_THROW_ON_ERROR);
        $parsedTradeLockedItems = $this->parseInventoryResponse($tradeLockedData);
        $parsedItems = array_merge($parsedItems, $parsedTradeLockedItems);

        // Extract storage boxes
        $storageBoxesData = array_merge(
            $storageBoxesData,
            $this->storageBoxService->extractStorageBoxesFromJson($tradeLockedData)
        );
    } catch (\JsonException $e) {
        $errors[] = 'Invalid JSON in trade-locked items: ' . $e->getMessage();
    }

    // ... rest of existing code for parsing items ...

    // Store storage boxes data in session along with items
    $sessionKey = $this->storeInSession($mappedItems, $storageBoxesData);

    return new ImportPreview(
        totalItems: count($mappedItems),
        itemsToAdd: $stats['items_to_add'],
        itemsToRemove: $stats['items_to_remove'],
        statsByRarity: $stats['by_rarity'],
        statsByType: $stats['by_type'],
        notableItems: $stats['notable_items'],
        unmatchedItems: $unmatchedItems,
        errors: $errors,
        sessionKey: $sessionKey,
        storageBoxCount: count($storageBoxesData)  // NEW - add to DTO
    );
}
```

Update `executeImport()` to sync storage boxes and preserve items in storage:

```php
public function executeImport(User $user, string $sessionKey): ImportResult
{
    $sessionData = $this->retrieveFromSession($sessionKey);

    if ($sessionData === null) {
        return new ImportResult(/* ... */);
    }

    $mappedItems = $sessionData['items'];
    $storageBoxesData = $sessionData['storage_boxes'] ?? [];

    // ... existing transaction code ...

    try {
        // Sync storage boxes FIRST
        if (!empty($storageBoxesData)) {
            $this->storageBoxService->syncStorageBoxes($user, $storageBoxesData);
        }

        // IMPORTANT: Delete ONLY items in main inventory (storageBox IS NULL)
        // Items in storage containers (both Steam and manual) are preserved
        $qb = $this->entityManager->createQueryBuilder();
        $qb->delete(ItemUser::class, 'iu')
            ->where('iu.user = :user')
            ->andWhere('iu.storageBox IS NULL')  // Only delete items NOT in storage
            ->setParameter('user', $user);

        $deletedCount = $qb->getQuery()->execute();

        $this->logger->info('Deleted main inventory items during import', [
            'user_id' => $user->getId(),
            'deleted_count' => $deletedCount,
            'preserved_in_storage' => true
        ]);

        // ... rest of existing import logic (create new ItemUser entities) ...

        $this->entityManager->flush();
        $this->entityManager->commit();

        // Clear session data
        $this->clearFromSession($sessionKey);
    } catch (\Exception $e) {
        // ... existing error handling ...
    }

    return new ImportResult(/* ... */);
}
```

Update `storeInSession()` and `retrieveFromSession()`:

```php
private function storeInSession(array $mappedItems, array $storageBoxesData): string
{
    $session = $this->requestStack->getSession();
    $sessionKey = self::SESSION_PREFIX . bin2hex(random_bytes(16));

    $serializableData = [
        'items' => [],
        'storage_boxes' => $storageBoxesData
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

private function retrieveFromSession(string $sessionKey): ?array
{
    $session = $this->requestStack->getSession();
    $serializableData = $session->get($sessionKey);

    if ($serializableData === null) {
        return null;
    }

    // Reconstruct mapped items
    $mappedItems = [];
    foreach ($serializableData['items'] as $serializedItem) {
        $item = $this->itemRepository->find($serializedItem['item_id']);
        if ($item === null) {
            continue;
        }

        $mappedItems[] = [
            'item' => $item,
            'data' => $serializedItem['data'],
        ];
    }

    return [
        'items' => $mappedItems,
        'storage_boxes' => $serializableData['storage_boxes'] ?? []
    ];
}
```

### 3. Update ImportPreview DTO

**File**: `src/DTO/ImportPreview.php`

Add storage box count:

```php
readonly class ImportPreview
{
    public function __construct(
        public int $totalItems,
        public int $itemsToAdd,
        public int $itemsToRemove,
        public array $statsByRarity,
        public array $statsByType,
        public array $notableItems,
        public array $unmatchedItems,
        public array $errors,
        public string $sessionKey,
        public int $storageBoxCount = 0,  // NEW
    ) {}

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
```

### 4. Update Import Preview Template

**File**: `templates/inventory/import_preview.html.twig`

Add storage box count to the stats display:

```twig
<div class="stat-card">
    <div class="stat-label">Storage Boxes</div>
    <div class="stat-value">{{ preview.storageBoxCount }}</div>
</div>
```

## Testing

### Manual Testing

1. **Import with Storage Boxes**:
   - Use the existing inventory JSON files that contain storage boxes
   - Navigate to import page
   - Paste JSONs and preview
   - Verify storage box count is displayed in preview
   - Confirm import
   - Check database to verify storage boxes were created

2. **Verify Database**:
   ```sql
   SELECT * FROM storage_box;
   SELECT * FROM item_user WHERE storage_box_id IS NOT NULL;
   ```
   Should show your storage boxes with names, item counts, etc.

3. **Test Storage Box Parsing**:
   - Check logs for any parsing errors
   - Verify all storage boxes from JSON were imported
   - Verify item counts match what's in the JSON

4. **Test Multiple Imports**:
   - Import inventory twice
   - Verify storage boxes are updated (not duplicated)
   - Verify assetId matching works correctly

5. **Test Item Preservation During Import** (CRITICAL):
   - Manually assign some items to a storage box (set storage_box_id in database)
   - Or create a manual storage box and assign items to it
   - Run a full inventory import
   - Verify items in storage boxes are NOT deleted
   - Verify only main inventory items (storage_box_id = NULL) are replaced
   - Check logs to confirm deletion count excludes storage items

6. **Test Manual Storage Box Preservation**:
   - Create a manual storage box (assetId = NULL) via database or future UI
   - Assign some items to this manual box
   - Run inventory import
   - Verify manual box still exists unchanged
   - Verify items in manual box are still there

## Acceptance Criteria

### Import Functionality
- [ ] StorageBoxService created with all methods (including manual box support)
- [ ] Storage boxes are extracted from JSON during import
- [ ] Storage boxes are created in database during import
- [ ] Existing Steam storage boxes are updated (not duplicated) on re-import
- [ ] Import preview shows storage box count
- [ ] Storage box names are parsed correctly from name tags
- [ ] Storage box item counts are parsed correctly
- [ ] Storage box modification dates are parsed correctly
- [ ] Regular items (non-storage-boxes) are still imported correctly
- [ ] No errors in logs during import
- [ ] Multiple storage boxes per user are supported

### Item Preservation (CRITICAL)
- [ ] Items in ANY storage box (storageBox IS NOT NULL) are NEVER deleted during import
- [ ] Only items in main inventory (storageBox IS NULL) are deleted/replaced during import
- [ ] Import deletion query includes `WHERE storageBox IS NULL` condition
- [ ] Import logs show count of preserved items in storage
- [ ] Items in Steam storage boxes are preserved
- [ ] Items in manual storage boxes are preserved
- [ ] After import, storage items remain organized in their boxes

### Manual Storage Box Support
- [x] StorageBox entity supports nullable assetId (for manual boxes)
- [x] Database asset_id column is nullable
- [ ] Manual storage boxes (assetId = null) are NEVER modified by imports
- [ ] Only Steam boxes (assetId != null) are updated during imports
- [x] Repository methods exist to differentiate manual vs Steam boxes
- [x] StorageBox entity has isManualBox() and isSteamBox() helper methods
- [ ] Manual boxes can be created via createManualBox() method
- [ ] Manual boxes can be renamed and deleted independently

## Dependencies

- Task 2-1: Storage Box Database Setup (required)

## Next Tasks

**Task 2-3**: Storage Box Display - Update inventory page to show storage boxes and items in storage.

**Task 2-4** (Future): Manual Storage Box Management UI
- Create UI for creating manual storage boxes (for friend lending tracking)
- Add ability to assign items to manual boxes
- Add ability to move items between boxes
- Add ability to rename/delete manual boxes
- Show separate sections for Steam boxes vs manual boxes

## Related Files

- `src/Entity/StorageBox.php` (modified - added isManualBox/isSteamBox methods)
- `src/Repository/StorageBoxRepository.php` (modified - added findManualBoxes/findSteamBoxes)
- `src/Service/StorageBoxService.php` (new)
- `src/Service/InventoryImportService.php` (modified)
- `src/DTO/ImportPreview.php` (modified)
- `templates/inventory/import_preview.html.twig` (modified)
- `migrations/Version20251103024338.php` (new - makes assetId nullable)