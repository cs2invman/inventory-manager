# Selective Inventory Import with Reusable Item Card Component

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Large
**Created**: 2025-11-03

## Overview

Transform the inventory import process from a full delete-and-replace operation to a selective import system where users can choose exactly which items to add and which to remove. This includes creating a reusable item card component for consistent item display across the entire system.

## Problem Statement

Currently, the inventory import process (`InventoryImportService::prepareImportPreview()` and `executeImport()`) performs a complete delete-and-replace:
1. Shows preview with aggregate statistics (by rarity, by type, notable items)
2. Deletes ALL existing inventory items (where `storageBox IS NULL`)
3. Imports ALL items from the new JSON

**Issues with current approach:**
- No granular control over what gets imported
- Users can't exclude specific items from import
- Users can't keep specific existing items that aren't in the new snapshot
- No way to handle partial inventory updates or selective syncs

**Desired behavior:**
- Show individual items to be added and items to be removed
- Allow users to select/deselect items with checkboxes (default: all checked)
- Provide bulk selection tools (select all by rarity, type, price range, etc.)
- Only add/remove items that are checked in the preview
- Use a consistent, reusable item card component across the system

## Requirements

### Functional Requirements

1. **Preview Page Changes**
   - Remove "Items by Rarity", "Items by Type", and "Notable Items" sections
   - Replace with two sections: "Items to Add" and "Items to Remove"
   - Each section displays items in a grid using the reusable item card component
   - Each item has a checkbox (default: checked)
   - Show actual item data: image, name, price, wear, float, stickers, keychains

2. **Item Selection Controls**
   - Individual checkboxes on each item card
   - "Select All" / "Deselect All" toggle for each section
   - Bulk selection buttons:
     - By rarity (e.g., "Select all Covert", "Select all Knives")
     - By type (e.g., "Select all Rifles", "Select all Gloves")
     - By price range (e.g., "Select all items > $10", "Select all items > $100")
   - Search/filter text box to find items by name

3. **Dynamic Summary Statistics**
   - Keep the 4 summary stat cards at the top
   - Update counts in real-time as user checks/unchecks items
   - Show: Total Items to Import, Items to Add (checked), Items to Remove (checked), Storage Boxes

4. **Reusable Item Card Component**
   - Create `templates/components/item_card.html.twig` as a Twig embed
   - Display: item image, name, price, wear category, float value, pattern index
   - Show stickers and keychains as overlays
   - Show StatTrak/Souvenir badges
   - Show rarity indicator line at bottom
   - Support different "modes": display-only, with-checkbox, with-storage-badge
   - Allow customization through embed blocks

5. **Session Storage Changes**
   - Store item selection state in session alongside item data
   - Track which items are selected for add/remove
   - Preserve selection state if user navigates away and comes back

6. **Import Execution Changes**
   - Only add items that are checked in "Items to Add"
   - Only remove items that are checked in "Items to Remove"
   - Match items to remove by assetId for accuracy

### Non-Functional Requirements

- **Performance**: Handle inventories with 500+ items without lag
- **UX**: Clear visual feedback for selected/deselected states
- **Consistency**: Item card component must work in inventory index, import preview, storage deposit/withdraw
- **Accessibility**: Checkboxes and buttons properly labeled for screen readers
- **Mobile-friendly**: Responsive design works on tablets and phones

## Technical Approach

### 1. Reusable Item Card Component

**File**: `templates/components/item_card.html.twig`

Create a Twig embed template that accepts:
- `itemUser` - ItemUser entity
- `item` - Item entity
- `price` - Price data (optional)
- `stickersWithPrices` - Array of sticker data (optional)
- `keychainWithPrice` - Keychain data (optional)
- `mode` - String: 'display', 'with-checkbox', 'with-storage-badge'
- `checked` - Boolean: checkbox state (default true)
- `itemId` - Unique identifier for checkbox name/id

**Embed blocks for customization:**
- `badges` - Additional badges to show on the card
- `actions` - Action buttons (view, edit, etc.)
- `checkbox` - Override checkbox rendering

**Usage examples:**

```twig
{# Inventory index - display only #}
{% embed 'components/item_card.html.twig' with {
    itemUser: itemUser,
    item: item,
    price: priceData,
    stickersWithPrices: stickers,
    keychainWithPrice: keychain,
    mode: 'with-storage-badge'
} %}
{% endembed %}

{# Import preview - with checkbox #}
{% embed 'components/item_card.html.twig' with {
    itemUser: itemUser,
    item: item,
    price: priceData,
    mode: 'with-checkbox',
    checked: true,
    itemId: 'add-' ~ loop.index
} %}
    {% block badges %}
        <span class="badge-new">NEW</span>
    {% endblock %}
{% endembed %}
```

