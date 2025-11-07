# Inventory Item Grouping and Aggregate Pricing

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Medium
**Created**: 2025-11-07

## Overview

Enhance the inventory view to group non-unique items (cases, capsules, stickers, etc.) and display them with aggregate pricing. Instead of showing multiple individual cards for identical items, show a single card with quantity count and total value. Sort inventory by aggregate price rather than unit price to prioritize high-value stacks.

## Problem Statement

Currently, the inventory displays every item individually, which creates clutter when users have many duplicates of cases, capsules, or stickers. For example, if a user has 50 Operation Riptide Cases, they see 50 separate cards. This makes it difficult to:
- Get an overview of the inventory
- Identify which item types represent the most value (a cheap case owned 100x might be worth more than an expensive skin owned 1x)
- Navigate the inventory efficiently

## Requirements

### Functional Requirements
- Group identical non-unique items into a single card showing quantity
- Display pattern numbers (paintSeed) on item cards for items that have patterns (these items will NOT be grouped)
- Show "Quantity × Unit Price = Aggregate Price" on grouped item cards
- Sort inventory by aggregate price (total value) instead of unit price
- Apply grouping to both main inventory view and storage box views
- Items that should be grouped: Cases, Capsules, Stickers, Patches, Graffiti, Music Kits, Charms (without patterns), and other non-customizable items
- Items that should NOT be grouped: Items with pattern indices (paintSeed/patternIndex), items with float values, items with stickers applied, items with name tags, StatTrak items, Souvenir items, or any items with unique properties

### Non-Functional Requirements
- Maintain existing performance (no significant query overhead)
- Keep the current card design, only modify the price display area
- Preserve all existing functionality (filters, storage box badges, Steam links, etc.)
- Ensure grouped items display correctly in both main inventory and storage box views

## Technical Approach

### Grouping Logic (InventoryController.php)

**Hardcoded grouping rules:**
```php
private function isGroupableItem(Item $item): bool
{
    $name = strtolower($item->getName());

    // Group if item name contains these keywords AND has no customizations
    $groupableKeywords = [
        'case',
        'capsule',
        'sticker |',         // Stickers
        'patch |',           // Patches
        'graffiti |',
        'music kit |',
        'sealed graffiti |',
        'charm |',           // Charms/Keychains (only those without patterns)
    ];

    foreach ($groupableKeywords as $keyword) {
        if (str_contains($name, $keyword)) {
            return true;
        }
    }

    return false;
}

private function hasCustomizations(ItemUser $itemUser): bool
{
    // Don't group if item has any unique properties
    return $itemUser->getFloatValue() !== null
        || $itemUser->getPaintSeed() !== null      // Pattern index
        || $itemUser->getPatternIndex() !== null   // Alternative pattern field
        || $itemUser->getStickers() !== null       // Items with stickers applied
        || $itemUser->getNameTag() !== null        // Custom name tags
        || $itemUser->getIsStattrak()              // StatTrak items
        || $itemUser->getIsSouvenir();             // Souvenir items
    // Note: keychain/charm is NOT checked here - charms can be grouped if they have no other unique properties
}
```

**Grouping algorithm in `index()` method:**
```php
// After fetching $filteredItems and calculating prices:
$groupedItems = [];
$ungroupedItems = [];

foreach ($itemsWithPrices as $itemData) {
    $itemUser = $itemData['itemUser'];
    $item = $itemUser->getItem();

    if ($this->isGroupableItem($item) && !$this->hasCustomizations($itemUser)) {
        // Group by item ID
        $itemId = $item->getId();

        if (!isset($groupedItems[$itemId])) {
            $groupedItems[$itemId] = [
                'item' => $item,
                'latestPrice' => $itemData['latestPrice'],
                'unitPrice' => $itemData['priceValue'],
                'items' => [],
                'quantity' => 0,
                'aggregatePrice' => 0.0,
            ];
        }

        $groupedItems[$itemId]['items'][] = $itemUser;
        $groupedItems[$itemId]['quantity']++;
        $groupedItems[$itemId]['aggregatePrice'] += $itemData['itemTotalValue'];
    } else {
        // Keep as individual item
        $itemData['quantity'] = 1;
        $itemData['aggregatePrice'] = $itemData['itemTotalValue'];
        $ungroupedItems[] = $itemData;
    }
}

// Convert grouped items to same format as ungrouped
$finalGroupedItems = [];
foreach ($groupedItems as $groupData) {
    $finalGroupedItems[] = [
        'item' => $groupData['item'],
        'latestPrice' => $groupData['latestPrice'],
        'priceValue' => $groupData['unitPrice'],
        'quantity' => $groupData['quantity'],
        'aggregatePrice' => $groupData['aggregatePrice'],
        'isGrouped' => true,
        // For grouped items, we'll use the first ItemUser for display purposes
        'itemUser' => $groupData['items'][0],
        'stickersWithPrices' => [], // Grouped items won't have stickers
        'keychainWithPrice' => null,
    ];
}

// Merge grouped and ungrouped items
$allItems = array_merge($finalGroupedItems, $ungroupedItems);

// Sort by aggregate price descending
usort($allItems, fn($a, $b) => $b['aggregatePrice'] <=> $a['aggregatePrice']);
```

