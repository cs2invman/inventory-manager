# Storage Box Management System (Overview)

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Large (Split into 5 subtasks)
**Created**: 2025-11-02

## Task Breakdown

This large task has been split into 5 sequential subtasks for better manageability:

1. **Task 2-1**: Storage Box Database Setup (Small)
   - Create StorageBox entity
   - Update ItemUser with relationship
   - Create migration and repository

2. **Task 2-2**: Storage Box Import Integration (Medium)
   - Create StorageBoxService
   - Update InventoryImportService to parse storage boxes
   - Sync storage boxes during import

3. **Task 2-3**: Storage Box Display (Medium)
   - Update inventory page to show storage boxes
   - Add filtering (All Items, Active Only, By Box)
   - Add storage indicator badges on items

4. **Task 2-4**: Deposit/Withdraw Workflow (Large)
   - Create StorageBoxTransactionService
   - Implement deposit/withdraw controllers and templates
   - Handle snapshot comparison and item matching

5. **Task 2-5**: Testing & Documentation (Small)
   - Comprehensive testing of all workflows
   - Update CLAUDE.md
   - Document known issues and future improvements

**Complete tasks in order. Each builds on the previous.**

## Overview

Implement a comprehensive storage box management system that allows users to track CS2 storage units and their contents. The system will use JSON snapshot comparison to detect deposits and withdrawals, storing storage box metadata and tracking which items are contained in each box.

## Problem Statement

CS2 allows players to store up to 1,000 items per storage unit, but there's no external API to view storage box contents directly. Users need a way to:

1. Track storage boxes as separate entities with metadata (name, item count, modification date)
2. Deposit items into storage boxes by comparing inventory snapshots before/after
3. Withdraw items from storage boxes using the same snapshot comparison method
4. View storage boxes in their inventory with item count indicators
5. Ensure inventory accuracy by validating storage box item counts

Currently, the import system skips storage boxes entirely (see `InventoryImportService.php:271-278`). We need to:
- Stop skipping storage boxes and instead store their metadata
- Create a workflow for managing items within storage boxes
- Track which items are in which storage box

## Requirements

### Functional Requirements

1. **Storage Box Entity Management**
   - Create and track storage box entities with metadata (name, asset_id, item_count, modification_date)
   - Display storage boxes in main inventory view with item count badges
   - Support multiple storage boxes per user (as seen in JSON: 4 storage boxes with different names)

6. **Inventory Display Requirements**
   - Show ALL items on main inventory page (both active inventory AND items in storage boxes)
   - Items in storage boxes must have visual indicator showing which box they're in
   - Support filtering by: All Items, Active Inventory Only, or by specific Storage Box
   - Each item card should display storage box name badge if item is in storage
   - Storage boxes themselves also appear as items with special styling

2. **Deposit Workflow**
   - User clicks "Deposit" button on a storage box in inventory
   - Upload JSON snapshot of inventory after depositing items
   - System compares new snapshot with current database state
   - Show diff: items removed from active inventory (deposited into box)
   - User confirms deposit
   - System moves items from active inventory to storage box

3. **Withdrawal Workflow**
   - User clicks "Withdraw" button on a storage box
   - Upload JSON snapshot of inventory after withdrawing items
   - System compares new snapshot with current database state
   - Show diff: items added to active inventory (withdrawn from box)
   - User confirms withdrawal
   - System moves items from storage box to active inventory

4. **Snapshot Comparison Logic**
   - Parse JSON to extract all items with their properties (assetId, market_hash_name, float, pattern, etc.)
   - Compare against current database state
   - Identify items that appeared (withdrawals) and disappeared (deposits)
   - Handle assetId changes by matching on market_hash_name + float + pattern
   - Display clear diff showing exactly what changed

5. **Storage Box Discovery During Import**
   - Parse storage box data from JSON descriptions
   - Extract: name (from name_tag), item count, modification date
   - Create/update StorageBox entities
   - Validate item counts: sum of items in each box should match box's reported count

### Non-Functional Requirements