### 2. Database/Entity Changes

**No changes required** - Existing entities support this feature.

### 3. Service Layer Changes

#### **InventoryImportService Changes**

**A. Modify `prepareImportPreview()` method:**

Current behavior:
- Generates aggregate stats (by rarity, by type, notable items)
- Calculates `itemsToAdd` and `itemsToRemove` counts

New behavior:
- Calculate actual items to add (new assetIds not in current inventory)
- Calculate actual items to remove (current assetIds not in new inventory)
- Return full item data for both lists (not just counts)
- Include price lookups for preview display

**B. Modify `executeImport()` method:**

Current behavior:
- Deletes ALL items where `storageBox IS NULL`
- Inserts ALL items from session

New behavior:
- Accept additional parameters: `selectedAddIds[]` and `selectedRemoveIds[]`
- Only delete items whose assetIds are in `selectedRemoveIds[]`
- Only insert items whose indices/identifiers are in `selectedAddIds[]`

**C. Add new method: `getItemsToAdd()` and `getItemsToRemove()`**

These helper methods compare current inventory with new inventory to determine differences:

```php
private function getItemsToAdd(array $mappedItems, array $currentInventory): array
{
    $newAssetIds = array_map(fn($m) => $m['data']['asset_id'], $mappedItems);
    $currentAssetIds = array_map(fn($item) => $item->getAssetId(), $currentInventory);

    $assetIdsToAdd = array_diff($newAssetIds, $currentAssetIds);

    // Return full mapped items that are new
    return array_filter($mappedItems, fn($m) => in_array($m['data']['asset_id'], $assetIdsToAdd));
}

private function getItemsToRemove(array $mappedItems, array $currentInventory): array
{
    $newAssetIds = array_map(fn($m) => $m['data']['asset_id'], $mappedItems);
    $currentAssetIds = array_map(fn($item) => $item->getAssetId(), $currentInventory);

    $assetIdsToRemove = array_diff($currentAssetIds, $newAssetIds);

    // Return current ItemUser entities that are no longer present
    return array_filter($currentInventory, fn($item) => in_array($item->getAssetId(), $assetIdsToRemove));
}
```

**D. Update session storage:**

Store selection state:
```php
$serializableData = [
    'items' => [...],
    'storage_boxes' => [...],
    'items_to_add' => $itemsToAdd,  // with prices and full data
    'items_to_remove' => $itemsToRemove,  // with prices and full data
    'selected_add_ids' => array_keys($itemsToAdd),  // default: all selected
    'selected_remove_ids' => array_keys($itemsToRemove),  // default: all selected
];
```

### 4. DTO Changes

#### **ImportPreview DTO Changes**

Update `src/DTO/ImportPreview.php`:

```php
public function __construct(
    public readonly int $totalItems,
    public readonly int $itemsToAdd,
    public readonly int $itemsToRemove,
    public readonly array $itemsToAddData,  // NEW: full item data
    public readonly array $itemsToRemoveData,  // NEW: full item data
    public readonly array $unmatchedItems,
    public readonly array $errors,
    public readonly string $sessionKey,
    public readonly int $storageBoxCount = 0,
    // REMOVED: statsByRarity, statsByType, notableItems
) {
}
```

### 5. Controller Changes

#### **InventoryImportController Changes**

**A. Update `preview()` method:**

- Call updated `prepareImportPreview()` which now returns full item data
- Look up prices for items to add and items to remove
- Pass enriched data to template

**B. Update `confirm()` method:**

- Accept `selected_add_ids[]` and `selected_remove_ids[]` from POST request
- Pass selection arrays to `executeImport()`
- Validate that selected IDs exist in session data

**New endpoint (optional): Save selection state**

```php
#[Route('/update-selection', name: 'inventory_import_update_selection', methods: ['POST'])]
public function updateSelection(Request $request): Response
{
    // Accept JSON with selected IDs
    // Update session with new selection state
    // Return updated counts
}
```

This allows saving selection state without navigating away.

### 6. Frontend Changes

#### **A. Update `templates/inventory/import_preview.html.twig`**

**Remove:**
- "Items by Rarity" section
- "Items by Type" section
- "Notable Items" section

