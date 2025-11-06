# Currency Display: Inventory Pages

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-06
**Part of**: Currency Display Feature (USD/CAD)

## Overview

Update the inventory index page and item card component to display prices in the user's preferred currency using the currency formatting filter. This is the most visible part of the currency feature.

## Requirements

Update all price displays on:
- Inventory index page (inventory/index.html.twig)
- Item card component (components/item_card.html.twig)
- Include total inventory value, stats cards, and individual item prices

## Implementation Steps

### 1. Update InventoryController

**File**: src/Controller/InventoryController.php

Ensure UserConfig is passed to templates:

```php
public function index(Request $request): Response
{
    $user = $this->getUser();

    // ... existing code ...

    return $this->render('inventory/index.html.twig', [
        // ... existing variables ...
        'userConfig' => $user->getConfig(), // Add this
    ]);
}
```

If using a service to get config:
```php
$userConfig = $this->userConfigService->getUserConfig($user);
```

### 2. Update Inventory Index Template

**File**: templates/inventory/index.html.twig

Replace all hardcoded price formatting with currency filter:

**Line 34** - Header total value:
```twig
{# Before #}
<p class="text-4xl font-bold text-cs2-orange">${{ totalValue|number_format(2, '.', ',') }}</p>

{# After #}
<p class="text-4xl font-bold text-cs2-orange">
    {{ totalValue|format_price(userConfig.preferredCurrency, userConfig.cadExchangeRate) }}
</p>
```

**Line 77** - Stats card total value:
```twig
{# Before #}
<div class="text-2xl font-bold text-cs2-orange">${{ totalValue|number_format(2, '.', ',') }}</div>

{# After #}
<div class="text-2xl font-bold text-cs2-orange">
    {{ totalValue|format_price(userConfig.preferredCurrency, userConfig.cadExchangeRate) }}
</div>
```

### 3. Update Item Card Component

**File**: templates/components/item_card.html.twig

Add parameters to component:
```twig
{# Add to component parameters documentation (line 5-16) #}
    - userConfig: UserConfig entity for currency formatting (optional)
```

**Line 170-176** - Price display:
```twig
{# Before #}
<span class="text-green-400 font-bold text-lg">
    {% if latestPrice and priceValue is not null %}
        ${{ priceValue|number_format(2, '.', ',') }}
    {% else %}
        <span class="text-gray-500">N/A</span>
    {% endif %}
</span>

{# After #}
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
```

### 4. Update Item Card Embeds

**File**: templates/inventory/index.html.twig

Pass userConfig to item card embeds (line 184-193):

```twig
{# Before #}
{% embed 'components/item_card.html.twig' with {
    itemUser: itemData.itemUser,
    item: itemData.itemUser.item,
    latestPrice: itemData.latestPrice,
    priceValue: itemData.priceValue,
    stickersWithPrices: itemData.stickersWithPrices,
    keychainWithPrice: itemData.keychainWithPrice,
    mode: 'with-storage-badge'
} %}
{% endembed %}

{# After #}
{% embed 'components/item_card.html.twig' with {
    itemUser: itemData.itemUser,
    item: itemData.itemUser.item,
    latestPrice: itemData.latestPrice,
    priceValue: itemData.priceValue,
    stickersWithPrices: itemData.stickersWithPrices,
    keychainWithPrice: itemData.keychainWithPrice,
    mode: 'with-storage-badge',
    userConfig: userConfig
} %}
{% endembed %}
```

### 5. Update Sticker/Keychain Tooltips (Optional Enhancement)

**File**: templates/components/item_card.html.twig

Lines 120, 138 - Update sticker and keychain tooltips:

```twig
{# Keychain tooltip (line 120) #}
title="Keychain: {{ keychainWithPrice.name }} ({{ keychainWithPrice.price|format_price(userConfig ? userConfig.preferredCurrency : 'USD', userConfig ? userConfig.cadExchangeRate : 1.0) }})"

{# Sticker tooltip (line 138) #}
title="{{ sticker.name }} ({{ sticker.price|format_price(userConfig ? userConfig.preferredCurrency : 'USD', userConfig ? userConfig.cadExchangeRate : 1.0) }})"
```

