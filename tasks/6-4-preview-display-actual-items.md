# Update Import Preview to Display Actual Items

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-03
**Part of**: Selective Inventory Import (4/6)
**Depends on**: Task 1001 (Item Card Component), Task 1003 (Comparison Logic)

## Overview

Update the import preview page to display actual items in "Items to Add" and "Items to Remove" sections using the item card component, instead of showing aggregate statistics (by rarity, by type, notable items).

## Problem Statement

Currently, `templates/inventory/import_preview.html.twig` shows:
- Summary statistics (count cards - keep these)
- Items by Rarity section (remove)
- Items by Type section (remove)
- Notable Items section (remove)

**Desired behavior:**
- Keep summary statistics at top
- Add "Items to Add" section showing grid of actual items
- Add "Items to Remove" section showing grid of actual items
- Use reusable item card component for display
- NO checkboxes yet (that's Task 1005)

## Requirements

### Functional Requirements

1. **Update ImportPreview DTO**
   - Add `itemsToAddData` property (array)
   - Add `itemsToRemoveData` property (array)
   - Remove `statsByRarity`, `statsByType`, `notableItems` properties
   - Keep `itemsToAdd` and `itemsToRemove` count properties

2. **Update template structure**
   - Remove "Items by Rarity" section
   - Remove "Items by Type" section
   - Remove "Notable Items" section
   - Add "Items to Add" section with grid of item cards
   - Add "Items to Remove" section with grid of item cards

3. **Display items using component**
   - Use `components/item_card.html.twig` embed
   - Show "NEW" badge for items to add (green)
   - Show "REMOVE" badge for items to remove (red)
   - Display all item properties (price, float, stickers, etc.)

4. **Handle empty states**
   - If no items to add: show "No new items to add"
   - If no items to remove: show "No items to remove"
   - If both empty: show "No changes detected"

### Non-Functional Requirements

- **Performance**: Load preview page within 2 seconds for 500+ items
- **Responsive**: Grid adjusts for mobile, tablet, desktop
- **UX**: Clear visual distinction between items to add vs remove

## Technical Approach

### 1. Update ImportPreview DTO

**File**: `src/DTO/ImportPreview.php`

```php
public function __construct(
    public readonly int $totalItems,
    public readonly int $itemsToAdd,
    public readonly int $itemsToRemove,
    public readonly array $itemsToAddData,  // NEW
    public readonly array $itemsToRemoveData,  // NEW
    public readonly array $unmatchedItems,
    public readonly array $errors,
    public readonly string $sessionKey,
    public readonly int $storageBoxCount = 0,
    // REMOVED: statsByRarity, statsByType, notableItems
) {
}
```

Update `toArray()` method:
```php
public function toArray(): array
{
    return [
        'total_items' => $this->totalItems,
        'items_to_add' => $this->itemsToAdd,
        'items_to_remove' => $this->itemsToRemove,
        'items_to_add_data' => $this->itemsToAddData,
        'items_to_remove_data' => $this->itemsToRemoveData,
        'unmatched_items' => $this->unmatchedItems,
        'errors' => $this->errors,
        'session_key' => $this->sessionKey,
        'storage_box_count' => $this->storageBoxCount,
    ];
}
```

### 2. Update InventoryImportService

**File**: `src/Service/InventoryImportService.php`

In `prepareImportPreview()`, update the return statement:

```php
return new ImportPreview(
    totalItems: count($mappedItems),
    itemsToAdd: count($itemsToAddData),
    itemsToRemove: count($itemsToRemoveData),
    itemsToAddData: $itemsToAddData,  // from Task 1003
    itemsToRemoveData: $itemsToRemoveData,  // from Task 1003
    unmatchedItems: $unmatchedItems,
    errors: $errors,
    sessionKey: $sessionKey,
    storageBoxCount: count($storageBoxesData),
);
```

Remove the `generatePreviewStats()` method entirely (no longer needed).

### 3. Update Import Preview Template

**File**: `templates/inventory/import_preview.html.twig`

**Keep these sections:**
- Page header (lines 8-14)
- Unmatched items warning (lines 16-42)
- Summary statistics cards (lines 44-70)
- Action buttons at bottom (lines 145-185)

**Remove these sections:**
- Items by Rarity (lines 73-94)
- Items by Type (lines 96-117)
- Notable Items (lines 119-143)

**Add these new sections:**

```twig
<!-- Items to Add Section -->
{% if preview.itemsToAddData is not empty %}
    <div class="mb-8">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-2xl font-bold text-white">Items to Add</h2>
            <p class="text-gray-400">{{ preview.itemsToAdd }} new items</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            {% for itemData in preview.itemsToAddData %}
                {% embed 'components/item_card.html.twig' with {
                    itemUser: itemData.itemUser,
                    item: itemData.item,
                    price: itemData.price,
                    stickersWithPrices: itemData.stickers,
                    keychainWithPrice: itemData.keychain,
                    mode: 'display'
                } %}
                    {% block badges %}
                        <span class="absolute top-2 left-2 bg-green-600 text-white px-2 py-1 rounded text-xs font-bold shadow-lg">
                            NEW
                        </span>
                    {% endblock %}
                {% endembed %}
            {% endfor %}
        </div>
    </div>
{% else %}
    <div class="card mb-8 bg-gray-800 border-gray-700">
        <p class="text-center text-gray-400">No new items to add</p>
    </div>
{% endif %}

<!-- Items to Remove Section -->
{% if preview.itemsToRemoveData is not empty %}
    <div class="mb-8">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-2xl font-bold text-white">Items to Remove</h2>
            <p class="text-gray-400">{{ preview.itemsToRemove }} items will be removed</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            {% for itemData in preview.itemsToRemoveData %}
                {% embed 'components/item_card.html.twig' with {
                    itemUser: itemData.itemUser,
                    item: itemData.item,
                    price: itemData.price,
                    stickersWithPrices: itemData.stickers,
                    keychainWithPrice: itemData.keychain,
                    mode: 'display'
                } %}
                    {% block badges %}
                        <span class="absolute top-2 left-2 bg-red-600 text-white px-2 py-1 rounded text-xs font-bold shadow-lg">
                            REMOVE
                        </span>
                    {% endblock %}
                {% endembed %}
            {% endfor %}
        </div>
    </div>
{% else %}
    <div class="card mb-8 bg-gray-800 border-gray-700">
        <p class="text-center text-gray-400">No items to remove</p>
    </div>
{% endif %}

<!-- No Changes Detected -->
{% if preview.itemsToAddData is empty and preview.itemsToRemoveData is empty %}
    <div class="card mb-8 bg-blue-900 border-blue-700">
        <div class="text-center">
            <svg class="mx-auto h-12 w-12 text-blue-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <h3 class="text-xl font-semibold text-blue-100 mb-2">No Changes Detected</h3>
            <p class="text-blue-200">Your inventory is already up to date with this import.</p>
        </div>
    </div>
{% endif %}
```

## Implementation Steps

1. **Update ImportPreview DTO** (30 minutes)
   - Add new properties
   - Remove old properties
   - Update constructor
   - Update toArray() method
   - Update PHPDoc

2. **Update InventoryImportService** (30 minutes)
   - Update prepareImportPreview() return statement
   - Remove generatePreviewStats() method
   - Ensure itemsToAddData and itemsToRemoveData are passed

3. **Update import preview template** (2 hours)
   - Remove old sections (rarity, type, notable items)
   - Add "Items to Add" section with grid
   - Add "Items to Remove" section with grid
   - Add empty state messages
   - Add "No changes" message
   - Use item card component with custom badges

4. **Test with real data** (1 hour)
   - Test with import that has new items
   - Test with import that removes items
   - Test with import that has both
   - Test with no changes
   - Test with 100+ items (performance)
   - Test responsive design on mobile

5. **Visual polish** (30 minutes)
   - Adjust spacing and layout
   - Ensure badges are clearly visible
   - Check color contrast
   - Verify hover effects work

## Edge Cases & Error Handling

### Edge Case 1: No Changes
**Scenario**: Import JSON is identical to current inventory.

**Handling**:
- Show "No Changes Detected" message with icon
- Hide "Items to Add" and "Items to Remove" sections
- User can click "Cancel Import" to return

### Edge Case 2: Only Additions
**Scenario**: All items are new, nothing to remove.

**Handling**:
- Show "Items to Add" section with items
- Show "No items to remove" message for remove section
- This is valid scenario (first import or adding to inventory)

### Edge Case 3: Only Removals
**Scenario**: No new items, only items to remove.

**Handling**:
- Show "No new items to add" message
- Show "Items to Remove" section with items
- User might be clearing inventory - allow this

### Edge Case 4: Large Number of Items
**Scenario**: 500+ items to add or remove.

**Handling**:
- Grid should handle this gracefully
- May be slow to render - consider adding loading indicator
- Future enhancement: pagination or lazy loading

## Acceptance Criteria

- [ ] ImportPreview DTO updated with new properties
- [ ] Old properties removed from DTO (statsByRarity, statsByType, notableItems)
- [ ] InventoryImportService returns updated DTO
- [ ] generatePreviewStats() method removed
- [ ] Import preview template shows "Items to Add" section
- [ ] Import preview template shows "Items to Remove" section
- [ ] Old sections removed (by rarity, by type, notable items)
- [ ] Items displayed using item card component
- [ ] "NEW" badge shows on items to add (green)
- [ ] "REMOVE" badge shows on items to remove (red)
- [ ] All item properties display correctly (name, price, float, stickers, keychains)
- [ ] Empty state shows "No new items to add" when appropriate
- [ ] Empty state shows "No items to remove" when appropriate
- [ ] "No Changes Detected" message shows when both lists are empty
- [ ] Grid is responsive on mobile, tablet, desktop
- [ ] Preview loads within 2 seconds for 100+ items
- [ ] Summary statistics cards at top still work
- [ ] Action buttons at bottom still work
- [ ] No visual regressions in other parts of page

## Notes & Considerations

### Grid Responsiveness

- **Mobile (< 640px)**: 1 column
- **Tablet (640px - 1024px)**: 2-4 columns
- **Desktop (> 1024px)**: 6 columns

Tailwind classes: `grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6`

### Visual Distinction

Make it very clear which items are being added vs removed:
- **Add**: Green "NEW" badge, maybe green border on hover
- **Remove**: Red "REMOVE" badge, maybe red border on hover

### Performance Consideration

If preview page becomes slow with large inventories:
1. Add loading spinner while rendering
2. Consider pagination (show 50 items per page)
3. Consider lazy loading (load as user scrolls)

These are future enhancements, not required for this task.

## Dependencies

- **Task 1001**: Reusable item card component must exist
- **Task 1003**: Comparison logic must be implemented

## Next Tasks

After this task is complete:
- **Task 1005**: Add checkbox selection and bulk controls

## Related Files

- `src/DTO/ImportPreview.php`
- `src/Service/InventoryImportService.php`
- `templates/inventory/import_preview.html.twig`
- `templates/components/item_card.html.twig`
