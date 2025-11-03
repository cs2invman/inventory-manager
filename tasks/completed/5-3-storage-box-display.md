# Storage Box Display

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-02
**Part of**: Task 2 - Storage Box Management System (Phase 3)

## Overview

Update the inventory page to display storage boxes and show visual indicators for items that are stored in boxes.

## Prerequisites

- Task 2-1 (Storage Box Database Setup) must be completed
- Task 2-2 (Storage Box Import Integration) must be completed

## Goals

1. Display ALL items on inventory page (active inventory + items in storage)
2. Show storage boxes as special cards with item count badges
3. Add visual indicator badges on items showing which storage box they're in
4. Implement filtering: All Items, Active Inventory Only, or by specific Storage Box
5. Add "View Contents" link for storage boxes

## Implementation Steps

### 1. Update Inventory Controller

**File**: `src/Controller/InventoryController.php` (or wherever inventory is displayed)

Update to fetch all items and storage boxes:

```php
#[Route('/inventory', name: 'app_inventory')]
public function index(
    Request $request,
    ItemUserRepository $itemUserRepository,
    StorageBoxRepository $storageBoxRepository
): Response {
    $user = $this->getUser();

    // Get ALL items (active inventory + in storage)
    $allItems = $itemUserRepository->findUserInventory($user->getId());

    // Get all storage boxes
    $storageBoxes = $storageBoxRepository->findByUser($user);

    // Get filter parameter
    $filter = $request->query->get('filter', 'all');
    $filterBoxId = $request->query->getInt('box_id', 0);

    // Apply filtering
    $filteredItems = $allItems;
    if ($filter === 'active') {
        // Only items NOT in storage
        $filteredItems = array_filter($allItems, fn($item) => $item->getStorageBox() === null);
    } elseif ($filter === 'box' && $filterBoxId > 0) {
        // Only items in specific storage box
        $filteredItems = array_filter($allItems, fn($item) =>
            $item->getStorageBox() && $item->getStorageBox()->getId() === $filterBoxId
        );
    }

    // Calculate stats
    $totalValue = array_sum(array_map(
        fn($item) => $item->getCurrentMarketValueAsFloat() ?? 0,
        $allItems
    ));

    $activeInventoryCount = count(array_filter($allItems, fn($item) => $item->getStorageBox() === null));
    $storedItemsCount = count($allItems) - $activeInventoryCount;

    return $this->render('inventory/index.html.twig', [
        'items' => $filteredItems,
        'storageBoxes' => $storageBoxes,
        'totalValue' => $totalValue,
        'totalItems' => count($allItems),
        'activeInventoryCount' => $activeInventoryCount,
        'storedItemsCount' => $storedItemsCount,
        'currentFilter' => $filter,
        'currentBoxId' => $filterBoxId,
    ]);
}
```

### 2. Update Inventory Template

**File**: `templates/inventory/index.html.twig`

Add filter controls at the top:

```twig
{# Filter Controls #}
<div class="mb-6 flex items-center gap-4">
    <label class="font-semibold">Filter:</label>
    <div class="flex gap-2">
        <a href="{{ path('app_inventory', {filter: 'all'}) }}"
           class="px-4 py-2 rounded-lg {{ currentFilter == 'all' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700' }}">
            All Items ({{ totalItems }})
        </a>
        <a href="{{ path('app_inventory', {filter: 'active'}) }}"
           class="px-4 py-2 rounded-lg {{ currentFilter == 'active' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700' }}">
            Active Inventory ({{ activeInventoryCount }})
        </a>

        {% if storageBoxes|length > 0 %}
            <div class="relative">
                <select id="storage-box-filter"
                        onchange="if(this.value) window.location.href = '{{ path('app_inventory') }}?filter=box&box_id=' + this.value"
                        class="px-4 py-2 rounded-lg border border-gray-300">
                    <option value="">By Storage Box...</option>
                    {% for box in storageBoxes %}
                        <option value="{{ box.id }}" {{ currentBoxId == box.id ? 'selected' : '' }}>
                            📦 {{ box.name }} ({{ box.itemCount }})
                        </option>
                    {% endfor %}
                </select>
            </div>
        {% endif %}
    </div>

    <div class="ml-auto">
        <span class="text-gray-600">
            In Storage: {{ storedItemsCount }} items
        </span>
    </div>
</div>

{# Stats Cards #}
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-4 rounded-lg shadow">
        <div class="text-sm text-gray-600">Total Value</div>
        <div class="text-2xl font-bold">${{ totalValue|number_format(2) }}</div>
    </div>
    <div class="bg-white p-4 rounded-lg shadow">
        <div class="text-sm text-gray-600">Total Items</div>
        <div class="text-2xl font-bold">{{ totalItems }}</div>
    </div>
    <div class="bg-white p-4 rounded-lg shadow">
        <div class="text-sm text-gray-600">Active Inventory</div>
        <div class="text-2xl font-bold">{{ activeInventoryCount }}</div>
    </div>
    <div class="bg-white p-4 rounded-lg shadow">
        <div class="text-sm text-gray-600">Storage Boxes</div>
        <div class="text-2xl font-bold">{{ storageBoxes|length }}</div>
    </div>
</div>

{# Storage Boxes Section (if not filtering) #}
{% if currentFilter == 'all' and storageBoxes|length > 0 %}
    <div class="mb-8">
        <h2 class="text-xl font-bold mb-4">Storage Boxes</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            {% for box in storageBoxes %}
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 border-2 border-blue-300 rounded-lg p-4 hover:shadow-lg transition">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-2xl">📦</span>
                        <span class="bg-blue-600 text-white px-2 py-1 rounded text-sm font-bold">
                            {{ box.itemCount }} items
                        </span>
                    </div>
                    <h3 class="font-bold text-lg mb-2">{{ box.name }}</h3>
                    <div class="text-xs text-gray-600 mb-3">
                        {% if box.modificationDate %}
                            Modified: {{ box.modificationDate|date('M d, Y') }}
                        {% endif %}
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ path('app_inventory', {filter: 'box', box_id: box.id}) }}"
                           class="flex-1 text-center bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700">
                            View Contents
                        </a>
                    </div>
                </div>
            {% endfor %}
        </div>
    </div>
{% endif %}

{# Items Grid #}
<div>
    <h2 class="text-xl font-bold mb-4">
        {% if currentFilter == 'active' %}
            Active Inventory Items
        {% elseif currentFilter == 'box' %}
            Items in {{ storageBoxes|filter(b => b.id == currentBoxId)|first.name }}
        {% else %}
            All Items
        {% endif %}
    </h2>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
        {% for itemUser in items %}
            <div class="bg-white rounded-lg shadow hover:shadow-xl transition p-4 relative">
                {# Storage Indicator Badge #}
                {% if itemUser.storageBox is not null %}
                    <div class="absolute top-2 left-2 z-10">
                        <span class="inline-flex items-center gap-1 bg-blue-600 text-white px-2 py-1 rounded text-xs font-bold">
                            📦 {{ itemUser.storageBox.name }}
                        </span>
                    </div>
                {% endif %}

                {# Item Image #}
                <div class="mb-2">
                    <img src="{{ itemUser.item.imageUrl }}"
                         alt="{{ itemUser.item.name }}"
                         class="w-full h-32 object-contain">
                </div>

                {# Item Name #}
                <h3 class="text-sm font-semibold mb-1 truncate" title="{{ itemUser.item.name }}">
                    {{ itemUser.item.name }}
                </h3>

                {# Item Details #}
                <div class="text-xs text-gray-600 space-y-1">
                    {% if itemUser.floatValue %}
                        <div>Float: {{ itemUser.floatValue }}</div>
                    {% endif %}
                    {% if itemUser.currentMarketValue %}
                        <div class="font-bold text-green-600">
                            ${{ itemUser.currentMarketValueAsFloat|number_format(2) }}
                        </div>
                    {% endif %}
                </div>
            </div>
        {% else %}
            <div class="col-span-full text-center py-12 text-gray-500">
                No items found.
            </div>
        {% endfor %}
    </div>
</div>
```

### 3. Add CSS Styling (if needed)

**File**: `assets/styles/app.css` (or wherever styles are)

Add any custom styles for storage indicators:

```css
.storage-badge {
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
}

.storage-box-card {
    transition: all 0.3s ease;
}

.storage-box-card:hover {
    transform: translateY(-4px);
}
```

### 4. Update Dashboard Stats (Optional)

**File**: `templates/dashboard/index.html.twig`

Update to show storage box stats:

```twig
<div class="stat-card">
    <div class="stat-label">Items in Storage</div>
    <div class="stat-value">{{ storedItemsCount }}</div>
</div>

<div class="stat-card">
    <div class="stat-label">Storage Boxes</div>
    <div class="stat-value">{{ storageBoxCount }}</div>
</div>
```

## Testing

### Manual Testing

1. **View Inventory - All Items**:
   - Navigate to inventory page
   - Verify all items are shown (including those in storage)
   - Verify storage boxes are displayed with item counts
   - Verify items in storage have blue badge with box name

2. **Filter by Active Inventory**:
   - Click "Active Inventory" filter
   - Verify only items NOT in storage are shown
   - Verify storage boxes themselves are hidden

3. **Filter by Storage Box**:
   - Select a storage box from dropdown
   - Verify only items in that specific box are shown
   - Verify the heading shows the box name

4. **Visual Verification**:
   - Storage boxes should have distinct styling (blue gradient background)
   - Items in storage should have badge in top-left corner
   - Badge should show storage box name
   - Item count badge on storage boxes should be visible

5. **Responsive Design**:
   - Test on different screen sizes
   - Verify grid layout adjusts appropriately
   - Verify filters work on mobile

## Acceptance Criteria

- [ ] Inventory page displays ALL ItemUser records (active + in storage)
- [ ] Storage boxes are displayed as special cards with distinct styling
- [ ] Storage box cards show item count badge
- [ ] Storage box cards show modification date
- [ ] Items in storage display badge with storage box name
- [ ] Filter "All Items" shows everything (default)
- [ ] Filter "Active Inventory" shows only items not in storage
- [ ] Filter "By Storage Box" shows only items in selected box
- [ ] Stats show correct counts (total, active, in storage)
- [ ] "View Contents" button on storage box filters to that box
- [ ] Page is responsive and works on mobile
- [ ] No console errors in browser
- [ ] Item cards display correctly with or without storage badge

## Dependencies

- Task 2-1: Storage Box Database Setup (required)
- Task 2-2: Storage Box Import Integration (required)

## Next Task

**Task 2-4**: Deposit/Withdraw Workflow - Implement the deposit and withdraw functionality for moving items in/out of storage boxes.

## Related Files

- `src/Controller/InventoryController.php` (modified)
- `templates/inventory/index.html.twig` (modified)
- `assets/styles/app.css` (modified - optional)
- `templates/dashboard/index.html.twig` (modified - optional)