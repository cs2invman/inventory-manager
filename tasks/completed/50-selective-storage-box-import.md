# Selective Storage Box Import

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Medium
**Created**: 2025-11-10

## Overview

Add checkbox-based selection for storage boxes in the import preview, allowing users to choose which storage boxes to import. This prevents unwanted import of storage boxes when importing items from someone else's inventory (e.g., when importing just one item from a friend).

## Problem Statement

Currently, when importing an inventory:
- Users can check/uncheck individual items to control what gets added/removed
- Storage boxes are **always imported automatically** (line 238-241 in `InventoryImportService.php`)
- When importing items from someone else (e.g., a friend's single item), their storage boxes are imported too
- This clutters the user's storage box list with boxes they don't own

This task adds the same selective import control for storage boxes that already exists for items.

## Requirements

### Functional Requirements
- Display storage boxes in the import preview with checkboxes (individual selection)
- Show storage box metadata: name, item count (from Steam JSON)
- Default all storage boxes to **unchecked** (opt-in, unlike items which default to checked)
- Include storage box selection controls: "Select All Storage Boxes", "Deselect All Storage Boxes"
- Update the summary statistics to show selected vs total storage boxes
- Only import storage boxes that are checked by the user
- Storage boxes that are not selected should be completely ignored (not synced)

### Non-Functional Requirements
- Maintain consistency with existing item selection UI/UX
- Preserve existing session data structure (add storage box selection)
- No database changes required (only service and frontend)
- Must work with existing checkbox selection JavaScript (`public/js/import-preview.js`)

## Technical Approach

### Session Data Changes
In `InventoryImportService::storeInSession()` (line 729):
- Already stores `storage_boxes` array with extracted box data
- No changes needed to storage structure

### Service Layer Changes
**File**: `src/Service/InventoryImportService.php`

1. **`executeImport()` method** (line 205):
   - Add new parameter: `array $selectedStorageBoxIds = []`
   - Filter `$storageBoxesData` to only include selected boxes before syncing
   - Only call `syncStorageBoxes()` with filtered array

2. **Storage Box ID Format**:
   - Use `storageBox-{assetId}` as the checkbox ID format (consistent with `add-{assetId}` and `remove-{assetId}`)
   - Extract assetIds from selected IDs in controller

### Controller Changes
**File**: `src/Controller/InventoryImportController.php`

1. **`confirm()` method** (line 96):
   - Extract storage box IDs from `$selectedItems` (look for `storageBox-` prefix)
   - Pass `$selectedStorageBoxIds` to `executeImport()`

### Frontend Changes
**File**: `templates/inventory/import_preview.html.twig`

1. **Add new section** (after line 84, before "Items to Add"):
   ```twig
   <!-- Storage Boxes Section -->
   {% if preview.storageBoxCount > 0 %}
   <div class="mb-8">
       <div class="flex items-center justify-between mb-4 flex-wrap gap-4">
           <h2 class="text-2xl font-bold text-white">Storage Boxes</h2>
           <div class="flex items-center gap-2 flex-wrap">
               <button type="button" id="select-all-storage-boxes" class="px-3 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded transition-colors text-sm">
                   Select All
               </button>
               <button type="button" id="deselect-all-storage-boxes" class="px-3 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded transition-colors text-sm">
                   Deselect All
               </button>
           </div>
       </div>

       <div id="storage-boxes-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
           {% for boxData in storageBoxesData %}
           <div class="card relative storage-box-item" data-storage-box-id="storageBox-{{ boxData.asset_id }}">
               <label class="cursor-pointer">
                   <input type="checkbox"
                          class="storage-box-checkbox absolute top-3 right-3 w-5 h-5 rounded"
                          data-item-id="storageBox-{{ boxData.asset_id }}"
                          value="storageBox-{{ boxData.asset_id }}">

                   <div class="flex items-center space-x-4">
                       <!-- Storage box icon -->
                       <div class="flex-shrink-0">
                           <svg class="w-12 h-12 text-cs2-orange" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                               <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                           </svg>
                       </div>

                       <!-- Box info -->
                       <div class="flex-1 min-w-0">
                           <p class="text-sm font-medium text-white truncate">{{ boxData.name }}</p>
                           <p class="text-xs text-gray-400">{{ boxData.item_count }} items</p>
                           {% if boxData.modification_date %}
                           <p class="text-xs text-gray-500">Modified: {{ boxData.modification_date|date('M d, Y') }}</p>
                           {% endif %}
                       </div>
                   </div>
               </label>
           </div>
           {% endfor %}
       </div>
   </div>
   {% endif %}
   ```

2. **Update Summary Statistics** (line 78-83):
   - Change the static "Storage Boxes" stat to show: "X / Y Storage Boxes" (selected / total)
   - Add `id="storage-boxes-selected-count"` and `id="storage-boxes-total-count"` for JavaScript updates

3. **Update "Confirm Import" button** (line 255):
   - JavaScript must include storage box checkboxes in total count

**File**: `public/js/import-preview.js` (needs to be checked/created)

1. **Add storage box checkbox handling**:
   - Wire up "Select All Storage Boxes" button
   - Wire up "Deselect All Storage Boxes" button
   - Update counters when storage box checkboxes change
   - Include storage box selections in form submission

2. **Update `updateSelectedCount()` function**:
   - Count checked storage box checkboxes
   - Update storage box selected count display

3. **Update form submission**:
   - Collect all checked storage box checkbox values
   - Add them to the `selected_items[]` array in the form

### DTO Changes
**File**: `src/DTO/ImportPreview.php`

Check if `storageBoxesData` is exposed. If not, add:
```php
public function __construct(
    // ... existing parameters ...
    public readonly array $storageBoxesData = [], // Add this if missing
) {}
```

Update `prepareImportPreview()` to pass `storageBoxesData` to the DTO.

## Implementation Steps

1. **Update DTO (if needed)**:
   - Read `src/DTO/ImportPreview.php`
   - Add `storageBoxesData` property if not present
   - Pass `$storageBoxesData` to DTO in `InventoryImportService::prepareImportPreview()`

2. **Update Service Layer**:
   - Modify `InventoryImportService::executeImport()`:
     - Add `array $selectedStorageBoxIds = []` parameter
     - Extract assetIds from selected IDs (remove `storageBox-` prefix)
     - Filter `$storageBoxesData` to only include selected boxes
     - Only call `syncStorageBoxes()` if filtered array is not empty

3. **Update Controller**:
   - Modify `InventoryImportController::confirm()`:
     - Parse `$selectedItems` to extract storage box IDs (`storageBox-*` prefix)
     - Create `$selectedStorageBoxIds` array
     - Pass to `executeImport()` method

4. **Update Frontend Template**:
   - Add new "Storage Boxes" section in `import_preview.html.twig` after summary statistics
   - Create storage box cards with checkboxes (default unchecked)
   - Add "Select All" / "Deselect All" buttons for storage boxes
   - Update summary statistics to show "X / Y Storage Boxes"

5. **Update JavaScript**:
   - Read/create `public/js/import-preview.js`
   - Add event listeners for storage box select/deselect buttons
   - Update `updateSelectedCount()` to include storage box counts
   - Include storage box checkbox values in form submission
   - Update total selected count in "Confirm Import" button

6. **Test Frontend Assets**:
   - Rebuild Tailwind assets: `docker compose run --rm node npm run build`

## Edge Cases & Error Handling

1. **No Storage Boxes in Import**:
   - Don't show the storage boxes section if `storageBoxCount == 0`
   - Handled by existing `{% if preview.storageBoxCount > 0 %}` check

2. **All Storage Boxes Deselected**:
   - `syncStorageBoxes()` should not be called
   - No error, just skip the sync step

3. **Missing Asset ID**:
   - Storage boxes without `asset_id` are already logged as warnings (line 150 in `StorageBoxService.php`)
   - These boxes won't have checkboxes (can't be synced anyway)

