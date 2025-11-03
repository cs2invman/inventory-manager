# Add Checkbox Selection and Bulk Controls to Import Preview

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-03
**Part of**: Selective Inventory Import (5/6)
**Depends on**: Task 1004 (Preview Display Actual Items)

## Overview

Add checkbox selection to each item in the import preview, along with bulk selection controls (select all, deselect all, filter by category, search). Update summary statistics dynamically as user toggles checkboxes.

## Problem Statement

Currently, the import preview shows all items to add/remove, but users cannot select which specific items to include in the import. All items are imported.

**Desired behavior:**
- Each item card has a checkbox (default: checked)
- Users can individually check/uncheck items
- Bulk controls: "Select All", "Deselect All"
- Filter by rarity, type, price range
- Search by item name
- Summary stats update in real-time as selections change

## Requirements

### Functional Requirements

1. **Add checkboxes to item cards**
   - Checkbox in top-right corner of each card
   - Default: all checked
   - Clear visual indication when checked/unchecked

2. **Bulk selection controls for "Items to Add"**
   - "Select All" button
   - "Deselect All" button
   - Dropdown with filter options:
     - By rarity (Covert, Classified, etc.)
     - By type (Knife, Rifle, Pistol, etc.)
     - By price (> $10, > $100)
   - Search text box to filter by item name

3. **Bulk selection controls for "Items to Remove"**
   - Same controls as "Items to Add" section

4. **Dynamic count updates**
   - Update "Items to Add" count as checkboxes change
   - Update "Items to Remove" count as checkboxes change
   - Update in real-time (no page reload)

5. **Form submission**
   - On "Confirm Import", collect all checked item IDs
   - Submit as hidden form inputs
   - Pass to controller for processing

### Non-Functional Requirements

- **Performance**: Checkbox toggling feels instant, no lag
- **UX**: Clear visual feedback for selected/unselected items
- **Accessibility**: Checkboxes properly labeled for screen readers
- **Mobile-friendly**: Touch-friendly checkbox size, controls work on mobile

## Technical Approach

### 1. Update Item Card Component for Checkbox Mode

**File**: `templates/components/item_card.html.twig`

The component already supports `mode: 'with-checkbox'` from Task 1001. Ensure it renders:
- Checkbox in top-right corner
- Checkbox has unique ID based on `itemId` parameter
- Checkbox name is `selected_items[]`
- Checkbox value is the `itemId`

### 2. Update Import Preview Template

**File**: `templates/inventory/import_preview.html.twig`

**A. Update summary stat cards with IDs for JavaScript:**

```twig
<div class="card">
    <p class="text-sm text-gray-400">Items to Add</p>
    <p class="text-4xl font-bold text-green-400" id="items-to-add-count">{{ preview.itemsToAdd }}</p>
</div>
<div class="card">
    <p class="text-sm text-gray-400">Items to Remove</p>
    <p class="text-4xl font-bold text-red-400" id="items-to-remove-count">{{ preview.itemsToRemove }}</p>
</div>
```

**B. Add bulk controls above "Items to Add" grid:**