### Template Changes (item_card.html.twig)

**Update the price display section (lines 154-181):**

Replace the current "Wear/Float and Price Row" with:
```twig
{# Item Details #}
<div class="space-y-2">
    {# Wear/Float and Pattern Row (for non-grouped items) #}
    {% if quantity|default(1) == 1 %}
        <div class="flex items-center justify-between text-xs">
            {# Left: Wear, Float, and Pattern #}
            <div class="flex items-center gap-2">
                {% if itemUser.wearCategory %}
                    <span class="bg-gray-700 text-gray-300 px-2 py-0.5 rounded">{{ itemUser.wearCategory }}</span>
                {% endif %}
                {% if itemUser.floatValue %}
                    <span class="text-gray-300 font-mono ml-1">{{ itemUser.floatValue }}</span>
                {% endif %}
                {% if itemUser.paintSeed %}
                    <span class="text-gray-300 font-mono ml-1">#{{ itemUser.paintSeed }}</span>
                {% endif %}
            </div>

            {# Right: Price #}
            <span class="text-green-400 font-bold text-lg">
                {% if latestPrice and priceValue is not null %}
                    {{ priceValue|format_price(
                        userConfig ? userConfig.preferredCurrency : 'USD',
                        userConfig ? userConfig.cadExchangeRate : 1.0
                    ) }}
                {% else %}
                    <span class="text-gray-500">N/A</span>
                {% endif %}
            </span>
        </div>
    {% else %}
        {# Grouped items: Show quantity × unit price = aggregate #}
        <div class="flex items-center justify-between text-xs">
            {# Left: Quantity × Unit Price #}
            <div class="flex items-center gap-1 text-gray-300">
                <span class="font-bold text-white">{{ quantity }}</span>
                <span>×</span>
                <span>
                    {% if latestPrice and priceValue is not null %}
                        {{ priceValue|format_price(
                            userConfig ? userConfig.preferredCurrency : 'USD',
                            userConfig ? userConfig.cadExchangeRate : 1.0
                        ) }}
                    {% else %}
                        <span class="text-gray-500">N/A</span>
                    {% endif %}
                </span>
            </div>

            {# Right: Aggregate Price #}
            <span class="text-green-400 font-bold text-lg">
                {% if latestPrice and aggregatePrice|default(priceValue) is not null %}
                    {{ aggregatePrice|default(priceValue)|format_price(
                        userConfig ? userConfig.preferredCurrency : 'USD',
                        userConfig ? userConfig.cadExchangeRate : 1.0
                    ) }}
                {% else %}
                    <span class="text-gray-500">N/A</span>
                {% endif %}
            </span>
        </div>
    {% endif %}

    {# Name Tag (unchanged) #}
    {% if itemUser.nameTag %}
        <div class="pt-2 border-t border-gray-700">
            <p class="text-xs text-gray-400">Name Tag:</p>
            <p class="text-xs text-yellow-400 italic">"{{ itemUser.nameTag }}"</p>
        </div>
    {% endif %}
</div>
```