4. **Session Expiration**:
   - Already handled by existing session validation in `executeImport()`

5. **Duplicate Asset IDs**:
   - Storage boxes are deduplicated by assetId in the JSON extraction phase
   - No additional handling needed

6. **Manual Storage Boxes**:
   - Manual boxes (no assetId) are never touched by imports
   - Only Steam boxes (with assetId) can be imported

## Acceptance Criteria

- [ ] Storage boxes appear in import preview with checkboxes (individual selection)
- [ ] Storage boxes default to **unchecked** (opt-in import)
- [ ] "Select All Storage Boxes" button checks all storage box checkboxes
- [ ] "Deselect All Storage Boxes" button unchecks all storage box checkboxes
- [ ] Summary statistics show "X / Y Storage Boxes" (selected / total)
- [ ] "Confirm Import" button shows updated total count including storage boxes
- [ ] Only checked storage boxes are synced during import
- [ ] Unchecked storage boxes are completely ignored (not synced)
- [ ] Items can still be checked/unchecked independently (existing functionality preserved)
- [ ] Session data handling works correctly with storage box selections
- [ ] Import success message shows storage box import count (if any imported)
- [ ] Manual verification: Import someone else's inventory with unchecked storage boxes → boxes not imported
- [ ] Manual verification: Import with all storage boxes checked → all boxes imported as before

## Notes & Considerations

### Default Checkbox State
- **Items**: Default to checked (user's own inventory, usually wants everything)
- **Storage Boxes**: Default to **unchecked** (when importing others' inventories, boxes are rarely wanted)

### UI Consistency
- Storage box cards should match the style of item cards (dark theme, rounded corners, hover effects)
- Use same checkbox styling as item checkboxes
- Maintain consistent spacing and grid layout

### Performance Considerations
- Storage boxes are typically few (0-5 per user)
- No performance concerns with rendering individual checkboxes
- JavaScript checkbox handling is lightweight

### Future Improvements
- Could add "Show Contents" expandable view for each storage box (out of scope for this task)
- Could add bulk filters for storage boxes (e.g., "Empty Boxes", "Boxes with >50 items") - out of scope
- Could show a warning if importing storage boxes that already exist - out of scope

### Related Code References
- Item checkbox selection: `templates/inventory/import_preview.html.twig` lines 86-146, 148-208
- Storage box sync: `src/Service/StorageBoxService.php` lines 140-186
- Import session handling: `src/Service/InventoryImportService.php` lines 729-751, 756-784

## Dependencies

### Blocking Dependencies
None - this is a standalone feature enhancement

### Related Tasks
- Builds on existing checkbox selection UI from task 6-5 (checkbox-selection-controls)
- Related to storage box management (task 5-storage-box-management)

### Can Be Done in Parallel With
Any other frontend or service layer tasks that don't modify import preview

### External Dependencies
- Tailwind CSS (already configured)
- Alpine.js (already in use for dropdowns)
- JavaScript (ES6+)

## Related Tasks

This task is standalone and does not block or depend on other tasks.