```twig
<div class="flex items-center justify-between mb-4">
    <h2 class="text-2xl font-bold text-white">Items to Add</h2>
    <div class="flex items-center gap-2 flex-wrap">
        <button type="button" id="select-all-add" class="px-3 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded transition-colors text-sm">
            Select All
        </button>
        <button type="button" id="deselect-all-add" class="px-3 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded transition-colors text-sm">
            Deselect All
        </button>

        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="px-3 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded transition-colors text-sm">
                Bulk Select ▾
            </button>
            <div x-show="open" @click.outside="open = false" class="absolute right-0 mt-2 w-48 bg-gray-800 rounded shadow-lg z-10 border border-gray-700">
                <button type="button" data-section="add" data-filter="rarity:Covert" class="block w-full text-left px-4 py-2 hover:bg-gray-700 text-white text-sm">Covert items</button>
                <button type="button" data-section="add" data-filter="rarity:Classified" class="block w-full text-left px-4 py-2 hover:bg-gray-700 text-white text-sm">Classified items</button>
                <button type="button" data-section="add" data-filter="type:Knife" class="block w-full text-left px-4 py-2 hover:bg-gray-700 text-white text-sm">Knives</button>
                <button type="button" data-section="add" data-filter="type:Gloves" class="block w-full text-left px-4 py-2 hover:bg-gray-700 text-white text-sm">Gloves</button>
                <button type="button" data-section="add" data-filter="price:>10" class="block w-full text-left px-4 py-2 hover:bg-gray-700 text-white text-sm">Items > $10</button>
                <button type="button" data-section="add" data-filter="price:>100" class="block w-full text-left px-4 py-2 hover:bg-gray-700 text-white text-sm">Items > $100</button>
            </div>
        </div>

        <input type="text" id="search-add" placeholder="Search items..." class="px-3 py-2 bg-gray-700 text-white rounded text-sm w-48 focus:outline-none focus:ring-2 focus:ring-cs2-orange">
    </div>
</div>
```

**C. Update item cards to use checkbox mode:**

```twig
{% for itemData in preview.itemsToAddData %}
    {% embed 'components/item_card.html.twig' with {
        itemUser: itemData.itemUser,
        item: itemData.item,
        price: itemData.price,
        stickersWithPrices: itemData.stickers,
        keychainWithPrice: itemData.keychain,
        mode: 'with-checkbox',
        checked: true,
        itemId: 'add-' ~ itemData.assetId,
        rarity: itemData.item.rarity,
        type: itemData.item.type,
        priceValue: itemData.priceValue
    } %}
        {% block badges %}
            <span class="absolute top-2 left-2 bg-green-600 text-white px-2 py-1 rounded text-xs font-bold shadow-lg z-10">
                NEW
            </span>
        {% endblock %}
    {% endembed %}
{% endfor %}
```

**D. Add similar controls for "Items to Remove" section**

**E. Update form to include hidden input container:**

```twig
<form action="{{ path('inventory_import_confirm') }}" method="post" id="import-form">
    <input type="hidden" name="session_key" value="{{ preview.sessionKey }}">

    <!-- Hidden inputs populated by JavaScript on submit -->
    <div id="selected-items-container"></div>

    <div class="flex justify-center gap-4">
        <button type="button" onclick="window.location.href='{{ path('inventory_import_cancel') }}'" class="bg-gray-700 hover:bg-gray-600 text-white font-semibold py-3 px-8 rounded-lg transition-colors">
            Cancel
        </button>
        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-8 rounded-lg transition-colors">
            Confirm Import (<span id="total-selected-count">{{ preview.itemsToAdd + preview.itemsToRemove }}</span> items)
        </button>
    </div>
</form>
```

### 3. Create JavaScript for Checkbox Management

**File**: `assets/js/import-preview.js`

