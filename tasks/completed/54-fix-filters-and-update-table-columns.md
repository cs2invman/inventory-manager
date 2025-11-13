# Fix Items Index Filters and Update Table Columns

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-13

## Overview

Fix the non-functional StatTrak Available and Souvenir Available filters on the items index page, add a new "Owned Inventory" filter to show only items the current user owns, and update the table columns to show buy orders, sell orders, and reorganize existing columns.

## Problem Statement

Currently, the items index page (`/items`) has several issues and missing features:

1. **Broken Filters**: The StatTrak Available and Souvenir Available checkbox filters do not return any results when checked
2. **Missing Owned Filter**: No way to filter items to show only what the current user owns in their inventory
3. **Missing Volume Data**: Buy Orders and Sell Orders are not displayed in the table
4. **Column Organization**: "Volume" column needs to be renamed to "Sold 30D" and the "Updated" column needs to be repositioned

The StatTrak and Souvenir filters are likely broken because:
- The checkbox values in the frontend are being sent as booleans (`true`/`false`)
- The controller converts them to strings `'1'` when checked
- The repository filter logic may not be correctly handling the parameters

## Requirements

### Functional Requirements

**Fix Existing Filters:**
- Fix StatTrak Available filter to correctly show items where `Item.stattrakAvailable = true`
- Fix Souvenir Available filter to correctly show items where `Item.souvenirAvailable = true`
- Ensure filters work independently and in combination with other filters

**Add Owned Inventory Filter:**
- Add checkbox labeled "Owned Inventory" next to StatTrak and Souvenir filters
- When checked, filter to show only items where current user has at least one `ItemUser` record
- Filter should work in combination with all other filters
- Should use efficient query (join to `item_user` table where `user_id = current_user`)

**Update Table Columns:**
The table should display columns in this exact order:
1. Image - No change
2. Name - No change (sortable)
3. Type - No change (sortable)
4. Category - No change (sortable)
5. Subcategory - No change (sortable)
6. Rarity - No change (sortable)
7. Price - No change (sortable)
8. **Buy Orders** - NEW column mapped to `ItemPrice.volumeBuyOrders` (sortable)
9. **Sell Orders** - NEW column mapped to `ItemPrice.volumeSellOrders` (sortable)
10. **Sold 7D** - NEW column mapped to `ItemPrice.sold7d` (sortable)
11. 7d Trend - No change (sortable)
12. **Sold 30D** - Rename from "Volume", maps to `ItemPrice.sold30d` (sortable)
13. 30d Trend - No change (sortable)
14. **Updated** - Move to position after Sell Orders (sortable)
15. Actions - No change (not sortable)

### Non-Functional Requirements

- Maintain existing performance characteristics (paginated queries, efficient joins)
- Use existing query optimization patterns (price-aware queries when needed)
- Maintain currency conversion for displayed prices
- Preserve existing Alpine.js frontend patterns
- All changes must work in Docker environment

## Technical Approach

### Root Cause Analysis (Filters)

The StatTrak and Souvenir filters are likely failing because:

1. **Frontend sends**: `filters.stattrak: true` (boolean)
2. **Controller receives**: `$request->query->get('stattrak', '')` returns `'1'` string when checked
3. **Controller checks**: `if ($stattrak === '1')` then adds `$filters['stattrakAvailable'] = true`
4. **Repository receives**: Boolean `true` value
5. **Query builder**: Uses `i.stattrakAvailable = :stattrakAvailable` with boolean parameter
6. **Potential issue**: Parameter binding or query logic may be filtering incorrectly

**Diagnosis needed**: Check if the query is actually being constructed correctly and if parameters are being bound properly. The logic looks correct, so there may be a data issue or the filter is too restrictive when combined with other filters.

### Database Changes

**No migrations needed** - All required fields already exist:
- `Item.stattrakAvailable` (boolean)
- `Item.souvenirAvailable` (boolean)
- `ItemPrice.volumeBuyOrders` (integer)
- `ItemPrice.volumeSellOrders` (integer)
- `ItemPrice.sold7d` (integer)
- `ItemPrice.sold30d` (integer)
- `ItemUser.user_id` (foreign key to User)

### Repository Layer Changes

