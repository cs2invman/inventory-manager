# Currency Display: Storage Box Pages

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Medium
**Created**: 2025-11-06
**Part of**: Currency Display Feature (USD/CAD)

## Overview

Update storage box deposit and withdraw pages to display prices in the user's preferred currency. This includes preview pages, confirmation pages, and any storage box detail views.

## Requirements

Update price displays on:
- Storage box deposit form and preview
- Storage box withdraw form and preview
- Storage box detail/contents view (if separate from main inventory)
- Item movement summaries

## Implementation Steps

### 1. Identify Storage Box Controllers

**Files to check**:
- src/Controller/StorageBoxController.php
- Any deposit/withdraw controllers

### 2. Update Controllers

For each controller action that renders prices, pass userConfig:

```php
// Deposit preview action
public function depositPreview(Request $request, StorageBox $box): Response
{
    $user = $this->getUser();

    // ... existing code ...

    return $this->render('storage_box/deposit_preview.html.twig', [
        // ... existing variables ...
        'userConfig' => $user->getConfig(),
    ]);
}

// Withdraw preview action
public function withdrawPreview(Request $request, StorageBox $box): Response
{
    $user = $this->getUser();

    // ... existing code ...

    return $this->render('storage_box/withdraw_preview.html.twig', [
        // ... existing variables ...
        'userConfig' => $user->getConfig(),
    ]);
}
```

### 3. Update Storage Box Templates

**Common locations for price displays**:

#### Deposit Preview Template
```twig
{# File: templates/storage_box/deposit_preview.html.twig #}

{# Total value of items being deposited #}
{# Before #}
<p class="text-2xl font-bold text-green-400">${{ totalValue|number_format(2, '.', ',') }}</p>

{# After #}
<p class="text-2xl font-bold text-green-400">
    {{ totalValue|format_price(userConfig.preferredCurrency, userConfig.cadExchangeRate) }}
</p>

{# Item cards #}
{% embed 'components/item_card.html.twig' with {
    itemUser: item,
    mode: 'with-checkbox',
    userConfig: userConfig  {# Add this #}
} %}
{% endembed %}
```

#### Withdraw Preview Template
```twig
{# File: templates/storage_box/withdraw_preview.html.twig #}

{# Total value of items being withdrawn #}
{# Before #}
<p class="text-2xl font-bold text-orange-400">${{ totalValue|number_format(2, '.', ',') }}</p>

{# After #}
<p class="text-2xl font-bold text-orange-400">
    {{ totalValue|format_price(userConfig.preferredCurrency, userConfig.cadExchangeRate) }}
</p>

{# Item cards #}
{% embed 'components/item_card.html.twig' with {
    itemUser: item,
    mode: 'with-checkbox',
    userConfig: userConfig  {# Add this #}
} %}
{% endembed %}
```

### 4. Update Any Storage Box Stats

If storage box cards show total value:

```twig
{# Storage box value display #}
{# Before #}
<span class="text-sm text-gray-400">Value: ${{ box.totalValue|number_format(2) }}</span>

{# After #}
<span class="text-sm text-gray-400">
    Value: {{ box.totalValue|format_price(userConfig.preferredCurrency, userConfig.cadExchangeRate) }}
</span>
```

### 5. Check Dashboard (if applicable)

If dashboard shows storage box values, update there too:

**File**: templates/dashboard/index.html.twig (or similar)

Update any storage box value displays to use the currency filter.

## Files to Update

Based on typical structure, check these templates:

1. **templates/storage_box/deposit_form.html.twig**
   - Item selection interface with prices

2. **templates/storage_box/deposit_preview.html.twig**
   - Preview of items to deposit
   - Total value of deposit
   - Individual item prices

3. **templates/storage_box/withdraw_form.html.twig**
   - Item selection interface with prices

4. **templates/storage_box/withdraw_preview.html.twig**
   - Preview of items to withdraw
   - Total value of withdrawal
   - Individual item prices

5. **templates/storage_box/show.html.twig** (if exists)
   - Storage box details
   - Box total value
   - Contents listing with prices