```javascript
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('import-form');
    const selectedItemsContainer = document.getElementById('selected-items-container');

    // Update counts when checkboxes change
    function updateCounts() {
        const addChecked = document.querySelectorAll('#items-to-add-grid input[type="checkbox"]:checked').length;
        const removeChecked = document.querySelectorAll('#items-to-remove-grid input[type="checkbox"]:checked').length;
        const totalSelected = addChecked + removeChecked;

        document.getElementById('items-to-add-count').textContent = addChecked;
        document.getElementById('items-to-remove-count').textContent = removeChecked;
        document.getElementById('total-selected-count').textContent = totalSelected;
    }

    // Select all / deselect all for "Add" section
    const selectAllAdd = document.getElementById('select-all-add');
    const deselectAllAdd = document.getElementById('deselect-all-add');

    if (selectAllAdd) {
        selectAllAdd.addEventListener('click', () => {
            document.querySelectorAll('#items-to-add-grid input[type="checkbox"]:not([style*="display: none"])').forEach(cb => {
                cb.checked = true;
            });
            updateCounts();
        });
    }

    if (deselectAllAdd) {
        deselectAllAdd.addEventListener('click', () => {
            document.querySelectorAll('#items-to-add-grid input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            updateCounts();
        });
    }

    // Similar for "Remove" section
    const selectAllRemove = document.getElementById('select-all-remove');
    const deselectAllRemove = document.getElementById('deselect-all-remove');

    if (selectAllRemove) {
        selectAllRemove.addEventListener('click', () => {
            document.querySelectorAll('#items-to-remove-grid input[type="checkbox"]:not([style*="display: none"])').forEach(cb => {
                cb.checked = true;
            });
            updateCounts();
        });
    }

    if (deselectAllRemove) {
        deselectAllRemove.addEventListener('click', () => {
            document.querySelectorAll('#items-to-remove-grid input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            updateCounts();
        });
    }

    // Bulk select by filter
    document.querySelectorAll('[data-filter]').forEach(button => {
        button.addEventListener('click', function() {
            const filter = this.dataset.filter;
            const section = this.dataset.section; // 'add' or 'remove'
            const [type, value] = filter.split(':');

            const gridId = section === 'add' ? 'items-to-add-grid' : 'items-to-remove-grid';
            const grid = document.getElementById(gridId);

            if (!grid) return;

            grid.querySelectorAll('.card').forEach(card => {
                if (card.style.display === 'none') return; // Skip hidden items

                const checkbox = card.querySelector('input[type="checkbox"]');
                let matches = false;

                if (type === 'rarity') {
                    matches = card.dataset.rarity === value;
                } else if (type === 'type') {
                    matches = card.dataset.type === value;
                } else if (type === 'price') {
                    const operator = value.startsWith('>') ? '>' : '<';
                    const threshold = parseFloat(value.substring(1));
                    const price = parseFloat(card.dataset.price || '0');
                    matches = operator === '>' ? price > threshold : price < threshold;
                }

                if (matches && checkbox) {
                    checkbox.checked = true;
                }
            });

            updateCounts();
        });
    });

    // Search functionality for "Add" section
    const searchAdd = document.getElementById('search-add');
    if (searchAdd) {
        searchAdd.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            document.querySelectorAll('#items-to-add-grid .card').forEach(card => {
                const name = card.querySelector('h3').textContent.toLowerCase();
                card.style.display = name.includes(query) ? '' : 'none';
            });
            // Don't update counts here - just hide/show cards
        });
    }

    // Search functionality for "Remove" section
    const searchRemove = document.getElementById('search-remove');
    if (searchRemove) {
        searchRemove.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            document.querySelectorAll('#items-to-remove-grid .card').forEach(card => {
                const name = card.querySelector('h3').textContent.toLowerCase();
                card.style.display = name.includes(query) ? '' : 'none';
            });
        });
    }

    // Update counts on any checkbox change
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', updateCounts);
    });

    // On form submit, collect selected IDs
    if (form) {
        form.addEventListener('submit', function(e) {
            // Check if any items are selected
            const totalSelected = document.querySelectorAll('input[type="checkbox"]:checked').length;
            if (totalSelected === 0) {
                e.preventDefault();
                alert('Please select at least one item to import.');
                return;
            }

            // Clear existing hidden inputs
            selectedItemsContainer.innerHTML = '';

            // Add selected item IDs as hidden inputs
            document.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_items[]';
                input.value = cb.value;
                selectedItemsContainer.appendChild(input);
            });
        });
    }
});
```

### 4. Update Asset Pipeline

**File**: `assets/app.js`

```javascript
import './js/import-preview.js';
```

Then rebuild:
```bash
docker compose run --rm node npm run build
```

### 5. Add Data Attributes to Item Cards

Ensure the item card component adds these data attributes when in checkbox mode:
- `data-rarity="{{ rarity }}"`
- `data-type="{{ type }}"`
- `data-price="{{ priceValue }}"`

These enable JavaScript filtering.