**File**: `src/Repository/ItemRepository.php`

**1. Fix StatTrak/Souvenir Filter Logic** (lines 462-470)

Current code looks correct, but add debugging or verify the filter is actually applied:

```php
// Boolean filters
if (isset($filters['stattrakAvailable']) && $filters['stattrakAvailable'] !== '') {
    $qb->andWhere('i.stattrakAvailable = :stattrakAvailable')
       ->setParameter('stattrakAvailable', (bool) $filters['stattrakAvailable']);
}

if (isset($filters['souvenirAvailable']) && $filters['souvenirAvailable'] !== '') {
    $qb->andWhere('i.souvenirAvailable = :souvenirAvailable')
       ->setParameter('souvenirAvailable', (bool) $filters['souvenirAvailable']);
}
```

**Issue to investigate**:
- Verify that items in the database actually have `stattrak_available = 1` or `souvenir_available = 1`
- Run raw SQL query to confirm data exists: `SELECT COUNT(*) FROM item WHERE stattrak_available = 1 AND active = 1;`
- If no data exists, this is a data problem, not a code problem

**2. Add Owned Inventory Filter Logic** (add after souvenirAvailable filter, ~line 471)

```php
// Owned inventory filter - join to item_user table
if (isset($filters['ownedOnly']) && $filters['ownedOnly'] === true) {
    $qb->innerJoin('App\Entity\ItemUser', 'iu', 'WITH', 'iu.item = i')
       ->andWhere('iu.user = :currentUser')
       ->setParameter('currentUser', $filters['currentUser']);
    // Use DISTINCT to avoid duplicates if user has multiple instances
    $qb->distinct();
}
```

**3. Update findItemIdsSortedByPrice for SQL-based queries**

Find the `findItemIdsSortedByPrice()` method and:
- Add owned inventory filter support (similar SQL join)
- Ensure the method handles the new filter parameter
- Use LEFT JOIN and WHERE clause for user filter in raw SQL

**4. Update countWithPriceFilters for owned filter**

In `countWithPriceFilters()` method (~line 334), add SQL join for owned filter:

```php
// After line 378, add owned filter logic
if (isset($filters['ownedOnly']) && $filters['ownedOnly'] === true && isset($filters['currentUser'])) {
    $whereClauses[] = 'EXISTS (SELECT 1 FROM item_user iu WHERE iu.item_id = i.id AND iu.user_id = :currentUserId)';
    $params['currentUserId'] = $filters['currentUser']->getId();
}
```

**5. Update findWithLatestPriceAndTrend data structure**

The method already fetches latest price data. Ensure the returned array includes:
- `volumeBuyOrders` - map from `ItemPrice.volumeBuyOrders`
- `volumeSellOrders` - map from `ItemPrice.volumeSellOrders`
- `sold7d` - already exists
- `sold30d` - map from `ItemPrice.sold30d`

Check the SQL query in this method (around line 499+) and ensure all these fields are selected.

### Service Layer Changes

**File**: `src/Service/ItemTableService.php`

**1. Update getItemsTableData() to pass user context** (line 26-96)

The service needs to pass the current user to the repository for the owned filter:

```php
public function getItemsTableData(
    array $filters = [],
    string $sortBy = 'name',
    string $sortDirection = 'ASC',
    int $page = 1,
    int $perPage = 25,
    ?User $currentUser = null  // Add parameter
): array {
    // Pass user to filters array
    if ($currentUser !== null && isset($filters['ownedOnly']) && $filters['ownedOnly']) {
        $filters['currentUser'] = $currentUser;
    }

    // ... rest of method unchanged
}
```

**2. Update sorting validation** (line 76)

Add new sortable columns:

```php
$validSortColumns = [
    'name', 'category', 'subcategory', 'type', 'rarity',
    'price', 'volume', 'updatedAt', 'trend7d', 'trend30d',
    'volumeBuyOrders', 'volumeSellOrders', 'sold7d', 'sold30d'  // Add these
];
```

**3. Update getSortValue() for new columns** (line 170-179)

