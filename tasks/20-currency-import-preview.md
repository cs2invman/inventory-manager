# Currency Display: Import Preview Pages

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-06
**Part of**: Currency Display Feature (USD/CAD)

## Overview

Update the inventory import preview page to display prices in the user's preferred currency. Users should see converted prices when reviewing items to be imported or removed.

## Requirements

Update price displays on:
- Import preview page (inventory/import_preview.html.twig)
- NEW items prices
- REMOVE items prices
- Total value calculations
- Individual item cards

## Implementation Steps

### 1. Update InventoryImportController

**File**: src/Controller/InventoryImportController.php

Find the preview action and ensure userConfig is passed:

```php
// In preview() or importPreview() method
public function preview(Request $request): Response
{
    $user = $this->getUser();

    // ... existing code ...

    return $this->render('inventory/import_preview.html.twig', [
        // ... existing variables ...
        'userConfig' => $user->getConfig(), // Add this
    ]);
}
```

### 2. Identify All Price Displays

**File**: templates/inventory/import_preview.html.twig

Search for patterns like:
- `${{ ... |number_format }}`
- Price displays in summary cards
- Total value displays
- Item card price displays

### 3. Update Price Displays

Replace all hardcoded USD formatting with the currency filter:

**Example - Summary totals:**
```twig
{# Before #}
<p class="text-2xl font-bold text-green-400">${{ newItemsTotal|number_format(2, '.', ',') }}</p>

{# After #}
<p class="text-2xl font-bold text-green-400">
    {{ newItemsTotal|format_price(userConfig.preferredCurrency, userConfig.cadExchangeRate) }}
</p>
```

**Example - Remove items totals:**
```twig
{# Before #}
<p class="text-2xl font-bold text-red-400">${{ removedItemsTotal|number_format(2, '.', ',') }}</p>

{# After #}
<p class="text-2xl font-bold text-red-400">
    {{ removedItemsTotal|format_price(userConfig.preferredCurrency, userConfig.cadExchangeRate) }}
</p>
```

### 4. Update Item Card Embeds

If import preview uses the item_card component, pass userConfig:

```twig
{# Before #}
{% embed 'components/item_card.html.twig' with {
    itemUser: itemData.itemUser,
    latestPrice: itemData.latestPrice,
    priceValue: itemData.priceValue,
    mode: 'with-checkbox'
} %}
{% endembed %}

{# After #}
{% embed 'components/item_card.html.twig' with {
    itemUser: itemData.itemUser,
    latestPrice: itemData.latestPrice,
    priceValue: itemData.priceValue,
    mode: 'with-checkbox',
    userConfig: userConfig
} %}
{% endembed %}
```

### 5. Update Any Inline Price Displays

Check for any inline price displays that don't use components:

```twig
{# Before #}
${{ item.price|number_format(2, '.', ',') }}

{# After #}
{{ item.price|format_price(userConfig.preferredCurrency, userConfig.cadExchangeRate) }}
```

## Locations to Update

Based on typical import preview structure, update:

1. **Summary Statistics**
   - Total value of NEW items
   - Total value of REMOVE items
   - Net change in inventory value

2. **NEW Items Section**
   - Individual item prices
   - Section subtotal

3. **REMOVE Items Section**
   - Individual item prices
   - Section subtotal

4. **Filter/Sort Controls** (if prices shown)

5. **Confirmation Summary**
   - Final totals before confirming import

## Acceptance Criteria

- [ ] InventoryImportController passes userConfig to template
- [ ] NEW items total displays in user's preferred currency
- [ ] REMOVE items total displays in user's preferred currency
- [ ] Individual item prices display in user's preferred currency
- [ ] Net change calculation displays in user's preferred currency
- [ ] Currency symbol is correct ($ or CA$)
- [ ] Conversion applies correctly for CAD
- [ ] No conversion for USD
- [ ] All totals match sum of individual items (with conversion)
- [ ] Page works for users without UserConfig (defaults to USD)

## Testing

### Test Scenario 1: USD Import Preview

1. Set currency to USD in settings
2. Upload Steam inventory JSON
3. View import preview
4. Verify:
   - NEW items show "$X.XX"
   - REMOVE items show "$X.XX"
   - Totals show "$X.XX"
   - No conversion applied

### Test Scenario 2: CAD Import Preview

1. Set currency to CAD with rate 1.38
2. Upload Steam inventory JSON
3. View import preview
4. Verify:
   - NEW items show "CA$X.XX"
   - REMOVE items show "CA$X.XX"
   - Totals show "CA$X.XX"
   - Prices are 1.38x USD values
   - Example: $10 item shows CA$13.80

### Test Scenario 3: Large Import

1. Upload large inventory (50+ items)
2. Set currency to CAD
3. Verify:
   - All item prices converted correctly
   - Totals sum correctly with conversion
   - Thousands separator works (e.g., "CA$1,234.56")
   - Page renders without performance issues

### Test Scenario 4: Edge Cases

- **No new items**: Verify totals display correctly
- **No removed items**: Verify totals display correctly
- **Null prices**: Verify "N/A" displays
- **Zero price items**: Verify "$0.00" or "CA$0.00"

### Calculation Verification

Manually verify totals:
```
If 3 items at $10, $20, $30 USD (total $60)
With CAD rate 1.38:
- Item 1: CA$13.80
- Item 2: CA$27.60
- Item 3: CA$41.40
- Total: CA$82.80 ✓
```

## Notes

### Import Preview Workflow

Import preview page shows:
1. Upload form (Task complete when prices show correctly here)
2. Preview page with NEW/REMOVE items
3. Confirmation page (may need currency update too)

Make sure to check all three steps if applicable.

### Total Calculations

If totals are calculated in controller:
```php
// Keep calculations in USD in controller
$newItemsTotal = ...; // USD value

// Template handles conversion via filter
{{ newItemsTotal|format_price(...) }}
```

Don't convert in controller - let template filter handle it.

### Consistency

Ensure all monetary values use the filter:
- Individual item prices ✓
- Section subtotals ✓
- Grand totals ✓
- Net change calculations ✓

## Common Issues

### Issue: Totals Don't Match Sum of Items

**Cause**: Rounding differences when converting individual items vs total

**Solution**: Apply conversion to total, not individual items:
```twig
{# Good: Convert the total #}
{{ (item1 + item2 + item3)|format_price(...) }}

{# Avoid: Sum of converted items (rounding issues) #}
{{ item1|format_price(...) }} + {{ item2|format_price(...) }}
```

### Issue: UserConfig is Null

**Solution**: Provide defaults in template or controller
```twig
{% set currency = userConfig ? userConfig.preferredCurrency : 'USD' %}
{% set rate = userConfig ? userConfig.cadExchangeRate : 1.0 %}
```

## Dependencies

- **Requires**:
  - Task 14 (Database & Entity Changes)
  - Task 15 (Twig Extension)
  - Task 18 (Settings Page) - for users to configure currency
- **Related**: Task 19 (Inventory Pages) - similar implementation

## Related Tasks

- Task 14: Currency Display - Database & Entity Changes
- Task 15: Currency Display - Twig Extension
- Task 19: Currency Display - Inventory Pages
- Task 21: Currency Display - Storage Box Pages