**Add:**
- "Items to Add" section with grid of item cards
- "Items to Remove" section with grid of item cards
- Bulk selection controls for each section
- JavaScript for checkbox management and count updates

**Structure:**

```twig
<!-- Summary Statistics (updated dynamically) -->
<div class="grid grid-cols-4 gap-4 mb-8" id="summary-stats">
    <div class="card">
        <p class="text-sm text-gray-400">Total Items to Import</p>
        <p class="text-4xl font-bold text-cs2-orange" id="total-items-count">{{ preview.totalItems }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-gray-400">Items to Add</p>
        <p class="text-4xl font-bold text-green-400" id="items-to-add-count">{{ preview.itemsToAdd }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-gray-400">Items to Remove</p>
        <p class="text-4xl font-bold text-red-400" id="items-to-remove-count">{{ preview.itemsToRemove }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-gray-400">Storage Boxes</p>
        <p class="text-4xl font-bold text-white">{{ preview.storageBoxCount }}</p>
    </div>
</div>

<!-- Items to Add Section -->
<div class="mb-8">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-2xl font-bold text-white">Items to Add</h2>
        <div class="flex items-center gap-2">
            <!-- Bulk selection controls -->
            <button type="button" id="select-all-add" class="btn-sm">Select All</button>
            <button type="button" id="deselect-all-add" class="btn-sm">Deselect All</button>

            <!-- Filter dropdown -->
            <div class="dropdown">
                <button class="btn-sm">Bulk Select ▾</button>
                <div class="dropdown-menu">
                    <button data-filter="rarity:Covert">Covert items</button>
                    <button data-filter="type:Knife">Knives</button>
                    <button data-filter="price:>10">Items > $10</button>
                    <button data-filter="price:>100">Items > $100</button>
                </div>
            </div>

            <!-- Search -->
            <input type="text" id="search-add" placeholder="Search items..." class="input-sm">
        </div>
    </div>

    <div class="grid grid-cols-6 gap-4" id="items-to-add-grid">
        {% for itemData in preview.itemsToAddData %}
            {% embed 'components/item_card.html.twig' with {
                itemUser: itemData.itemUser,
                item: itemData.item,
                price: itemData.price,
                stickersWithPrices: itemData.stickers,
                keychainWithPrice: itemData.keychain,
                mode: 'with-checkbox',
                checked: true,
                itemId: 'add-' ~ loop.index,
                rarity: itemData.item.rarity,
                type: itemData.item.type,
                priceValue: itemData.priceValue
            } %}
                {% block badges %}
                    <span class="absolute top-2 left-2 bg-green-600 text-white px-2 py-1 rounded text-xs font-bold">
                        NEW
                    </span>
                {% endblock %}
            {% endembed %}
        {% endfor %}
    </div>
</div>

<!-- Items to Remove Section -->
<div class="mb-8">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-2xl font-bold text-white">Items to Remove</h2>
        <div class="flex items-center gap-2">
            <!-- Similar bulk controls as above -->
        </div>
    </div>

    <div class="grid grid-cols-6 gap-4" id="items-to-remove-grid">
        {% for itemData in preview.itemsToRemoveData %}
            {% embed 'components/item_card.html.twig' with {
                itemUser: itemData.itemUser,
                item: itemData.item,
                price: itemData.price,
                mode: 'with-checkbox',
                checked: true,
                itemId: 'remove-' ~ loop.index,
                rarity: itemData.item.rarity,
                type: itemData.item.type,
                priceValue: itemData.priceValue
            } %}
                {% block badges %}
                    <span class="absolute top-2 left-2 bg-red-600 text-white px-2 py-1 rounded text-xs font-bold">
                        REMOVE
                    </span>
                {% endblock %}
            {% endembed %}
        {% endfor %}
    </div>
</div>

<!-- Confirm Form -->
<form action="{{ path('inventory_import_confirm') }}" method="post" id="import-form">
    <input type="hidden" name="session_key" value="{{ preview.sessionKey }}">

    <!-- Hidden inputs for selected item IDs (populated by JavaScript) -->
    <div id="selected-items-container"></div>

    <div class="flex justify-center gap-4">
        <button type="button" id="cancel-btn" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary">Confirm Import</button>
    </div>
</form>
```

#### **B. Create `templates/components/item_card.html.twig`**

Reusable item card component using Twig embed:

```twig
{# Component variables:
   - itemUser (ItemUser entity)
   - item (Item entity)
   - price (price data, optional)
   - stickersWithPrices (array, optional)
   - keychainWithPrice (object, optional)
   - mode ('display', 'with-checkbox', 'with-storage-badge')
   - checked (boolean, for checkbox mode)
   - itemId (string, unique identifier)
   - rarity (string, optional for filtering)
   - type (string, optional for filtering)
   - priceValue (float, optional for filtering)
#}

<div class="card hover:border-cs2-orange transition-colors duration-200 group relative"
     data-rarity="{{ rarity|default('') }}"
     data-type="{{ type|default('') }}"
     data-price="{{ priceValue|default(0) }}"
     data-item-id="{{ itemId|default('') }}">

    {% if mode == 'with-checkbox' %}
        <!-- Checkbox overlay -->
        <div class="absolute top-2 right-2 z-20">
            <input type="checkbox"
                   id="{{ itemId }}"
                   name="selected_items[]"
                   value="{{ itemId }}"
                   class="item-checkbox w-5 h-5 rounded border-gray-400"
                   {{ checked ? 'checked' : '' }}>
        </div>
    {% endif %}

    {% if mode == 'with-storage-badge' and itemUser.storageBox is not null %}
        <!-- Storage badge -->
        <div class="absolute top-2 left-2 z-10">
            <span class="inline-flex items-center gap-1 bg-blue-600 text-white px-2 py-1 rounded text-xs font-bold">
                📦 {{ itemUser.storageBox.name }}
            </span>
        </div>
    {% endif %}

    {% block badges %}
        {# Customizable badge block #}
    {% endblock %}

    <!-- Item Name -->
    <h3 class="font-semibold text-white text-sm line-clamp-2 group-hover:text-cs2-orange transition-colors mb-3">
        {{ item.name }}
    </h3>

    <!-- Item Image -->
    <div class="relative bg-gray-900 rounded-lg p-4 mb-3 flex items-center justify-center h-48">
        <!-- Rarity indicator line -->
        {% if item.rarityColor %}
            <div class="absolute bottom-0 left-0 right-0 h-1 rounded-b-lg" style="background-color: {{ item.rarityColor }};"></div>
        {% endif %}

        <img src="{{ item.imageUrl }}" alt="{{ item.name }}" class="max-h-full max-w-full object-contain">

        <!-- Steam Market Link -->
        <a href="https://steamcommunity.com/market/listings/730/{{ item.hashName|url_encode }}"
           target="_blank"
           class="absolute top-2 right-2 bg-gray-800 hover:bg-steam-blue p-1.5 rounded transition-colors"
           title="View on Steam Market">
            <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="currentColor">
                <!-- Steam icon SVG path -->
            </svg>
        </a>

        <!-- StatTrak/Souvenir badges -->
        {% if itemUser.isStattrak %}
            <span class="absolute top-2 left-2 bg-cs2-orange text-white text-xs font-bold px-2 py-1 rounded">ST</span>
        {% endif %}
        {% if itemUser.isSouvenir %}
            <span class="absolute top-2 left-2 bg-yellow-500 text-white text-xs font-bold px-2 py-1 rounded">SV</span>
        {% endif %}

        <!-- Stickers and Keychain Overlay -->
        {% if stickersWithPrices or keychainWithPrice %}
            <div class="absolute bottom-2 left-2 flex gap-1">
                {% if keychainWithPrice %}
                    <img src="{{ keychainWithPrice.image_url }}"
                         title="Keychain: {{ keychainWithPrice.name }}"
                         class="h-8 w-10 rounded bg-gray-800/80 p-0.5 border border-yellow-500">
                {% endif %}
                {% if stickersWithPrices %}
                    {% for sticker in stickersWithPrices %}
                        <img src="{{ sticker.image_url }}"
                             title="{{ sticker.name }}"
                             class="h-8 w-10 rounded bg-gray-800/80 p-0.5">
                    {% endfor %}
                {% endif %}
            </div>
        {% endif %}
    </div>

    <!-- Item Details -->
    <div class="space-y-2">
        <div class="flex items-center justify-between text-xs">
            <div class="flex items-center gap-2">
                {% if itemUser.wearCategory %}
                    <span class="bg-gray-700 text-gray-300 px-2 py-0.5 rounded">{{ itemUser.wearCategory }}</span>
                {% endif %}
                {% if itemUser.floatValue %}
                    <span class="text-gray-300 font-mono">{{ itemUser.floatValue }}</span>
                {% endif %}
                {% if itemUser.patternIndex %}
                    <span class="text-gray-300 font-mono">[{{ itemUser.patternIndex }}]</span>
                {% endif %}
            </div>

            <span class="text-green-400 font-bold text-lg">
                {% if price %}
                    ${{ priceValue|number_format(2, '.', ',') }}
                {% else %}
                    <span class="text-gray-500">N/A</span>
                {% endif %}
            </span>
        </div>

        {% if itemUser.nameTag %}
            <div class="pt-2 border-t border-gray-700">
                <p class="text-xs text-gray-400">Name Tag:</p>
                <p class="text-xs text-yellow-400 italic">"{{ itemUser.nameTag }}"</p>
            </div>
        {% endif %}
    </div>

    {% block actions %}
        {# Customizable actions block #}
    {% endblock %}
</div>
```

