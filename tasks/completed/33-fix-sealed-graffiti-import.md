# Fix Sealed Graffiti Import

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-07

## Overview

Sealed graffiti items are currently being skipped during inventory import because the import service treats all graffiti (type `CSGO_Type_Spray`) as non-tradeable collectibles. However, **sealed graffiti** are tradeable, valuable items that should be imported, while **unsealed graffiti** (consumable spray charges) should continue to be skipped.

## Problem Statement

In `src/Service/InventoryImportService.php`, the `shouldSkipItem()` method (lines 791-822) skips all items with type `CSGO_Type_Spray` or `Type_Spray`. This blanket exclusion prevents sealed graffiti from being imported into the user's inventory, even though they are tradeable items with market value.

**Current behavior:**
- Sealed graffiti (e.g., "Sealed Graffiti | Recoil") are skipped ❌
- Unsealed graffiti (consumable spray charges) are skipped ✅

**Expected behavior:**
- Sealed graffiti should be imported ✅
- Unsealed graffiti should continue to be skipped ✅

## Requirements

### Functional Requirements
- Import sealed graffiti items (market_hash_name contains "Sealed Graffiti")
- Continue to skip unsealed graffiti (consumable spray charges)
- Maintain existing skip logic for other non-tradeable items (Storage Units, Medals, Coins, Music Kits)
- Log when graffiti items are skipped vs. imported for debugging

### Non-Functional Requirements
- No performance impact on import processing
- Maintain backward compatibility with existing import workflows
- Clear distinction logic that's easy to understand and maintain

## Technical Approach

### Service Layer Changes

**File: `src/Service/InventoryImportService.php`**

Modify the `shouldSkipItem()` method to:
1. Check if item type is `CSGO_Type_Spray` or `Type_Spray`
2. If yes, check the `market_hash_name` field in the description
3. If `market_hash_name` contains "Sealed Graffiti", DO NOT skip (allow import)
4. If `market_hash_name` does NOT contain "Sealed Graffiti", skip it (unsealed graffiti)
5. Update debug logging to differentiate between sealed and unsealed graffiti

### Implementation Logic

The key distinction is the `market_hash_name`:
- **Sealed graffiti**: `"Sealed Graffiti | [Name]"` (e.g., "Sealed Graffiti | Recoil")
- **Unsealed graffiti**: Just the name or other format without "Sealed Graffiti"

## Implementation Steps

1. **Modify `shouldSkipItem()` method** in `InventoryImportService.php` (line 791)
   - Add special handling for graffiti types before the generic type check
   - Extract `market_hash_name` from `$description`
   - Check if it contains "Sealed Graffiti"
   - If yes, return `false` (do not skip)
   - If no, return `true` (skip unsealed graffiti)

2. **Update debug logging** (line 421-425)
   - Add more specific log message for graffiti items
   - Log whether it's sealed or unsealed
   - Include `market_hash_name` in log data for debugging

3. **Test with real Steam inventory data**
   - Find a Steam inventory with sealed graffiti
   - Import it and verify sealed graffiti appear in preview
   - Verify unsealed graffiti continue to be skipped
   - Check logs to confirm correct classification

## Edge Cases & Error Handling

- **Missing market_hash_name**: If `market_hash_name` is not present, treat as unsealed and skip (safe default)
- **Null or empty market_hash_name**: Skip the item (unsealed graffiti likely don't have market hash names)
- **Case sensitivity**: Use case-insensitive string matching (`stripos()` or `str_contains()` with case handling) to detect "Sealed Graffiti"
- **Partial matches**: Ensure we're looking for "Sealed Graffiti" as a complete phrase, not partial matches
- **Multiple graffiti in inventory**: Ensure logic works correctly when processing multiple graffiti items in one import

## Dependencies

### Blocking Dependencies
- None (this is a standalone bug fix)

### Related Tasks
- None (isolated change to import service)

### Can Be Done in Parallel With
- Any other tasks

### External Dependencies
- SteamWebAPI.com data must include `market_hash_name` for graffiti items (already the case)

## Acceptance Criteria

- [ ] Sealed graffiti items (market_hash_name contains "Sealed Graffiti") are imported successfully
- [ ] Sealed graffiti appear in import preview with correct pricing
- [ ] Unsealed graffiti (consumable spray charges) continue to be skipped
- [ ] Other non-tradeable items (Storage Units, Medals, Coins, Music Kits) continue to be skipped correctly
- [ ] Debug logs clearly indicate when graffiti items are skipped vs. imported
- [ ] No performance degradation in import processing
- [ ] Manual verification: Import a Steam inventory containing sealed graffiti and confirm they appear in the import preview

## Notes & Considerations

- **Why this happened**: The original implementation grouped all graffiti together as non-tradeable collectibles, but sealed graffiti are actually tradeable items with market value
- **Type distinction**: Sealed graffiti have type `CSGO_Type_Spray` but are marketable; unsealed graffiti have the same type but are consumable
- **Market hash name is key**: The `market_hash_name` field is the reliable way to distinguish sealed from unsealed
- **Backward compatibility**: This change only affects graffiti items; all other skip logic remains unchanged
- **Testing**: Can test with any Steam inventory that contains sealed graffiti items (check Steam Community Market for examples)

## Code Example

```php
// In shouldSkipItem() method, BEFORE the generic type check:

// Special handling for graffiti: allow sealed graffiti, skip unsealed
if (in_array($itemType, ['CSGO_Type_Spray', 'Type_Spray'])) {
    $marketHashName = $description['market_hash_name'] ?? '';

    // Sealed graffiti have "Sealed Graffiti" in their market hash name
    if (str_contains($marketHashName, 'Sealed Graffiti')) {
        $this->logger->debug('Allowing sealed graffiti import', [
            'name' => $description['name'] ?? 'Unknown',
            'market_hash_name' => $marketHashName,
        ]);
        return false; // Do NOT skip
    }

    // Unsealed graffiti (consumable spray charges) - skip
    $this->logger->debug('Skipping unsealed graffiti', [
        'name' => $description['name'] ?? 'Unknown',
        'market_hash_name' => $marketHashName,
    ]);
    return true; // Skip
}

// Continue with existing skip logic for other types...
```

## Related Tasks

None - this is a standalone bug fix