- **Performance**: Snapshot comparison should complete within 2-3 seconds for inventories up to 2000 items
- **Data Integrity**: Use database transactions to ensure atomic deposit/withdrawal operations
- **Accuracy**: Validate storage box item counts match reported counts from Steam
- **Usability**: Clear visual diff showing deposits/withdrawals before confirmation

## Technical Approach

### Database Changes

#### New Entity: StorageBox

```php
#[ORM\Entity(repositoryClass: StorageBoxRepository::class)]
#[ORM\Table(name: 'storage_box')]
class StorageBox
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'storageBoxes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $assetId = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $itemCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $modificationDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    // Getters and setters...
}
```

#### Update ItemUser Entity

The `ItemUser` entity already has a `storageBoxName` field (line 45), but we'll change this to reference the StorageBox entity:

```php
// Replace storageBoxName with a ManyToOne relationship
#[ORM\ManyToOne(targetEntity: StorageBox::class)]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
private ?StorageBox $storageBox = null;
```

#### Migration

- Create `storage_box` table
- Migrate existing `storage_box_name` values (if any) to the new relationship
- Add foreign key from `item_user.storage_box_id` to `storage_box.id`
- Remove `storage_box_name` column

### Service Layer

#### StorageBoxService

Create `src/Service/StorageBoxService.php`:

**Responsibilities:**
- Parse storage box data from Steam JSON
- Create/update StorageBox entities
- Validate item counts
- Retrieve storage boxes for a user
- Get items contained in a specific storage box

**Key Methods:**
```php
public function extractStorageBoxesFromJson(array $jsonData): array
public function syncStorageBoxes(User $user, array $storageBoxData): void
public function getStorageBoxByAssetId(User $user, string $assetId): ?StorageBox
public function getItemsInStorageBox(StorageBox $box): array
public function validateItemCount(StorageBox $box): bool
```

#### StorageBoxTransactionService

Create `src/Service/StorageBoxTransactionService.php`:

**Responsibilities:**
- Prepare deposit/withdraw preview by comparing snapshots
- Execute deposit operations
- Execute withdraw operations
- Match items by assetId or by properties (market_hash_name + float + pattern)

**Key Methods:**
```php
public function prepareDepositPreview(User $user, StorageBox $box, string $jsonSnapshot): DepositPreview
public function prepareWithdrawPreview(User $user, StorageBox $box, string $jsonSnapshot): WithdrawPreview
public function executeDeposit(User $user, StorageBox $box, string $sessionKey): TransactionResult
public function executeWithdraw(User $user, StorageBox $box, string $sessionKey): TransactionResult
private function compareSnapshots(array $currentItems, array $newSnapshot): array
private function matchItemByProperties(array $itemData, array $potentialMatches): ?ItemUser
```

#### Update InventoryImportService

Modify `src/Service/InventoryImportService.php`:

1. **Remove storage box skip logic** (lines 271-278)
2. **Add storage box extraction**:
   - In `parseInventoryResponse()`, detect storage boxes
   - Extract storage box metadata
   - Return storage box data separately from regular items
3. **Update `prepareImportPreview()` to handle storage boxes**:
   - Parse storage boxes from both JSON files
   - Pass to StorageBoxService for sync
   - Include storage box count in preview stats
4. **Update `executeImport()` to sync storage boxes**:
   - Call StorageBoxService to create/update storage boxes
   - Validate item counts after import

### Controllers

#### StorageBoxController

Create `src/Controller/StorageBoxController.php`:

**Routes:**
```php
#[Route('/storage')]
class StorageBoxController extends AbstractController
{
    #[Route('/deposit/{id}', name: 'storage_box_deposit_form')]
    public function depositForm(StorageBox $storageBox): Response

    #[Route('/deposit/{id}/preview', name: 'storage_box_deposit_preview', methods: ['POST'])]
    public function depositPreview(StorageBox $storageBox, Request $request): Response

    #[Route('/deposit/{id}/confirm', name: 'storage_box_deposit_confirm', methods: ['POST'])]
    public function depositConfirm(StorageBox $storageBox, Request $request): Response

    #[Route('/withdraw/{id}', name: 'storage_box_withdraw_form')]
    public function withdrawForm(StorageBox $storageBox): Response

    #[Route('/withdraw/{id}/preview', name: 'storage_box_withdraw_preview', methods: ['POST'])]
    public function withdrawPreview(StorageBox $storageBox, Request $request): Response

    #[Route('/withdraw/{id}/confirm', name: 'storage_box_withdraw_confirm', methods: ['POST'])]
    public function withdrawConfirm(StorageBox $storageBox, Request $request): Response

    #[Route('/{id}/contents', name: 'storage_box_contents')]
    public function viewContents(StorageBox $storageBox): Response
}
```

### Frontend Changes

#### Inventory Page - Display ALL Items with Storage Indicators

**File: `templates/inventory/index.html.twig`** (or similar)

**Key Requirements:**
- Display ALL ItemUser records for the user (active inventory + items in storage boxes)
- Each regular item card should check if `$itemUser->getStorageBox()` is set
- If item is in storage, display a badge/tag with the storage box name
- Storage boxes themselves also appear as special item cards

**Implementation Details:**

1. **Query all items** (active + in storage):
   ```php
   // In controller
   $allItems = $itemUserRepository->findUserInventory($user->getId());
   $storageBoxes = $storageBoxRepository->findByUser($user);
   ```

2. **Filter Controls** (above inventory grid):
   - Dropdown: "All Items" (default) | "Active Inventory Only" | "SOUVENIRS Box" | "STICKER/CHARM/SPRAY Box" | etc.
   - When filtering by storage box, only show items where `storageBox->getId() === $selectedBoxId`
   - When filtering "Active Inventory Only", only show items where `storageBox === null`

3. **Item Card Display**:
   - Regular items: Show image, name, price, float, etc. (as currently implemented)
   - **NEW: Storage indicator badge**:
     ```twig
     {% if item.storageBox is not null %}
       <div class="storage-badge">
         <span class="badge badge-info">
           📦 {{ item.storageBox.name }}
         </span>
       </div>
     {% endif %}
     ```
   - Position badge in top-left or top-right corner of item card
   - Style with distinct color (e.g., blue badge, storage box icon)

4. **Storage Box Cards**:
   - Display storage boxes with special styling (distinct border color, larger size, or different layout)
   - Show storage box icon/image
   - Show item count badge: "73 items"
   - Add "Deposit" and "Withdraw" action buttons
   - Add "View Contents" link

#### Deposit Form

**File: `templates/storage_box/deposit.html.twig`**

- Display storage box name and current item count
- JSON textarea for pasting inventory snapshot
- "Preview Deposit" button
- Instructions for user: "Copy your inventory JSON after depositing items"

#### Deposit Preview

**File: `templates/storage_box/deposit_preview.html.twig`**

- Show storage box name
- Display diff:
  - **Items to Deposit** (items that disappeared from active inventory)
    - Show item name, image, float, pattern
    - Count of items being deposited
  - **New Item Count**: X → Y items
- "Confirm Deposit" button
- "Cancel" button

#### Withdraw Form & Preview

Similar structure to deposit form/preview, but with:
- "Preview Withdrawal" button
- **Items to Withdraw** (items that appeared in active inventory)

#### Storage Box Contents View

**File: `templates/storage_box/contents.html.twig`**

- Display storage box name
- List all items currently in the storage box
- Show total value of items in storage
- "Withdraw Items" button

#### Dashboard Updates

**File: `templates/dashboard/index.html.twig`**

- Include items in storage boxes in total inventory value
- Optionally add a "Storage Boxes" stat showing count and total items stored

### DTOs

Create new DTO classes:

#### DepositPreview

```php
namespace App\DTO;

readonly class DepositPreview
{
    public function __construct(
        public array $itemsToDeposit,      // Items that will move to storage
        public int $currentItemCount,      // Current count in storage box
        public int $newItemCount,          // New count after deposit
        public array $errors,              // Any errors encountered
        public string $sessionKey,         // Session key for confirmation
    ) {}
}
```