#### **C. Add JavaScript for checkbox management**

Create `assets/js/import-preview.js`:

```javascript
// Checkbox state management
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('import-form');
    const selectedItemsContainer = document.getElementById('selected-items-container');

    // Update counts when checkboxes change
    function updateCounts() {
        const addChecked = document.querySelectorAll('#items-to-add-grid input[type="checkbox"]:checked').length;
        const removeChecked = document.querySelectorAll('#items-to-remove-grid input[type="checkbox"]:checked').length;

        document.getElementById('items-to-add-count').textContent = addChecked;
        document.getElementById('items-to-remove-count').textContent = removeChecked;
    }

    // Select all / deselect all
    document.getElementById('select-all-add').addEventListener('click', () => {
        document.querySelectorAll('#items-to-add-grid input[type="checkbox"]').forEach(cb => cb.checked = true);
        updateCounts();
    });

    document.getElementById('deselect-all-add').addEventListener('click', () => {
        document.querySelectorAll('#items-to-add-grid input[type="checkbox"]').forEach(cb => cb.checked = false);
        updateCounts();
    });

    // Similar for remove section...

    // Bulk select by filter
    document.querySelectorAll('[data-filter]').forEach(button => {
        button.addEventListener('click', function() {
            const filter = this.dataset.filter;
            const [type, value] = filter.split(':');

            const section = this.closest('.mb-8');
            section.querySelectorAll('.card').forEach(card => {
                const checkbox = card.querySelector('input[type="checkbox"]');
                let matches = false;

                if (type === 'rarity') {
                    matches = card.dataset.rarity === value;
                } else if (type === 'type') {
                    matches = card.dataset.type === value;
                } else if (type === 'price') {
                    const operator = value.startsWith('>') ? '>' : '<';
                    const threshold = parseFloat(value.substring(1));
                    const price = parseFloat(card.dataset.price);
                    matches = operator === '>' ? price > threshold : price < threshold;
                }

                if (matches && checkbox) {
                    checkbox.checked = true;
                }
            });

            updateCounts();
        });
    });

    // Search functionality
    document.getElementById('search-add').addEventListener('input', function() {
        const query = this.value.toLowerCase();
        document.querySelectorAll('#items-to-add-grid .card').forEach(card => {
            const name = card.querySelector('h3').textContent.toLowerCase();
            card.style.display = name.includes(query) ? '' : 'none';
        });
    });

    // Similar for remove section search...

    // Update counts on any checkbox change
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', updateCounts);
    });

    // On form submit, collect selected IDs
    form.addEventListener('submit', function(e) {
        // Clear existing hidden inputs
        selectedItemsContainer.innerHTML = '';

        // Add selected item IDs
        document.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = cb.name;
            input.value = cb.value;
            selectedItemsContainer.appendChild(input);
        });
    });
});
```

#### **D. Update other templates to use item card component**

**Update `templates/inventory/index.html.twig`:**

Replace lines 174-308 (the item card HTML) with:

```twig
{% for itemData in itemsWithPrices %}
    {% embed 'components/item_card.html.twig' with {
        itemUser: itemData.itemUser,
        item: itemData.item,
        price: itemData.latestPrice,
        stickersWithPrices: itemData.stickersWithPrices,
        keychainWithPrice: itemData.keychainWithPrice,
        mode: 'with-storage-badge',
        priceValue: itemData.priceValue
    } %}
    {% endembed %}
{% endfor %}
```

**Update storage deposit/withdraw templates:**

Similar changes to use the item card component.

### 7. Configuration Changes

**Add to `assets/app.js`:**