```php
private function getSortValue(array $itemData, string $sortBy): float|int|null
{
    return match ($sortBy) {
        'price' => $itemData['latestPrice'],
        'volume', 'sold30d' => $itemData['sold30d'],  // Map both to sold30d
        'sold7d' => $itemData['sold7d'],
        'volumeBuyOrders' => $itemData['volumeBuyOrders'],
        'volumeSellOrders' => $itemData['volumeSellOrders'],
        'trend7d' => $itemData['trend7d'],
        'trend30d' => $itemData['trend30d'],
        default => null,
    };
}
```

### Controller Changes

**File**: `src/Controller/ItemsController.php`

**1. Update data() method to extract owned filter** (line 58-74)

```php
public function data(Request $request): JsonResponse
{
    // Extract query parameters
    $search = trim($request->query->get('search', ''));
    $category = $request->query->get('category', '');
    $subcategory = $request->query->get('subcategory', '');
    $type = $request->query->get('type', '');
    $rarity = $request->query->get('rarity', '');
    $stattrak = $request->query->get('stattrak', '');
    $souvenir = $request->query->get('souvenir', '');
    $ownedOnly = $request->query->get('ownedOnly', '');  // Add this
    $minPrice = $request->query->get('minPrice', '');
    $maxPrice = $request->query->get('maxPrice', '');
    // ... rest unchanged
}
```

**2. Update filter building** (line 96-124)

```php
// Build filters array
$filters = ['active' => true];

if (strlen($search) >= 2) {
    $filters['search'] = $search;
}
// ... existing filters ...

if ($stattrak === '1') {
    $filters['stattrakAvailable'] = true;
}
if ($souvenir === '1') {
    $filters['souvenirAvailable'] = true;
}
if ($ownedOnly === '1') {  // Add this
    $filters['ownedOnly'] = true;
}

// ... price filters ...
```

**3. Update service call to pass user** (line 142-149)

```php
try {
    $result = $this->itemTableService->getItemsTableData(
        $filters,
        $sortBy,
        strtoupper($sortDirection),
        $page,
        $perPage,
        $this->getUser()  // Add current user
    );
} catch (\Exception $e) {
    // ... error handling
}
```

**4. Update formatItemForJson() to include new fields** (line 199-230)

```php
private function formatItemForJson(
    array $itemData,
    string $currencySymbol,
    ?float $exchangeRate = null
): array {
    $item = $itemData['item'];
    $price = $itemData['latestPrice'];

    if ($price !== null && $exchangeRate !== null) {
        $price = $price * $exchangeRate;
    }

    return [
        'id' => $item->getId(),
        'name' => $item->getName(),
        'marketName' => $item->getMarketName(),
        'imageUrl' => $item->getImageUrl(),
        'type' => $item->getType(),
        'category' => $item->getCategory(),
        'subcategory' => $item->getSubcategory(),
        'rarity' => $item->getRarity(),
        'rarityColor' => $item->getRarityColor(),
        'stattrakAvailable' => $item->isStattrakAvailable(),
        'souvenirAvailable' => $item->isSouvenirAvailable(),
        'price' => $price !== null ? round($price, 2) : null,
        'volume' => $itemData['sold30d'],  // Change from 'volume' to 'sold30d'
        'sold30d' => $itemData['sold30d'],  // Add explicit sold30d
        'sold7d' => $itemData['sold7d'],    // Add sold7d
        'volumeBuyOrders' => $itemData['volumeBuyOrders'],    // Add buy orders
        'volumeSellOrders' => $itemData['volumeSellOrders'],  // Add sell orders
        'updatedAt' => $itemData['priceDate']?->format('c'),
        'trend7d' => $itemData['trend7d'] !== null ? round($itemData['trend7d'], 1) : null,
        'trend30d' => $itemData['trend30d'] !== null ? round($itemData['trend30d'], 1) : null,
        'currencySymbol' => $currencySymbol,
    ];
}
```

**5. Update validSortColumns** (line 76)

```php
$validSortColumns = [
    'name', 'category', 'subcategory', 'type', 'rarity',
    'price', 'volume', 'sold30d', 'sold7d', 'volumeBuyOrders', 'volumeSellOrders',
    'updatedAt', 'trend7d', 'trend30d'
];
```

### Frontend Changes

**File**: `templates/items/index.html.twig`

**1. Add Owned Inventory checkbox filter** (after line 154, before Clear Filters button)