#### WithdrawPreview

```php
namespace App\DTO;

readonly class WithdrawPreview
{
    public function __construct(
        public array $itemsToWithdraw,     // Items that will move to inventory
        public int $currentItemCount,      // Current count in storage box
        public int $newItemCount,          // New count after withdrawal
        public array $errors,              // Any errors encountered
        public string $sessionKey,         // Session key for confirmation
    ) {}
}
```

#### TransactionResult

```php
namespace App\DTO;

readonly class TransactionResult
{
    public function __construct(
        public int $itemsMoved,            // Number of items moved
        public bool $success,              // Overall success status
        public array $errors,              // Any errors encountered
    ) {}
}
```

### Configuration

No Docker or environment variable changes required.

## Implementation Steps

### Phase 1: Database & Entity Setup

1. **Create StorageBox Entity**
   - Generate entity: `php bin/console make:entity StorageBox`
   - Add all fields as specified above
   - Add relationships to User
   - Add lifecycle callbacks for timestamps

2. **Update ItemUser Entity**
   - Remove `storageBoxName` string field
   - Add `ManyToOne` relationship to StorageBox
   - Update index from `storage_box_name` to `storage_box_id`

3. **Create Migration**
   - Generate migration: `php bin/console make:migration`
   - Review and test migration
   - Run migration: `php bin/console doctrine:migrations:migrate`

4. **Create Repository**
   - Create `StorageBoxRepository` with custom queries:
     - `findByUser(User $user): array`
     - `findByAssetId(User $user, string $assetId): ?StorageBox`
     - `findWithItemCount(User $user): array` (join count items)

### Phase 2: Storage Box Service Layer

5. **Create StorageBoxService**
   - Implement storage box parsing from JSON
   - Extract metadata: name (from nametag), assetId, item count, modification date
   - Implement sync logic to create/update storage boxes
   - Implement validation: compare reported item count vs actual items in DB

6. **Update InventoryImportService**
   - Modify `shouldSkipItem()` to NOT skip storage boxes
   - Add `extractStorageBoxData()` method to parse storage box metadata
   - Update `parseInventoryResponse()` to return storage boxes separately
   - Update `prepareImportPreview()` to call StorageBoxService.sync
   - Add storage box stats to preview
   - Update `executeImport()` to sync storage boxes

7. **Create StorageBoxTransactionService**
   - Implement `compareSnapshots()`: diff current DB state vs new JSON
   - Implement `matchItemByProperties()`: match items by hash_name + float + pattern
   - Implement `prepareDepositPreview()`: identify items that disappeared
   - Implement `prepareWithdrawPreview()`: identify items that appeared
   - Implement `executeDeposit()`:
     - For each item in deposit list: `$itemUser->setStorageBox($targetStorageBox)`
     - Persist changes in transaction
   - Implement `executeWithdraw()`:
     - For each item in withdraw list: `$itemUser->setStorageBox(null)`
     - Persist changes in transaction
   - Store preview data in session (similar to InventoryImportService)

### Phase 3: Controllers & Routes

8. **Create StorageBoxController**
   - Implement all routes as specified above
   - Add security checks: ensure user owns storage box
   - Handle JSON parsing errors gracefully
   - Use flash messages for user feedback
   - Implement session-based preview/confirm flow (same pattern as import)

### Phase 4: Frontend Templates

9. **Update Inventory Display**
   - Modify `templates/inventory/index.html.twig` (or wherever inventory is shown)
   - Add storage boxes to item grid
   - Style storage boxes with distinct appearance
   - Add item count badge
   - Add "Deposit" and "Withdraw" action buttons
   - Add "View Contents" link

10. **Create Deposit Templates**
    - `templates/storage_box/deposit.html.twig`: Form to upload JSON
    - `templates/storage_box/deposit_preview.html.twig`: Show diff before confirming
    - Include instructions for user workflow
    - Style with Tailwind CSS to match existing UI

