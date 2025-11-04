# Update Storage Deposit/Withdraw Templates to Use Item Card Component

**Status**: Completed
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-03
**Part of**: Selective Inventory Import (2/6)
**Depends on**: Task 1001 (Reusable Item Card Component)

## Overview

Replace duplicated item card HTML in storage box deposit and withdraw templates with the reusable item card component created in Task 1001.

## Problem Statement

The storage deposit and withdraw preview pages (`templates/storage_box/deposit.html.twig` and `withdraw.html.twig`) contain duplicated item card HTML similar to the inventory index page.

**Current state:**
- Item display code is duplicated in deposit/withdraw templates
- Inconsistent with the new reusable component
- Makes maintenance difficult

**Desired state:**
- Use the reusable `components/item_card.html.twig` component
- Consistent item display across all pages
- Easy to maintain and update

## Requirements

### Functional Requirements

1. **Update deposit preview template**
   - Replace item card HTML with component embed
   - Show items being deposited (moving from inventory to storage)
   - Display all item properties (price, float, stickers, etc.)

2. **Update withdraw preview template**
   - Replace item card HTML with component embed
   - Show items being withdrawn (moving from storage to inventory)
   - Display all item properties

3. **Maintain existing functionality**
   - Preview pages still show correct items
   - All item data displays correctly
   - No functional changes, only refactoring

### Non-Functional Requirements

- **Visual consistency**: No visual changes from before
- **Performance**: No performance degradation
- **Responsive**: Works on mobile, tablet, desktop

## Technical Approach

### 1. Update Deposit Template

**File**: `templates/storage_box/deposit.html.twig`

Find the section that displays items to be deposited (likely has a grid of item cards) and replace with:

```twig
{% for itemData in itemsToDeposit %}
    {% embed 'components/item_card.html.twig' with {
        itemUser: itemData.itemUser,
        item: itemData.item,
        price: itemData.price,
        stickersWithPrices: itemData.stickers,
        keychainWithPrice: itemData.keychain,
        mode: 'display'
    } %}
        {% block badges %}
            <span class="absolute top-2 left-2 bg-green-600 text-white px-2 py-1 rounded text-xs font-bold">
                TO DEPOSIT
            </span>
        {% endblock %}
    {% endembed %}
{% endfor %}
```

### 2. Update Withdraw Template

**File**: `templates/storage_box/withdraw.html.twig`

Find the section that displays items to be withdrawn and replace with:

```twig
{% for itemData in itemsToWithdraw %}
    {% embed 'components/item_card.html.twig' with {
        itemUser: itemData.itemUser,
        item: itemData.item,
        price: itemData.price,
        stickersWithPrices: itemData.stickers,
        keychainWithPrice: itemData.keychain,
        mode: 'display'
    } %}
        {% block badges %}
            <span class="absolute top-2 left-2 bg-orange-600 text-white px-2 py-1 rounded text-xs font-bold">
                TO WITHDRAW
            </span>
        {% endblock %}
    {% endembed %}
{% endfor %}
```

## Implementation Steps

1. **Read current deposit template** (15 minutes)
   - Identify where item cards are displayed
   - Note data structure passed to template
   - Identify any custom badges or elements

2. **Update deposit template** (30 minutes)
   - Replace item card HTML with component embed
   - Add custom "TO DEPOSIT" badge via badges block
   - Ensure all data is passed correctly

3. **Test deposit flow** (30 minutes)
   - Navigate to deposit preview page
   - Verify items display correctly
   - Test with various item types (with stickers, keychains, name tags)
   - Check responsive design

4. **Read current withdraw template** (15 minutes)
   - Identify where item cards are displayed
   - Note data structure passed to template
   - Identify any custom badges or elements

5. **Update withdraw template** (30 minutes)
   - Replace item card HTML with component embed
   - Add custom "TO WITHDRAW" badge via badges block
   - Ensure all data is passed correctly

6. **Test withdraw flow** (30 minutes)
   - Navigate to withdraw preview page
   - Verify items display correctly
   - Test with various item types
   - Check responsive design

7. **Visual comparison** (15 minutes)
   - Compare before/after for both pages
   - Ensure no visual regressions
   - Verify badges show correctly

## Edge Cases & Error Handling

### Edge Case 1: Empty Lists
**Scenario**: No items to deposit or withdraw.

**Handling**:
- Template should show "No items to deposit/withdraw" message
- This is already handled by existing logic, no changes needed

### Edge Case 2: Items Without Prices
**Scenario**: Some items don't have price data.

**Handling**:
- Component already handles this with "N/A" display
- No additional handling needed

## Acceptance Criteria

- [ ] `templates/storage_box/deposit.html.twig` updated to use item card component
- [ ] `templates/storage_box/withdraw.html.twig` updated to use item card component
- [ ] Deposit preview shows "TO DEPOSIT" badge on items
- [ ] Withdraw preview shows "TO WITHDRAW" badge on items
- [ ] All item properties display correctly (name, price, float, stickers, keychains)
- [ ] Responsive design works on mobile, tablet, desktop
- [ ] No visual regressions compared to original
- [ ] Deposit workflow still functions correctly end-to-end
- [ ] Withdraw workflow still functions correctly end-to-end

## Notes & Considerations

### Data Structure Verification

Before implementing, verify the data structure passed to deposit/withdraw templates matches what the component expects:
- `itemUser` entity available?
- `item` entity available?
- `price` value available?
- `stickers` and `keychain` data available?

If data structure differs, may need to adjust controller to format data correctly.

### Custom Badges

The deposit and withdraw pages should have distinct badges:
- **Deposit**: Green "TO DEPOSIT" badge
- **Withdraw**: Orange "TO WITHDRAW" badge

These are added via the `badges` block when embedding the component.

## Dependencies

- **Task 6-1**: Reusable item card component must be created first

## Next Tasks

After this task is complete:
- **Task 6-3**: Add comparison logic to InventoryImportService

## Related Files

- `templates/storage_box/deposit.html.twig`
- `templates/storage_box/withdraw.html.twig`
- `templates/components/item_card.html.twig` (from Task 1001)