```twig
<label class="flex items-center cursor-pointer">
    <input
        type="checkbox"
        x-model="filters.ownedOnly"
        @change="onFilterChange()"
        class="w-4 h-4 text-cs2-orange bg-gray-800 border-gray-600 rounded focus:ring-cs2-orange"
    >
    <span class="ml-2 text-sm text-gray-300">Owned Inventory</span>
</label>
```

**2. Update table headers** (lines 183-296)

Replace the existing `<thead>` section with the new column order:

```twig
<thead class="bg-gray-900 border-b border-gray-700">
    <tr>
        <th class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Image</th>

        <!-- Name (sortable) -->
        <th @click="sortByColumn('name')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Name</span>
                <svg x-show="sortBy === 'name'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- Type (sortable) -->
        <th @click="sortByColumn('type')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Type</span>
                <svg x-show="sortBy === 'type'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- Category (sortable) -->
        <th @click="sortByColumn('category')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Category</span>
                <svg x-show="sortBy === 'category'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- Subcategory (sortable) -->
        <th @click="sortByColumn('subcategory')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Subcategory</span>
                <svg x-show="sortBy === 'subcategory'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- Rarity (sortable) -->
        <th @click="sortByColumn('rarity')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Rarity</span>
                <svg x-show="sortBy === 'rarity'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- Price (sortable) -->
        <th @click="sortByColumn('price')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Price</span>
                <svg x-show="sortBy === 'price'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- Buy Orders (sortable) - NEW -->
        <th @click="sortByColumn('volumeBuyOrders')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Buy Orders</span>
                <svg x-show="sortBy === 'volumeBuyOrders'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- Sell Orders (sortable) - NEW -->
        <th @click="sortByColumn('volumeSellOrders')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Sell Orders</span>
                <svg x-show="sortBy === 'volumeSellOrders'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- Updated (sortable) - MOVED -->
        <th @click="sortByColumn('updatedAt')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Updated</span>
                <svg x-show="sortBy === 'updatedAt'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- Sold 7D (sortable) - NEW -->
        <th @click="sortByColumn('sold7d')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Sold 7D</span>
                <svg x-show="sortBy === 'sold7d'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- 7d Trend (sortable) -->
        <th @click="sortByColumn('trend7d')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>7d Trend</span>
                <svg x-show="sortBy === 'trend7d'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- Sold 30D (sortable) - RENAMED from "Volume" -->
        <th @click="sortByColumn('sold30d')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>Sold 30D</span>
                <svg x-show="sortBy === 'sold30d'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <!-- 30d Trend (sortable) -->
        <th @click="sortByColumn('trend30d')" class="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider cursor-pointer hover:text-cs2-orange">
            <div class="flex items-center space-x-1">
                <span>30d Trend</span>
                <svg x-show="sortBy === 'trend30d'" class="h-4 w-4" :class="sortDirection === 'asc' ? 'transform rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"></path>
                </svg>
            </div>
        </th>

        <th class="px-4 py-3 text-center text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
    </tr>
</thead>
```

**3. Update table body rows** (lines 312-376)

Update the item row template to match new column order:

```twig
<template x-for="item in items" :key="item.id">
    <tr class="hover:bg-gray-750 transition-colors">
        <!-- Image -->
        <td class="px-4 py-3">
            <img :src="item.imageUrl" :alt="item.name" class="h-12 w-auto">
        </td>

        <!-- Name with Quality Badge -->
        <td class="px-4 py-3">
            <div class="flex items-center space-x-2">
                <span class="font-medium text-white" x-text="item.name"></span>
                <span x-show="item.stattrakAvailable" class="px-2 py-0.5 text-xs bg-orange-600 text-white rounded">ST</span>
                <span x-show="item.souvenirAvailable" class="px-2 py-0.5 text-xs bg-yellow-600 text-white rounded">SV</span>
            </div>
        </td>

        <!-- Type -->
        <td class="px-4 py-3 text-sm text-gray-300" x-text="item.type"></td>

        <!-- Category -->
        <td class="px-4 py-3 text-sm text-gray-300" x-text="item.category"></td>

        <!-- Subcategory -->
        <td class="px-4 py-3 text-sm text-gray-300" x-text="item.subcategory || '--'"></td>

        <!-- Rarity with Color Badge -->
        <td class="px-4 py-3">
            <span
                class="px-2 py-1 text-xs font-medium rounded"
                :style="`background-color: ${item.rarityColor}20; color: ${item.rarityColor}; border: 1px solid ${item.rarityColor}40;`"
                x-text="item.rarity || '--'"
            ></span>
        </td>

        <!-- Price -->
        <td class="px-4 py-3 text-sm font-medium text-white" x-text="formatPrice(item.price, item.currencySymbol)"></td>

        <!-- Buy Orders - NEW -->
        <td class="px-4 py-3 text-sm text-gray-300" x-text="formatVolume(item.volumeBuyOrders)"></td>

        <!-- Sell Orders - NEW -->
        <td class="px-4 py-3 text-sm text-gray-300" x-text="formatVolume(item.volumeSellOrders)"></td>

        <!-- Updated - MOVED -->
        <td class="px-4 py-3 text-sm text-gray-400" x-text="formatRelativeTime(item.updatedAt)"></td>

        <!-- Sold 7D - NEW -->
        <td class="px-4 py-3 text-sm text-gray-300" x-text="formatVolume(item.sold7d)"></td>

        <!-- 7 Days Trend -->
        <td class="px-4 py-3 text-sm font-medium" :class="formatTrend(item.trend7d).color" x-text="formatTrend(item.trend7d).text"></td>

        <!-- Sold 30D - RENAMED from Volume -->
        <td class="px-4 py-3 text-sm text-gray-300" x-text="formatVolume(item.sold30d)"></td>

        <!-- 30 Days Trend -->
        <td class="px-4 py-3 text-sm font-medium" :class="formatTrend(item.trend30d).color" x-text="formatTrend(item.trend30d).text"></td>

        <!-- Actions -->
        <td class="px-4 py-3 text-center">
            <a
                :href="`https://steamcommunity.com/market/listings/730/${encodeURIComponent(item.marketName)}`"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center justify-center bg-gray-700 hover:bg-steam-blue p-2 rounded transition-colors"
                title="View on Steam Market"
            >
                <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M11.979 0C5.678 0 .511 4.86.022 11.037l6.432 2.658c.545-.371 1.203-.59 1.912-.59.063 0 .125.004.188.006l2.861-4.142V8.91c0-2.495 2.028-4.524 4.524-4.524 2.494 0 4.524 2.031 4.524 4.527s-2.03 4.525-4.524 4.525h-.105l-4.076 2.911c0 .052.004.105.004.159 0 1.875-1.515 3.396-3.39 3.396-1.635 0-3.016-1.173-3.331-2.727L.436 15.27C1.862 20.307 6.486 24 11.979 24c6.627 0 11.999-5.373 11.999-12S18.605 0 11.979 0zM7.54 18.21l-1.473-.61c.262.543.714.999 1.314 1.25 1.297.539 2.793-.076 3.332-1.375.263-.63.264-1.319.005-1.949s-.75-1.121-1.377-1.383c-.624-.26-1.29-.249-1.878-.03l1.523.63c.956.4 1.409 1.5 1.009 2.455-.397.957-1.497 1.41-2.454 1.012H7.54zm11.415-9.303c0-1.662-1.353-3.015-3.015-3.015-1.665 0-3.015 1.353-3.015 3.015 0 1.665 1.35 3.015 3.015 3.015 1.663 0 3.015-1.35 3.015-3.015zm-5.273-.005c0-1.252 1.013-2.266 2.265-2.266 1.249 0 2.266 1.014 2.266 2.266 0 1.251-1.017 2.265-2.266 2.265-1.253 0-2.265-1.014-2.265-2.265z" />
                </svg>
            </a>
        </td>
    </tr>