11. **Create Withdraw Templates**
    - `templates/storage_box/withdraw.html.twig`: Form to upload JSON
    - `templates/storage_box/withdraw_preview.html.twig`: Show diff before confirming
    - Similar styling to deposit templates

12. **Create Storage Box Contents Template**
    - `templates/storage_box/contents.html.twig`: Display all items in box
    - Grid layout showing item cards
    - Show total value
    - Add "Withdraw" button

13. **Update Dashboard**
    - Include storage box items in total value calculation
    - Optionally add storage box statistics card

### Phase 5: Testing & Validation

14. **Manual Testing Scenarios**
    - Import inventory with storage boxes → verify boxes are created
    - Deposit items → verify diff shows correct items → confirm → verify items moved
    - Withdraw items → verify diff shows correct items → confirm → verify items moved
    - Test assetId change handling: modify JSON with same item but different assetId
    - Validate item counts: ensure storage box count matches actual items in DB
    - Test with multiple storage boxes
    - Test edge cases: empty storage box, depositing all items, withdrawing all items

15. **Error Handling Testing**
    - Invalid JSON format
    - Missing storage box in JSON
    - Item count mismatch
    - Session expiration during deposit/withdraw
    - Network interruption during confirmation

### Phase 6: Documentation & Cleanup

16. **Update CLAUDE.md**
    - Document storage box management workflow
    - Add example commands if any console commands are added
    - Update architecture section

17. **Code Review**
    - Ensure all methods have proper type hints
    - Add PHPDoc comments to public methods
    - Review error handling and logging
    - Ensure consistent code style

## Testing Strategy

### Unit Tests

- **StorageBoxService**
  - Test storage box extraction from JSON
  - Test storage box sync logic (create new, update existing)
  - Test item count validation

- **StorageBoxTransactionService**
  - Test snapshot comparison logic
  - Test item matching by properties
  - Test deposit preview generation
  - Test withdraw preview generation

### Integration Tests

- **Full Deposit Workflow**
  - Upload JSON → preview → confirm → verify database state

- **Full Withdraw Workflow**
  - Upload JSON → preview → confirm → verify database state

- **Import with Storage Boxes**
  - Import inventory containing storage boxes
  - Verify storage boxes are created
  - Verify item counts are correct

### Manual Testing

- **User Workflow Testing**
  - Follow complete user journey: import → deposit → view contents → withdraw
  - Test with real Steam JSON data
  - Verify UI displays correctly
  - Ensure error messages are helpful

- **Data Integrity**
  - Verify no items are lost during deposit/withdraw
  - Verify item counts always match
  - Verify assetIds are updated correctly when changed

## Edge Cases & Error Handling

### Edge Cases

1. **Empty Storage Box**
   - Handle storage boxes with 0 items
   - Allow deposits into empty boxes
   - Display appropriate message in contents view

2. **Deposit All Items**
   - User deposits all items from active inventory
   - Ensure active inventory shows as empty (except storage boxes)

3. **Withdraw All Items**
   - User withdraws all items from a storage box
   - Storage box item count should be 0
   - Contents view should show empty state

4. **AssetId Changes**
   - Steam sometimes changes assetIds
   - System should match by market_hash_name + float + pattern
   - Update assetId in database when match is found
   - Log when assetId changes are detected

5. **Multiple Storage Boxes**
   - User has 4+ storage boxes with different names
   - Ensure each box is tracked independently
   - Prevent items from being duplicated across boxes

