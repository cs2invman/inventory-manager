# Create Reusable Item Card Component

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-03
**Part of**: Selective Inventory Import (1/6)

## Overview

Create a reusable Twig embed component for displaying CS2 inventory items consistently across the entire system. This component will replace the duplicated item card HTML currently in inventory index, storage deposit/withdraw templates.

## Problem Statement

Currently, the item card HTML (lines 180-308 in `templates/inventory/index.html.twig`) is duplicated across multiple templates:
- Inventory index page
- Storage deposit preview
- Storage withdraw preview

**Issues:**
- Changes to item display require updating multiple files
- Inconsistent styling between pages
- Difficult to maintain

**Solution:**
Create a single reusable `templates/components/item_card.html.twig` component using Twig embed.

## Requirements

### Functional Requirements

1. **Component accepts parameters:**
   - `itemUser` - ItemUser entity (required)
   - `item` - Item entity (required)
   - `price` - Price value (optional)
   - `stickersWithPrices` - Array of sticker data (optional)
   - `keychainWithPrice` - Keychain data (optional)
   - `mode` - String: 'display', 'with-checkbox', 'with-storage-badge' (default: 'display')
   - `checked` - Boolean for checkbox state (optional, default: true)
   - `itemId` - Unique identifier for checkbox (optional)

2. **Component displays:**
   - Item name
   - Item image with hover effects
   - Price (if available)
   - Wear category badge (FN/MW/FT/WW/BS)
   - Float value
   - Pattern index
   - StatTrak/Souvenir badges
   - Stickers overlay (bottom-left)
   - Keychain overlay (bottom-left)
   - Name tag (if present)
   - Rarity indicator line (bottom border)
   - Steam Market link icon

3. **Component modes:**
   - `display` - Standard display (inventory index)
   - `with-storage-badge` - Shows storage box name badge if item is in storage
   - `with-checkbox` - Shows checkbox in top-right corner (for import preview)

4. **Customizable blocks:**
   - `badges` - Override/add custom badges
   - `actions` - Add action buttons
   - `checkbox` - Override checkbox rendering (optional)

### Non-Functional Requirements

- **Consistency**: Identical visual appearance to current item cards
- **Flexibility**: Easy to customize specific parts via embed blocks
- **Performance**: No performance degradation from using embed
- **Responsive**: Works on mobile, tablet, desktop

## Technical Approach

### 1. Create Component File

**File**: `templates/components/item_card.html.twig`

Copy HTML structure from `templates/inventory/index.html.twig` lines 180-308 and convert to parameterized Twig embed.

**Key changes:**
- Replace hardcoded values with parameters
- Add conditional logic for different modes
- Add customizable blocks for badges, actions, checkbox

### 2. Update Inventory Index Template

**File**: `templates/inventory/index.html.twig`

Replace lines 174-309 (item card loop) with:

```twig
{% for itemData in itemsWithPrices %}
    {% embed 'components/item_card.html.twig' with {
        itemUser: itemData.itemUser,
        item: itemData.itemUser.item,
        price: itemData.priceValue,
        stickersWithPrices: itemData.stickersWithPrices,
        keychainWithPrice: itemData.keychainWithPrice,
        mode: 'with-storage-badge'
    } %}
    {% endembed %}
{% endfor %}
```

## Implementation Steps

1. **Create component template** (1 hour)
   - Create `templates/components/item_card.html.twig`
   - Copy HTML structure from inventory index
   - Convert to parameterized template
   - Add mode conditionals
   - Add customizable blocks (badges, actions, checkbox)

2. **Update inventory index** (30 minutes)
   - Replace item card HTML with embed usage
   - Test that page renders correctly
   - Verify all item data displays correctly

3. **Test on inventory page** (30 minutes)
   - Navigate to inventory index
   - Verify items display correctly
   - Check hover states, badges, overlays
   - Test responsive design on mobile/tablet
   - Verify storage box badge shows for items in storage
   - Check Steam Market link works

4. **Visual comparison** (30 minutes)
   - Compare before/after screenshots
   - Ensure no visual regressions
   - Verify spacing, colors, fonts are identical

## Edge Cases & Error Handling

### Edge Case 1: Missing Item Data
**Scenario**: ItemUser or Item entity is null/missing fields.

**Handling**:
- Use Twig's `|default('')` filter for optional fields
- Check `itemUser` and `item` exist before accessing properties

### Edge Case 2: Invalid Mode
**Scenario**: Mode parameter is not one of the expected values.

**Handling**:
- Default to 'display' mode if mode is unrecognized
- No error thrown, graceful fallback

### Edge Case 3: Long Item Names
**Scenario**: Item name is very long and overflows card.

**Handling**:
- Already handled by `line-clamp-2` class (2 lines max)
- Add title attribute for full name on hover

## Acceptance Criteria

- [ ] `templates/components/item_card.html.twig` created with all parameters
- [ ] Component supports three modes: display, with-storage-badge, with-checkbox
- [ ] Component has customizable blocks: badges, actions, checkbox
- [ ] `templates/inventory/index.html.twig` updated to use component
- [ ] Inventory index page renders correctly with no visual changes
- [ ] Storage box badge displays for items in storage boxes
- [ ] All item properties display correctly (name, price, wear, float, stickers, keychains)
- [ ] StatTrak/Souvenir badges show correctly
- [ ] Rarity indicator line shows at bottom
- [ ] Steam Market link works
- [ ] Hover effects work (border color, text color)
- [ ] Responsive design works on mobile, tablet, desktop
- [ ] No visual regressions compared to original

## Notes & Considerations

### Why Twig Embed?

- **Include**: Too rigid, can't customize inner blocks
- **Macro**: Returns HTML string, no block overrides
- **Embed**: Perfect balance - base structure with customizable blocks

### Example Usage

```twig
{# Basic usage - inventory display #}
{% embed 'components/item_card.html.twig' with {
    itemUser: item.itemUser,
    item: item.item,
    price: item.price,
    mode: 'with-storage-badge'
} %}
{% endembed %}

{# Custom badges - import preview #}
{% embed 'components/item_card.html.twig' with {
    itemUser: itemUser,
    item: item,
    mode: 'with-checkbox',
    checked: true,
    itemId: 'add-' ~ loop.index
} %}
    {% block badges %}
        <span class="absolute top-2 left-2 bg-green-600 text-white px-2 py-1 rounded text-xs font-bold">
            NEW
        </span>
    {% endblock %}
{% endembed %}
```

## Dependencies

- None - This is the first task in the series

## Next Tasks

After this task is complete:
- **Task 6-2**: Update storage deposit/withdraw templates to use item card component
- **Task 6-3**: Add comparison logic to InventoryImportService

## Related Files

- `templates/inventory/index.html.twig` (lines 180-308 - source HTML)
- `templates/components/` (new directory if it doesn't exist)