6. **templates/dashboard/index.html.twig** (if shows storage values)
   - Storage box summary cards
   - Total storage value

## Acceptance Criteria

- [ ] All storage box controllers pass userConfig to templates
- [ ] Deposit preview shows prices in user's preferred currency
- [ ] Withdraw preview shows prices in user's preferred currency
- [ ] Storage box total values display in user's preferred currency
- [ ] Item cards in storage context use currency filter
- [ ] Currency symbol is correct ($ or CA$)
- [ ] Conversion applies correctly for CAD
- [ ] No conversion for USD
- [ ] All totals match sum of individual items
- [ ] Dashboard storage values (if applicable) use currency filter
- [ ] Page works for users without UserConfig (defaults to USD)

## Testing

### Test Scenario 1: Deposit Workflow with USD

1. Set currency to USD
2. Navigate to storage box
3. Click "Deposit"
4. Select items
5. View preview
6. Verify:
   - Individual item prices show "$X.XX"
   - Total value shows "$X.XX"
   - No conversion applied

### Test Scenario 2: Deposit Workflow with CAD

1. Set currency to CAD with rate 1.38
2. Navigate to storage box
3. Click "Deposit"
4. Select items worth $100 USD total
5. View preview
6. Verify:
   - Individual prices show "CA$X.XX"
   - Total shows "CA$138.00"
   - Conversion applied correctly

### Test Scenario 3: Withdraw Workflow with CAD

1. Set currency to CAD with rate 1.38
2. Navigate to storage box with items
3. Click "Withdraw"
4. Select items worth $50 USD total
5. View preview
6. Verify:
   - Individual prices show "CA$X.XX"
   - Total shows "CA$69.00"
   - Conversion applied correctly

### Test Scenario 4: Storage Box Value Display

1. Set currency to CAD
2. View storage boxes on inventory page
3. Verify box value displays in CAD (if shown)
4. Change to USD
5. Verify box value displays in USD

### Test Scenario 5: Edge Cases

- **Empty storage box**: No prices to display (OK)
- **Null prices**: Verify "N/A" displays
- **Large deposit/withdraw**: Verify thousands separator
- **Mix of priced/unpriced items**: Verify totals calculate correctly

## Notes

### Transaction Preview Pattern

Storage box transactions use a two-step pattern:
1. **Selection**: User selects items (may show prices)
2. **Preview**: User reviews before confirming (definitely shows prices)

Ensure both steps use the currency filter.

### Value Calculations

If box total value is calculated:
```php
// Controller
$box->getTotalValue(); // Returns USD from database

// Template
{{ box.totalValue|format_price(userConfig.preferredCurrency, userConfig.cadExchangeRate) }}
```

Keep all server-side calculations in USD.

### Consistency Across Workflows

Ensure consistency:
- Deposit shows same currency as withdrawal
- Storage box values match item values
- Inventory view and storage view show same prices

## Common Issues

### Issue: Preview Totals Don't Match Selection

**Cause**: Selection page shows USD, preview shows CAD

**Solution**: Use currency filter on both pages:
```twig
{# Selection page AND preview page #}
{{ price|format_price(userConfig.preferredCurrency, userConfig.cadExchangeRate) }}
```

### Issue: Storage Box Card Value Doesn't Match Contents

**Cause**: Card value calculated in controller as CAD, but items are USD

**Solution**: Keep all calculations in USD, convert only in template

## Performance Considerations

- Filter is lightweight (negligible overhead)
- For storage box with 100 items: ~100-200 filter calls
- No performance impact expected
- If issues arise, consider caching userConfig in controller

## Dependencies

- **Requires**:
  - Task 14 (Database & Entity Changes)
  - Task 15 (Twig Extension)
  - Task 18 (Settings Page)
- **Related**: Task 19 (Inventory Pages), Task 20 (Import Preview)

## Related Tasks

- Task 14: Currency Display - Database & Entity Changes
- Task 15: Currency Display - Twig Extension
- Task 18: Currency Display - Settings Page
- Task 19: Currency Display - Inventory Pages
- Task 20: Currency Display - Import Preview