6. **Duplicate Items in JSON**
   - Same assetId appears twice in JSON (shouldn't happen, but handle it)
   - Log warning and use first occurrence

7. **Item Count Mismatch**
   - Storage box reports 781 items, but system counts 780
   - Display warning to user
   - Suggest re-importing inventory

### Error Handling

- **Invalid JSON**: Display clear error message with example format
- **Missing Storage Box**: If assetId not found, return 404
- **Session Expired**: Redirect back to form with message
- **Database Transaction Failure**: Roll back changes, log error, display user-friendly message
- **Item Not Found**: If item to deposit/withdraw doesn't exist, skip and add to error list
- **Concurrent Modification**: If storage box modified during transaction, abort and ask user to retry

### Logging

- Log all storage box operations (create, update, deposit, withdraw)
- Log assetId changes when matching by properties
- Log item count mismatches
- Log any errors during snapshot comparison

## Dependencies

- **Doctrine ORM**: For StorageBox entity and relationships
- **Symfony Messenger**: Not needed (no async operations)
- **Existing Services**: `InventoryImportService`, `ItemRepository`, `ItemUserRepository`

## Acceptance Criteria

- [ ] StorageBox entity created with all required fields
- [ ] ItemUser entity has `storageBox` ManyToOne relationship (replacing `storageBoxName`)
- [ ] Database migration created and runs successfully
- [ ] When items are deposited, `ItemUser.storageBox` is set to the target StorageBox
- [ ] When items are withdrawn, `ItemUser.storageBox` is set to null
- [ ] Inventory page query retrieves ALL ItemUser records (with and without storageBox set)
- [ ] Storage boxes are parsed from inventory JSON during import
- [ ] Storage boxes are created/updated in database during import
- [ ] Storage boxes display in inventory view with item count badge
- [ ] Deposit form allows uploading JSON snapshot
- [ ] Deposit preview shows accurate diff of items to deposit
- [ ] Deposit confirmation moves items to storage box atomically
- [ ] Withdraw form allows uploading JSON snapshot
- [ ] Withdraw preview shows accurate diff of items to withdraw
- [ ] Withdraw confirmation moves items to active inventory atomically
- [ ] AssetId changes are handled by matching on properties
- [ ] Storage box contents page displays all items in a box
- [ ] Dashboard includes storage box items in total value
- [ ] Item count validation warns when counts don't match
- [ ] Error handling provides clear feedback to user
- [ ] Manual testing confirms all workflows function correctly
- [ ] No items are lost or duplicated during operations
- [ ] System performs well with large inventories (2000+ items)

## Notes & Considerations

### Storage Box Data in JSON

From the inventory JSON, storage boxes have this structure:

```json
{
  "name": "Storage Unit",
  "type": "Base Grade Tool",
  "market_hash_name": "Storage Unit",
  "descriptions": [
    {
      "value": "Name Tag: ''SOUVENIRS''",
      "name": "nametag"
    },
    {
      "value": "Number of Items: 73",
      "name": "attr: items count"
    },
    {
      "value": "Modification Date: Sep 11, 2025 (22:25:42) GMT",
      "name": "attr: modification date"
    }
  ],
  "tags": [
    {
      "internal_name": "CSGO_Type_Tool",
      "localized_tag_name": "Tool"
    }
  ]
}
```

**Key extraction points:**
- Name: Parse from `descriptions` where `name === "nametag"`
- Item Count: Parse from `descriptions` where `name === "attr: items count"` → extract integer
- Modification Date: Parse from `descriptions` where `name === "attr: modification date"` → convert to DateTime
- AssetId: From `assets` array matching this classid/instanceid

### Performance Considerations

- Snapshot comparison with 2000 items should be optimized:
  - Index items by assetId for O(1) lookups
  - Use batch queries to load current inventory items
  - Avoid N+1 queries when comparing items

### Future Improvements

- **Bulk Operations**: Deposit/withdraw multiple boxes at once
- **Search within Storage**: Search for specific items across all storage boxes
- **Storage Analytics**: Show which boxes contain most valuable items
- **Auto-organize**: Suggest which items to store based on rarity/value
- **Storage Box Naming**: Allow renaming storage boxes within the app (independent of Steam name)

### Known Limitations

- **No Real-time Sync**: Users must manually upload JSON to update storage box states
- **No Content Listing from Steam**: Cannot view storage box contents directly from Steam API (this is a Steam limitation)
- **Requires Manual Workflow**: Users must perform deposit/withdraw in-game, then upload JSON snapshot