```javascript
import './js/import-preview.js';
```

**Rebuild assets:**
```bash
docker compose run --rm node npm run build
```

## Implementation Steps

### Phase 1: Create Reusable Item Card Component (2-3 hours)

1. **Create `templates/components/item_card.html.twig`**
   - Copy HTML structure from `templates/inventory/index.html.twig` lines 180-308
   - Convert to parameterized Twig embed
   - Add support for `mode` variable (display, with-checkbox, with-storage-badge)
   - Add `badges` and `actions` blocks for customization
   - Test rendering with sample data

2. **Update `templates/inventory/index.html.twig`**
   - Replace item card HTML (lines 174-308) with embed usage
   - Test that inventory index page renders correctly
   - Verify no visual regressions

3. **Update storage deposit/withdraw templates**
   - Replace item card HTML in `templates/storage_box/deposit.html.twig`
   - Replace item card HTML in `templates/storage_box/withdraw.html.twig`
   - Test both flows to ensure cards render correctly

### Phase 2: Service Layer - Calculate Items to Add/Remove (3-4 hours)

4. **Add helper methods to `InventoryImportService`**
   - Add `getItemsToAdd(array $mappedItems, array $currentInventory): array`
   - Add `getItemsToRemove(array $mappedItems, array $currentInventory): array`
   - Logic: compare assetIds between new and current inventory
   - Return full item data (not just counts)

5. **Update `prepareImportPreview()` method**
   - Call `getItemsToAdd()` and `getItemsToRemove()`
   - For each item, look up latest price (use existing price lookup logic)
   - For items to add, enrich with sticker/keychain prices
   - For items to remove, look up existing prices from database
   - Build `$itemsToAddData` and `$itemsToRemoveData` arrays with full details
   - Update session storage to include both arrays

6. **Update session storage format**
   - Add `items_to_add` key with enriched item data
   - Add `items_to_remove` key with enriched item data
   - Add `selected_add_ids` key (default: all IDs)
   - Add `selected_remove_ids` key (default: all IDs)
   - Update `storeInSession()` and `retrieveFromSession()` methods

### Phase 3: DTO Changes (30 minutes)

7. **Update `ImportPreview` DTO**
   - Add `itemsToAddData` property (array)
   - Add `itemsToRemoveData` property (array)
   - Remove `statsByRarity` property
   - Remove `statsByType` property
   - Remove `notableItems` property
   - Update constructor and `toArray()` method

### Phase 4: Controller Changes (1-2 hours)

8. **Update `InventoryImportController::preview()` method**
   - Updated `prepareImportPreview()` now returns full item data
   - No additional price lookups needed (already done in service)
   - Pass `itemsToAddData` and `itemsToRemoveData` to template

9. **Update `InventoryImportController::confirm()` method**
   - Accept `selected_items[]` from POST request
   - Parse selected IDs to separate add vs. remove (check 'add-' or 'remove-' prefix)
   - Update session with selected IDs before calling `executeImport()`
   - Validate that selected IDs exist in session data

### Phase 5: Import Execution Logic (2-3 hours)

10. **Update `InventoryImportService::executeImport()` method**
    - Retrieve `selected_add_ids` and `selected_remove_ids` from session
    - **Delete logic**: Only delete items whose assetIds are in `selected_remove_ids`
      ```php
      $assetIdsToRemove = []; // extracted from selected_remove_ids
      $qb = $this->entityManager->createQueryBuilder();
      $qb->delete(ItemUser::class, 'iu')
          ->where('iu.user = :user')
          ->andWhere('iu.storageBox IS NULL')
          ->andWhere('iu.assetId IN (:asset_ids)')
          ->setParameter('user', $user)
          ->setParameter('asset_ids', $assetIdsToRemove);
      $qb->getQuery()->execute();
      ```
    - **Insert logic**: Only insert items whose identifiers are in `selected_add_ids`
      ```php
      foreach ($mappedItems as $index => $mappedItem) {
          $itemId = 'add-' . $index;
          if (!in_array($itemId, $selectedAddIds)) {
              continue; // Skip unselected items
          }

          // Existing insert logic...
      }
      ```
    - Update success message to reflect actual counts

### Phase 6: Frontend - Preview Template (3-4 hours)