**Add optional quantity badge for grouped items (after line 63):**
```twig
{# Quantity Badge for Grouped Items #}
{% if quantity|default(1) > 1 %}
    <div class="absolute top-2 right-2 z-10">
        <span class="inline-flex items-center justify-center bg-cs2-orange text-white px-3 py-1 rounded-full text-sm font-bold shadow-lg min-w-[3rem]">
            ×{{ quantity }}
        </span>
    </div>
{% endif %}
```

### Controller Updates

**InventoryController.php changes:**
1. Add private helper methods `isGroupableItem()` and `hasCustomizations()`
2. Update `index()` method to group items after fetching
3. Change sorting from `$b['priceValue']` to `$b['aggregatePrice']`
4. Pass `quantity` and `aggregatePrice` to template for each item

### Template Updates (inventory/index.html.twig)

Update the item_card embedding to pass the new fields:
```twig
{% embed 'components/item_card.html.twig' with {
    itemUser: itemData.itemUser,
    item: itemData.itemUser.item,
    latestPrice: itemData.latestPrice,
    priceValue: itemData.priceValue,
    stickersWithPrices: itemData.stickersWithPrices,
    keychainWithPrice: itemData.keychainWithPrice,
    quantity: itemData.quantity|default(1),
    aggregatePrice: itemData.aggregatePrice|default(itemData.priceValue),
    mode: 'with-storage-badge',
    userConfig: userConfig
} %}
{% endembed %}
```

## Implementation Steps

1. **Update InventoryController.php**
   - Add `isGroupableItem(Item $item): bool` private method with hardcoded keyword checks (includes "charm |" for keychains)
   - Add `hasCustomizations(ItemUser $itemUser): bool` private method that checks:
     - paintSeed (pattern index)
     - patternIndex (alternative pattern field)
     - floatValue
     - stickers
     - nameTag
     - isStattrak
     - isSouvenir
     - Note: Does NOT check keychain/charm - they can be grouped if no other customizations
   - Modify `index()` method to group items after price calculation but before rendering
   - Update sorting logic to use `aggregatePrice` instead of `priceValue`
   - Ensure `quantity` and `aggregatePrice` are passed to template for all items

2. **Update item_card.html.twig component**
   - Add `quantity` and `aggregatePrice` as optional parameters in component documentation
   - Add quantity badge in top-right corner when `quantity > 1`
   - Replace the price display section to show two different layouts:
     - Single items: Show wear/float/pattern on left, unit price on right (current behavior)
     - Grouped items: Show "Quantity × Unit Price" on left, aggregate price on right
   - Add pattern number display using `itemUser.paintSeed` field (for items that have it)

3. **Update inventory/index.html.twig template**
   - Pass `quantity` and `aggregatePrice` fields when embedding item_card component
   - No other changes needed (grouping logic is in controller)

4. **Storage box views** (deposit_preview.html.twig, withdraw_preview.html.twig)
   - These views already use the item_card component
   - They will automatically benefit from the updated component
   - No additional changes needed

5. **Test grouping logic**
   - Verify cases are grouped correctly
   - Verify capsules are grouped correctly
   - Verify stickers and patches are grouped correctly
   - Verify graffiti and music kits are grouped correctly
   - Verify charms (keychains) without patterns are grouped correctly
   - Verify items with pattern indices (paintSeed or patternIndex) are NOT grouped
   - Verify items with float values are NOT grouped
   - Verify items with stickers applied are NOT grouped
   - Verify charms with pattern indices are NOT grouped
   - Verify items with name tags are NOT grouped
   - Verify StatTrak items are NOT grouped
   - Verify Souvenir items are NOT grouped

6. **Test sorting**
   - Verify inventory sorts by aggregate price (descending)
   - Verify a stack of 100 cheap cases appears higher than 1 expensive case if total value is higher
   - Verify storage box views also sort by aggregate price

7. **Test display**
   - Verify quantity badge appears on grouped items
   - Verify "Quantity × Unit Price = Total" displays correctly
   - Verify pattern numbers show on items that have them
   - Verify ungrouped items still show wear/float/pattern as before
   - Verify currency formatting works correctly with user preferences

## Edge Cases & Error Handling

### Edge Case 1: Items with same name but different types
- **Scenario**: "Sticker | Fnatic | Cologne 2015" vs "Patch | Fnatic"
- **Handling**: Different keywords ('sticker |' vs 'patch |'), so won't conflict. They group separately by item ID.