</template>
```

**4. Update Alpine.js state** (line 447-456)

```javascript
filters: {
    search: '',
    category: '',
    subcategory: '',
    type: '',
    rarity: '',
    stattrak: false,
    souvenir: false,
    ownedOnly: false,  // Add this
    minPrice: '',
    maxPrice: ''
},
```

**5. Update loadItems() method** (line 476-520)

```javascript
async loadItems() {
    this.loading = true;

    const params = new URLSearchParams({
        search: this.filters.search,
        category: this.filters.category,
        subcategory: this.filters.subcategory,
        type: this.filters.type,
        rarity: this.filters.rarity,
        stattrak: this.filters.stattrak ? '1' : '',
        souvenir: this.filters.souvenir ? '1' : '',
        ownedOnly: this.filters.ownedOnly ? '1' : '',  // Add this
        minPrice: this.filters.minPrice,
        maxPrice: this.filters.maxPrice,
        sortBy: this.sortBy,
        sortDirection: this.sortDirection,
        page: this.page,
        perPage: this.perPage
    });

    // Remove empty params
    for (let [key, value] of Array.from(params.entries())) {
        if (!value) params.delete(key);
    }

    // ... rest unchanged
}
```

**6. Update clearFilters() method** (line 535-549)

```javascript
clearFilters() {
    this.filters = {
        search: '',
        category: '',
        subcategory: '',
        type: '',
        rarity: '',
        stattrak: false,
        souvenir: false,
        ownedOnly: false,  // Add this
        minPrice: '',
        maxPrice: ''
    };
    this.page = 1;
    this.loadItems();
},
```

**7. Update empty state colspan** (line 302)

Change from `colspan="12"` to `colspan="15"` to account for the new columns.

### Asset Rebuilding

After making template changes, rebuild frontend assets:

```bash
docker compose run --rm node npm run build
```

## Implementation Steps

1. **Diagnose StatTrak/Souvenir filter issue**
   - Run raw SQL query to check if items with `stattrak_available = 1` or `souvenir_available = 1` exist
   - Use query: `docker compose exec php php bin/console dbal:run-sql "SELECT COUNT(*) as stattrak_count FROM item WHERE stattrak_available = 1 AND active = 1"`
   - Use query: `docker compose exec php php bin/console dbal:run-sql "SELECT COUNT(*) as souvenir_count FROM item WHERE souvenir_available = 1 AND active = 1"`
   - If count is 0, this is a data issue, not a code bug

2. **Update ItemRepository.php**
   - Verify `applyFilters()` method logic for StatTrak/Souvenir (lines 462-470)
   - Add owned inventory filter logic to `applyFilters()` method
   - Update `countWithPriceFilters()` to support owned filter
   - Find and update `findItemIdsSortedByPrice()` to support owned filter
   - Verify `findWithLatestPriceAndTrend()` returns all required fields (volumeBuyOrders, volumeSellOrders, sold7d, sold30d)

3. **Update ItemTableService.php**
   - Add `?User $currentUser = null` parameter to `getItemsTableData()`
   - Pass user to filters array when ownedOnly is true
   - Update `$validSortColumns` array to include new columns
   - Update `getSortValue()` match statement for new sort options
   - Update sort handling in `getItemsWithTrendSorting()` if needed

4. **Update ItemsController.php**
   - Extract `ownedOnly` parameter from request query
   - Add `ownedOnly` to filters array when `'1'`
   - Pass `$this->getUser()` to service call
   - Update `formatItemForJson()` to include new fields
   - Update `$validSortColumns` array

5. **Update items/index.html.twig**
   - Add "Owned Inventory" checkbox filter in filter section
   - Replace `<thead>` section with new column order
   - Replace item row `<template>` with new column order
   - Add `ownedOnly: false` to Alpine.js filters state
   - Add `ownedOnly` parameter to `loadItems()` method
   - Add `ownedOnly` to `clearFilters()` method
   - Update empty state colspan from 12 to 15

6. **Rebuild frontend assets**
   - Run `docker compose run --rm node npm run build`
   - Verify Tailwind scanned new classes
   - Check browser console for any JavaScript errors

7. **Test all filters individually**
   - Test StatTrak Available filter (should show items with ST badge)
   - Test Souvenir Available filter (should show items with SV badge)
   - Test Owned Inventory filter (should show only owned items)
   - Test price range filters
   - Test text search

8. **Test filter combinations**
   - Test StatTrak + Category filter
   - Test Owned + Type filter
   - Test all three checkboxes together
   - Test filters with price range

9. **Test all column sorting**
   - Test sorting by each column (Name, Type, Category, etc.)
   - Test ascending and descending for each
   - Verify Buy Orders and Sell Orders sort correctly
   - Verify Sold 7D and Sold 30D sort correctly
   - Verify Updated column position is correct

10. **Test pagination with filters**
    - Apply filter, verify pagination updates
    - Navigate between pages, verify filter persists
    - Change perPage, verify filter persists

## Edge Cases & Error Handling

**Data Issues:**
- If no items have `stattrak_available = 1` in database, filter will return 0 results (this is correct behavior, not a bug)
- If user has no inventory, owned filter will return 0 results
- Handle null values for volume fields (display as `--`)

**Query Performance:**
- Owned inventory filter requires JOIN to `item_user` table - use DISTINCT to avoid duplicates
- Ensure indexes exist on `item_user.user_id` and `item_user.item_id` for performance
- Large result sets with owned filter may be slower - this is expected

**Frontend:**
- Handle missing data gracefully (formatVolume returns `--` for null)
- Ensure Alpine.js state updates correctly when filters change
- Clear filters should reset all checkbox states

**Sorting:**
- When sorting by new columns (Buy Orders, Sell Orders, Sold 7D, Sold 30D), handle null values (send to end)
- Verify sort direction indicator displays correctly
- Price-based sorting may need separate query path (existing pattern)

**Filter Combinations:**
- Owned + StatTrak should work (user owns items that have ST available)
- All filters should be AND logic, not OR
- Empty filter values should be ignored, not treated as 0

## Acceptance Criteria

- [ ] StatTrak Available filter returns items where `Item.stattrakAvailable = true`
- [ ] Souvenir Available filter returns items where `Item.souvenirAvailable = true`
- [ ] Owned Inventory filter returns only items where user has ItemUser records
- [ ] All three checkbox filters work independently
- [ ] All three checkbox filters work in combination
- [ ] Filters work in combination with dropdowns and price range
- [ ] Table displays columns in correct order: Image, Name, Type, Category, Subcategory, Rarity, Price, Buy Orders, Sell Orders, Updated, Sold 7D, 7d Trend, Sold 30D, 30d Trend, Actions
- [ ] "Volume" column is renamed to "Sold 30D"
- [ ] Buy Orders column displays `ItemPrice.volumeBuyOrders`
- [ ] Sell Orders column displays `ItemPrice.volumeSellOrders`
- [ ] Sold 7D column displays `ItemPrice.sold7d`
- [ ] Updated column is positioned after Sell Orders
- [ ] All columns are sortable (except Image and Actions)
- [ ] Sorting works correctly for all new columns
- [ ] Null values display as `--` in volume columns
- [ ] Pagination works with all filters
- [ ] Clear Filters button resets all filters including new ones
- [ ] Frontend assets rebuild successfully
- [ ] No JavaScript console errors
- [ ] No PHP errors in logs
- [ ] Manual verification: Apply StatTrak filter, see only ST items
- [ ] Manual verification: Apply Souvenir filter, see only Souvenir items
- [ ] Manual verification: Apply Owned filter, see only owned items
- [ ] Manual verification: Sort by Buy Orders, verify order is correct
- [ ] Manual verification: Sort by Sold 7D, verify order is correct

## Notes & Considerations

### StatTrak/Souvenir Filter Investigation

The existing code looks correct. The issue is likely one of:
1. **Data issue**: No items in database have `stattrak_available = 1` or `souvenir_available = 1`
2. **Combination filter issue**: Filter works alone but fails when combined with price filter or other filters
3. **SQL parameter binding**: Doctrine may be binding boolean differently than expected

**Resolution**: Check database first, then verify filter combinations.

### Performance Considerations

- Owned inventory filter requires JOIN, which may slow down queries for users with large inventories
- Consider adding index on `item_user(user_id, item_id)` if not already exists
- DISTINCT clause may impact performance on large result sets
- Existing price-based query optimization should handle new sort columns

### Testing Notes

This project does not use automated tests. Manual testing must cover:
- All filter permutations (3 checkboxes = 8 combinations)
- All sorting columns (15 columns)
- Pagination with filters applied
- Edge cases (no results, null values, empty inventory)

## Related Tasks

- None (standalone task)