11. **Update `templates/inventory/import_preview.html.twig`**
    - Remove "Items by Rarity" section (lines 73-94)
    - Remove "Items by Type" section (lines 96-117)
    - Remove "Notable Items" section (lines 119-143)
    - Keep summary statistics (lines 45-70) but update IDs for JavaScript
    - Add "Items to Add" section with grid
    - Add "Items to Remove" section with grid
    - Use item card embeds with `mode: 'with-checkbox'`
    - Add bulk selection controls (select all, deselect all, filters, search)
    - Update form to include hidden input container for selected IDs

12. **Add data attributes to item cards for filtering**
    - Add `data-rarity`, `data-type`, `data-price` attributes
    - These enable JavaScript filtering by category

### Phase 7: Frontend - JavaScript (2-3 hours)

13. **Create `assets/js/import-preview.js`**
    - Implement checkbox state management
    - Implement "Select All" / "Deselect All" for both sections
    - Implement bulk selection by rarity, type, price
    - Implement search/filter functionality
    - Implement dynamic count updates in summary stats
    - On form submit, populate hidden inputs with selected IDs

14. **Update `assets/app.js`**
    - Import `import-preview.js`

15. **Rebuild assets**
    ```bash
    docker compose run --rm node npm run build
    ```

### Phase 8: Testing & Refinement (2-3 hours)

16. **Manual Testing - Item Card Component**
    - Verify inventory index page shows items correctly
    - Verify storage deposit/withdraw pages show items correctly
    - Check responsiveness on mobile/tablet
    - Verify all badges, overlays, and prices display correctly

17. **Manual Testing - Import Preview**
    - Import inventory with mixed items (some new, some existing)
    - Verify "Items to Add" shows only new items with correct data
    - Verify "Items to Remove" shows only items being removed with correct data
    - Test checkbox selection (individual, select all, bulk by category)
    - Test search functionality
    - Verify summary counts update dynamically

18. **Manual Testing - Import Execution**
    - Deselect some items to add and some items to remove
    - Confirm import
    - Verify only selected items were added/removed
    - Verify items in storage boxes are never affected
    - Check database to confirm assetIds match expectations

19. **Manual Testing - Edge Cases**
    - Empty inventory import (no items to add/remove)
    - Import with all items deselected (should show warning or do nothing)
    - Import with 500+ items (check performance)
    - Import with only tradeable or only trade-locked JSON
    - Items with unusual names, long names, special characters

20. **Visual Polish**
    - Ensure consistent spacing, colors, hover states
    - Improve mobile responsiveness if needed
    - Add loading states if needed
    - Add tooltips or help text if needed

## Edge Cases & Error Handling

### Edge Case 1: All Items Deselected
**Scenario**: User unchecks all items before confirming import.

**Handling**:
- JavaScript should detect if no items are selected and show warning
- Optionally disable "Confirm Import" button if nothing is selected
- If user somehow submits with nothing selected, show message "No items selected for import"

### Edge Case 2: Session Expiration
**Scenario**: User spends too long on preview page and session expires.

**Handling**:
- Current behavior: Show error "Session data not found or expired"
- Keep this behavior but improve UX with better messaging
- Suggest user re-upload JSON to generate new preview

### Edge Case 3: Large Inventories (500+ items)
**Scenario**: User has massive inventory with many items to add/remove.

**Handling**:
- Use pagination or lazy loading if grid becomes too large (optional enhancement)
- Ensure JavaScript doesn't lag when updating counts
- Consider adding "loading..." indicator during preview generation

### Edge Case 4: Item Matching Failures
**Scenario**: Some items can't be matched in database (already handled with unmatchedItems).

**Handling**:
- Keep existing behavior: show warning with unmatched items
- These items should NOT appear in "Items to Add" section
- User should be informed that unmatched items will be skipped

### Edge Case 5: AssetId Changes
**Scenario**: Steam changes assetIds between exports (rare but possible).

**Handling**:
- Current matching is by assetId, which is correct
- If assetId changes, item will appear as "remove old, add new"
- This is acceptable behavior - no special handling needed

### Edge Case 6: Duplicate AssetIds
**Scenario**: Duplicate assetIds in JSON (already handled in `prepareImportPreview`).

**Handling**:
- Keep existing deduplication logic (lines 69-80 in InventoryImportService)
- Log warning and skip duplicates

### Edge Case 7: Storage Box Items in Import
**Scenario**: Import includes items that are currently in storage boxes.

**Handling**:
- Current behavior: Items in storage are NEVER deleted (line 166: `andWhere('iu.storageBox IS NULL')`)
- Keep this behavior - it's correct
- If item is in storage and appears in new import, treat as "already exists" (no add, no remove)