### Edge Case 2: StatTrak cases or Souvenir capsules
- **Scenario**: A case with StatTrak flag (shouldn't exist, but just in case)
- **Handling**: `hasCustomizations()` checks for `isStattrak` and `isSouvenir`, so these won't be grouped.

### Edge Case 3: Item with zero or null price
- **Scenario**: A case with no price data
- **Handling**: Aggregate price calculation handles this (0.0), displays "N/A" in template. Sorting still works (null-safe comparison).

### Edge Case 4: Single item that would be groupable
- **Scenario**: User has exactly 1 case
- **Handling**: Still creates a "group" with quantity=1. Template detects `quantity == 1` and displays as single item (no visual difference).

### Edge Case 5: All items in inventory are grouped
- **Scenario**: User only has cases and capsules, no skins
- **Handling**: Works normally. All items shown as grouped cards with quantities.

### Edge Case 6: Pattern number on grouped items
- **Scenario**: Trying to group items that have pattern indices (paintSeed or patternIndex)
- **Handling**: Items with `paintSeed` or `patternIndex` are explicitly checked in `hasCustomizations()` and will NOT be grouped. Pattern numbers make items unique and they will always appear as individual cards.

### Edge Case 6a: Charms (keychains) with patterns
- **Scenario**: A charm item that has a pattern index
- **Handling**: The charm would match `isGroupableItem()` (contains "charm |"), but `hasCustomizations()` checks for `paintSeed` and `patternIndex`, preventing grouping. Only charms without pattern indices will be grouped.

### Edge Case 6b: Charms (keychains) without patterns
- **Scenario**: Multiple identical charms with no pattern indices or other unique properties
- **Handling**: These ARE groupable. They match "charm |" keyword and pass `hasCustomizations()` check (no patterns, no stickers, etc.), so they will be grouped and displayed with quantity badge.

### Edge Case 7: Storage box filter with grouped items
- **Scenario**: Viewing items in a specific storage box that contains groupable items
- **Handling**: Filtering happens before grouping, so grouped items will all be from the same box. Works correctly.

### Edge Case 8: Import preview and deposit/withdraw previews
- **Scenario**: Do these views need grouping?
- **Handling**: These views show items being added/removed, and need to show exact asset matches. Do NOT apply grouping to preview screens. Only apply to inventory/index.html.twig and storage box contents view.

**Correction to scope**: Only apply grouping in the main inventory view (inventory/index.html.twig) and when viewing storage box contents (same view, different filter). Do NOT apply grouping to import_preview, deposit_preview, or withdraw_preview.

## Dependencies

### Blocking Dependencies
- None (this is a standalone feature enhancement)

### Related Tasks
- None

### Can Be Done in Parallel With
- Any other UI enhancements or features

### External Dependencies
- None (uses existing data and infrastructure)

## Acceptance Criteria

- [ ] Cases are grouped by item ID and show quantity badge
- [ ] Capsules are grouped by item ID and show quantity badge
- [ ] Stickers are grouped by item ID and show quantity badge
- [ ] Patches are grouped by item ID and show quantity badge
- [ ] Graffiti items are grouped by item ID and show quantity badge
- [ ] Music Kits are grouped by item ID and show quantity badge
- [ ] Charms (keychains) without patterns are grouped by item ID and show quantity badge
- [ ] Items with pattern indices (paintSeed or patternIndex fields) are NOT grouped (appear as individual cards)
- [ ] Items with float values are NOT grouped (appear as individual cards)
- [ ] Items with stickers applied are NOT grouped (appear as individual cards)
- [ ] Items with name tags are NOT grouped (appear as individual cards)
- [ ] StatTrak items are NOT grouped (appear as individual cards)
- [ ] Souvenir items are NOT grouped (appear as individual cards)
- [ ] Grouped item cards show "Quantity × Unit Price = Aggregate Price" format
- [ ] Individual item cards show wear/float/pattern on left, unit price on right (unchanged from current)
- [ ] Pattern numbers (paintSeed) display on item cards that have them
- [ ] Inventory sorts by aggregate price (descending), not unit price
- [ ] A stack of many cheap items appears higher than 1 expensive item if total value is higher
- [ ] Grouping works correctly in main inventory view (filter: all, active)
- [ ] Grouping works correctly in storage box view (filter: box)
- [ ] Quantity badge appears in top-right corner for grouped items (quantity > 1)
- [ ] Single items (quantity = 1) display without quantity badge
- [ ] Currency formatting respects user preferences (USD/CAD)
- [ ] Total inventory value calculation remains accurate
- [ ] Storage box badges still display correctly on items in storage
- [ ] Steam market links still work on all item cards
- [ ] Import preview does NOT apply grouping (shows individual items)
- [ ] Deposit preview does NOT apply grouping (shows individual items)
- [ ] Withdraw preview does NOT apply grouping (shows individual items)
- [ ] Manual verification: View inventory with grouped and ungrouped items side-by-side
- [ ] Manual verification: Verify aggregate pricing math is correct (quantity × unit = total)
- [ ] Manual verification: Verify sorting places high-value stacks at the top

## Notes & Considerations

### Performance Considerations
- Grouping is done in-memory after fetching all items (no additional DB queries)
- Complexity is O(n) for grouping loop, O(n log n) for sorting
- For typical inventory sizes (100-1000 items), performance impact is negligible

### Future Improvements
- Add "expand/collapse" functionality to view individual items within a group
- Add a toggle to switch between grouped and ungrouped views
- Add grouping to import preview (would require different UI since we need to show which items are new vs existing)
- Consider adding a "group by" dropdown to allow grouping by other criteria (rarity, type, etc.)

### Security Considerations
- No user input involved in grouping logic (hardcoded keywords)
- No new database queries or data access
- Uses existing authorization checks

### UI/UX Considerations
- Quantity badge in top-right is visually distinct from storage box badge in top-left
- Orange quantity badge matches the CS2 theme (cs2-orange color)
- "Quantity × Unit Price = Total" is clear and mathematically explicit
- Pattern numbers use same font-mono style as float values for consistency
- Sorting by aggregate price helps users identify their most valuable item stacks
- Items with pattern indices are always shown individually since patterns make them unique and collectible
- Charms without patterns can be grouped, allowing users to see total charm value at a glance

### Alternative Approaches Considered

**Alternative 1: Database-level grouping with GROUP BY**
- Pros: More efficient for large inventories
- Cons: Complex SQL with JSON field handling, loses individual item references
- Decision: Rejected. In-memory grouping is simpler and sufficient for expected data volumes.

**Alternative 2: Configuration file for groupable items**
- Pros: More flexible, can be changed without code deployment
- Cons: Adds complexity, requires config management
- Decision: Rejected. Hardcoded keywords are sufficient and easier to maintain.

**Alternative 3: Show aggregate price only (hide unit price)**
- Pros: Cleaner UI
- Cons: Users can't see unit price at a glance
- Decision: Rejected. Showing "Quantity × Unit = Total" provides more information.

**Alternative 4: Group by item name instead of item ID**
- Pros: Simpler logic
- Cons: Could group items that are actually different (e.g., different skin variations)
- Decision: Rejected. Grouping by item ID is more accurate and leverages existing database relationships.

## Related Tasks

- None (standalone feature)

## Implementation Notes

### File Changes Summary
1. `src/Controller/InventoryController.php` - Add grouping logic and helper methods
2. `templates/components/item_card.html.twig` - Update price display section and add quantity badge
3. `templates/inventory/index.html.twig` - Pass quantity and aggregatePrice to component

### Code Locations
- Grouping logic: `InventoryController::index()` around line 77-199 (after price calculation)
- Helper methods: Add at end of InventoryController class
- Item card price display: `item_card.html.twig` lines 154-181
- Item card quantity badge: `item_card.html.twig` after line 63 (after custom badges block)

### Testing Checklist
1. Import inventory with various item types
2. Verify grouped items display with quantity badge
3. Verify ungrouped items display without quantity badge
4. Verify sorting by aggregate price
5. Check main inventory view
6. Check active inventory filter
7. Check storage box filter
8. Verify pattern numbers appear on applicable items
9. Test with USD currency
10. Test with CAD currency
11. Verify total inventory value is accurate
12. Check import preview (should NOT be grouped)
13. Check deposit preview (should NOT be grouped)
14. Check withdraw preview (should NOT be grouped)