## Implementation Steps

1. **Update item card component** (30 minutes)
   - Ensure checkbox mode works correctly
   - Add data attributes for filtering
   - Test checkbox rendering

2. **Update import preview template** (2 hours)
   - Add IDs to summary stat counts
   - Add bulk control buttons above each section
   - Update item cards to use checkbox mode
   - Add hidden input container to form
   - Update submit button text to show count

3. **Create JavaScript file** (2 hours)
   - Create `assets/js/import-preview.js`
   - Implement count update function
   - Implement select all / deselect all
   - Implement bulk filters (rarity, type, price)
   - Implement search functionality
   - Implement form submission handler
   - Add validation (at least one item selected)

4. **Update asset pipeline** (15 minutes)
   - Import JS file in app.js
   - Rebuild assets
   - Test that JS loads correctly

5. **Test checkbox functionality** (1 hour)
   - Test individual checkbox toggle
   - Test select all / deselect all
   - Test bulk filters
   - Test search
   - Verify counts update in real-time
   - Test form submission with selected items

6. **Test edge cases** (30 minutes)
   - Try to submit with no items selected (should show alert)
   - Search then select all (should only select visible)
   - Filter then deselect all
   - Mobile touch interactions

## Edge Cases & Error Handling

### Edge Case 1: No Items Selected
**Scenario**: User unchecks all items before submitting.

**Handling**:
- JavaScript validates before submission
- Show alert: "Please select at least one item to import."
- Prevent form submission

### Edge Case 2: Search + Select All
**Scenario**: User searches for items, then clicks "Select All".

**Handling**:
- "Select All" should only select visible (not hidden) items
- Check for `style="display: none"` before selecting

### Edge Case 3: Filter + Bulk Action
**Scenario**: User filters by rarity, then clicks bulk select for type.

**Handling**:
- Bulk select should respect current visibility
- Only check items that match filter AND are visible

## Acceptance Criteria

- [ ] Each item card has a checkbox in top-right corner
- [ ] All checkboxes are checked by default
- [ ] Clicking checkbox toggles checked state
- [ ] "Select All" button works for items to add
- [ ] "Deselect All" button works for items to add
- [ ] "Select All" button works for items to remove
- [ ] "Deselect All" button works for items to remove
- [ ] Bulk select by rarity works (Covert, Classified, etc.)
- [ ] Bulk select by type works (Knife, Gloves, etc.)
- [ ] Bulk select by price works (> $10, > $100)
- [ ] Search box filters items by name
- [ ] Search box works for both add and remove sections
- [ ] "Items to Add" count updates when checkboxes change
- [ ] "Items to Remove" count updates when checkboxes change
- [ ] Submit button shows total selected count
- [ ] Form submits with only selected item IDs
- [ ] Alert shows if user tries to submit with no items selected
- [ ] Checkboxes are touch-friendly on mobile
- [ ] Bulk controls work on mobile
- [ ] No performance lag when toggling checkboxes (100+ items)

## Notes & Considerations

### Alpine.js for Dropdown

The bulk select dropdown uses Alpine.js for show/hide functionality. Alpine.js is already included in the base template.

### Checkbox Styling

Tailwind provides basic checkbox styling. For better UX, consider:
- Larger checkbox size (w-5 h-5)
- Custom colors when checked
- Hover effects

### Visual Feedback for Unchecked Items

When item is unchecked, consider:
- Reduce opacity of entire card
- Add "skipped" badge
- Change border color

This can be done with CSS:
```css
.card:has(input[type="checkbox"]:not(:checked)) {
    opacity: 0.5;
}
```

## Dependencies

- **Task 1001**: Item card component with checkbox mode
- **Task 1004**: Preview displays actual items

## Next Tasks

After this task is complete:
- **Task 1006**: Update import execution to respect selection

## Related Files

- `templates/inventory/import_preview.html.twig`
- `templates/components/item_card.html.twig`
- `assets/js/import-preview.js`
- `assets/app.js`