## Dependencies

- **No external dependencies** - Uses existing Symfony, Twig, Doctrine, and Tailwind CSS
- **No blocking issues** - All required infrastructure already exists

## Acceptance Criteria

- [ ] Reusable item card component created in `templates/components/item_card.html.twig`
- [ ] Item card component supports three modes: display, with-checkbox, with-storage-badge
- [ ] Item card component used in inventory index, import preview, and storage deposit/withdraw
- [ ] Import preview shows "Items to Add" section with all new items in a grid
- [ ] Import preview shows "Items to Remove" section with all existing items to be removed
- [ ] Each item has a checkbox (default: checked)
- [ ] "Select All" / "Deselect All" buttons work for both sections
- [ ] Bulk selection by rarity, type, and price range works
- [ ] Search/filter by item name works
- [ ] Summary statistics update dynamically as checkboxes change
- [ ] Form submission includes only selected item IDs
- [ ] Import execution only adds items that were checked
- [ ] Import execution only removes items that were checked
- [ ] Items in storage boxes are never affected by import (existing behavior preserved)
- [ ] "Items by Rarity", "Items by Type", and "Notable Items" sections removed from preview
- [ ] All existing inventory display features work with new item card component
- [ ] Responsive design works on mobile, tablet, and desktop
- [ ] No visual regressions in inventory index page
- [ ] No visual regressions in storage deposit/withdraw pages
- [ ] Import with 500+ items performs acceptably (< 2 seconds to render)
- [ ] Edge case: All items deselected shows appropriate warning
- [ ] Edge case: Session expiration shows clear error message
- [ ] Edge case: Unmatched items are displayed in warning and excluded from import

## Notes & Considerations

### Recommendation: Use Twig Embed for Item Card Component

**Why Embed over Include or Macro:**

1. **Embed** (Recommended):
   - Allows base template with customizable blocks
   - Great for components that need some flexibility
   - Can override specific sections (badges, actions) while keeping structure
   - Example:
     ```twig
     {% embed 'components/item_card.html.twig' with {...} %}
         {% block badges %}
             <span class="custom-badge">NEW</span>
         {% endblock %}
     {% endembed %}
     ```

2. **Include** (Not recommended):
   - Simple but inflexible
   - Can't customize inner blocks
   - Would need many conditional parameters

3. **Macro** (Not recommended):
   - Returns HTML string, harder to customize
   - No block overrides
   - Better for simple, fully standardized components

**Verdict**: Embed is perfect for this use case because:
- Base structure is consistent (image, name, price, etc.)
- Some parts need customization (badges, checkboxes, storage indicator)
- Allows both fixed behavior and flexible extensions

### Future Improvements

1. **Import History**
   - Track import history (when, how many items added/removed)
   - Show import audit log in settings

2. **Import Scheduling**
   - Allow users to schedule automatic imports (if Steam API access is added)

3. **Conflict Resolution**
   - If item exists in database but has different float/stickers, show as "update" instead of "remove + add"

4. **Advanced Filters**
   - Filter by collection, price range slider, wear category
   - Sort by price, rarity, float value

5. **Bulk Actions on Inventory Page**
   - Select multiple items and perform actions (move to storage, delete, etc.)

6. **Import Presets**
   - Save selection patterns (e.g., "Always skip items < $1")
   - Apply presets to future imports

### Performance Considerations

- Item card rendering: Should handle 500+ items without lag
  - Twig templates are fast, no issue expected
  - If needed, add pagination or lazy loading

- Price lookups: May slow down with large inventories
  - Consider caching price lookups
  - Batch database queries for efficiency

- JavaScript checkbox management: Should be fast
  - Use event delegation for checkbox listeners
  - Avoid re-rendering entire grids

### Security Considerations

- **Session data validation**: Ensure selected IDs actually exist in session before deleting items
- **CSRF protection**: Existing Symfony CSRF tokens already in place
- **Input validation**: Validate assetIds before executing DELETE queries
- **Authorization**: Ensure user can only import to their own inventory (already handled by `IsGranted` and user entity)

## Related Tasks

- None (this is a standalone enhancement to existing import functionality)

---

**Notes for Implementation:**

This task is large and should be implemented in phases as outlined above. Each phase can be tested independently before moving to the next. The reusable item card component (Phase 1) is the foundation and should be completed and tested thoroughly before proceeding to other phases.

Estimated total time: **18-24 hours** of development work.