## Acceptance Criteria

- [ ] InventoryController passes userConfig to template
- [ ] Header total value displays in user's preferred currency
- [ ] Stats card total value displays in user's preferred currency
- [ ] Item card prices display in user's preferred currency
- [ ] Currency symbol is correct ($ for USD, CA$ for CAD)
- [ ] Conversion applies when CAD is selected
- [ ] No conversion when USD is selected
- [ ] N/A displays for null prices (unchanged behavior)
- [ ] Thousands separator works (e.g., "CA$1,234.56")
- [ ] Sticker and keychain tooltips show converted prices
- [ ] All price displays update when user changes currency preference
- [ ] Page works for users without UserConfig (defaults to USD)

## Testing

### Test Scenario 1: USD Display (Default)

1. Set currency to USD in settings
2. Navigate to inventory page
3. Verify:
   - Header shows "$X.XX" format
   - Stats card shows "$X.XX" format
   - Item cards show "$X.XX" format
   - No conversion applied (prices match database values)

### Test Scenario 2: CAD Display

1. Set currency to CAD with rate 1.38 in settings
2. Navigate to inventory page
3. Verify:
   - Header shows "CA$X.XX" format
   - Stats card shows "CA$X.XX" format
   - Item cards show "CA$X.XX" format
   - Prices are 1.38x the USD amount
   - Example: $10.00 item shows as CA$13.80

### Test Scenario 3: Exchange Rate Change

1. Set currency to CAD with rate 1.38
2. Note a specific item price (e.g., CA$13.80)
3. Change exchange rate to 1.50 in settings
4. Return to inventory page
5. Verify item price updated (e.g., CA$15.00)

### Test Scenario 4: Edge Cases

- **Null prices**: Verify "N/A" still displays
- **Zero prices**: Verify displays as "$0.00" or "CA$0.00"
- **Large values**: Verify thousands separator works (e.g., $1,234.56)
- **Small values**: Verify proper formatting (e.g., $0.05)
- **No UserConfig**: Verify defaults to USD with no errors

### Visual Testing

- [ ] Desktop: 1920px width
- [ ] Laptop: 1366px width
- [ ] Tablet: 768px width
- [ ] Mobile: 375px width
- [ ] Currency symbols don't break layout
- [ ] Longer "CA$" prefix doesn't cause overflow

### Filter Views Testing

Test currency display across all inventory views:
- [ ] All Items view
- [ ] Active Inventory view
- [ ] Storage Box filter view
- [ ] Individual storage box contents view

## Notes

### UserConfig Handling

If userConfig is null, provide defaults:
```twig
{{ price|format_price(
    userConfig ? userConfig.preferredCurrency : 'USD',
    userConfig ? userConfig.cadExchangeRate : 1.0
) }}
```

Or set a default in controller:
```php
'userConfig' => $user->getConfig() ?? new UserConfig(),
```

### Performance Considerations

- Filter is lightweight (single multiplication)
- Called once per price display (acceptable overhead)
- No database queries in filter
- For 100 items, ~400 filter calls (header, stats, items, tooltips)
- Negligible performance impact

### Consistency

Ensure all price displays use the filter:
- Header total value ✓
- Stats cards total value ✓
- Item card prices ✓
- Sticker tooltips ✓
- Keychain tooltips ✓

## Dependencies

- **Requires**:
  - Task 14 (Database & Entity Changes)
  - Task 15 (Twig Extension) - must be completed first
  - Task 18 (Settings Page) - for users to configure currency
- **Required by**: None (but enhances user experience)

## Related Tasks

- Task 14: Currency Display - Database & Entity Changes
- Task 15: Currency Display - Twig Extension
- Task 18: Currency Display - Settings Page
- Task 20: Currency Display - Import Preview (similar implementation)
- Task 21: Currency Display - Storage Box Pages (similar implementation)
