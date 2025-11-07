# Enable Music Kit and Tradeable Item Imports

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-07

## Overview

Music kits and other tradeable items with market prices are currently being skipped during inventory import. The import service should allow **all tradeable items** (those with market prices) to be imported, not just skins, cases, stickers, and agents.

## Problem Statement

In `src/Service/InventoryImportService.php`, the `shouldSkipItem()` method (lines 816-822) skips all items with type `CSGO_Type_MusicKit`, but music kits are tradeable items with market value and should be imported.

Additionally, some tools (like keys, name tags, etc.) might be tradeable and have market prices, but are being skipped under the blanket `CSGO_Type_Tool` exclusion.

**Current behavior:**
- Music kits are skipped ❌
- Some tradeable tools might be skipped ❌
- Storage Units are correctly skipped ✅ (handled separately by StorageBoxService)
- Medals, coins, badges are correctly skipped ✅ (non-tradeable collectibles)

**Expected behavior:**
- Music kits should be imported ✅
- Tradeable tools (if any exist in inventory) should be imported ✅
- Storage Units should continue to be skipped ✅ (handled separately)
- Medals, coins, badges should continue to be skipped ✅

## Requirements

### Functional Requirements
- Import all music kits (they're all tradeable with market prices)
- Review and allow tradeable tools while keeping Storage Units skipped
- Maintain existing skip logic for non-tradeable collectibles
- Log when items are imported vs. skipped for debugging

### Non-Functional Requirements
- No performance impact on import processing
- Maintain backward compatibility with existing import workflows
- Storage box handling should remain unchanged

## Technical Approach

### Service Layer Changes

**File: `src/Service/InventoryImportService.php`**

Modify the `shouldSkipItem()` method to:
1. Remove `CSGO_Type_MusicKit` from the skip types list entirely
2. For `CSGO_Type_Tool`, add special handling:
   - Check if it's a Storage Unit (keep skipping these)
   - Allow other tools to be imported
3. Keep skipping collectibles (medals, coins, badges)
4. Update debug logging to show what types are being allowed/skipped

## Implementation Steps

1. **Remove Music Kits from skip list** (line 820)
   - Delete `CSGO_Type_MusicKit` from the `$skipTypes` array
   - Music kits should be imported like any other tradeable item

2. **Add special handling for Tools** (similar to graffiti approach)
   - Check if item type is `CSGO_Type_Tool`
   - Examine the item name or market_hash_name to identify Storage Units
   - Skip only Storage Units (they contain "Storage Unit" in the name)
   - Allow other tools to be imported

3. **Update debug logging**
   - Log when music kits are being imported
   - Log when tools are being evaluated (storage unit vs. other)
   - Include item type and market_hash_name in logs

4. **Test with real Steam inventory data**
   - Import inventory with music kits and verify they appear
   - Verify Storage Units continue to be skipped
   - Check logs to confirm correct classification

## Edge Cases & Error Handling

- **Missing market_hash_name**: If tool doesn't have market_hash_name, check the name field for "Storage Unit"
- **Storage Unit variations**: Account for different Storage Unit names (e.g., "X-Ray P250 Package", "Operation Breakout Weapon Case", etc.)
- **Other tool types**: Keys, name tags, stickers (though stickers might have their own type)
- **International names**: Storage Units might have localized names in different languages

## Dependencies

### Blocking Dependencies
- None (standalone enhancement)

### Related Tasks
- Task 33: Fix Sealed Graffiti Import (similar approach for special handling)

### Can Be Done in Parallel With
- Any other tasks

### External Dependencies
- SteamWebAPI.com must have music kits in the database with prices

## Acceptance Criteria

- [ ] Music kits are imported successfully and appear in import preview with pricing
- [ ] Music kits can be selected and imported like other tradeable items
- [ ] Storage Units continue to be skipped (handled by StorageBoxService)
- [ ] Other tradeable tools (if any) are imported successfully
- [ ] Non-tradeable collectibles (medals, coins, badges) continue to be skipped
- [ ] Debug logs clearly indicate why items are imported or skipped
- [ ] No performance degradation in import processing
- [ ] Manual verification: Import a Steam inventory containing music kits and confirm they appear in the import preview

## Notes & Considerations

- **Music Kits**: All music kits are tradeable and have market prices - no need for special distinction logic like graffiti
- **Storage Units**: These are tools but handled separately by StorageBoxService, so we still want to skip them in regular import
- **Tool distinction**: Need to identify Storage Units specifically (by name) and skip only those
- **Type hierarchy**: Some items might have multiple type tags, so check both the extracted type and the tags array
- **Database availability**: Music kits and other tradeable items must exist in the database (synced via `app:steam:sync-items`) for import to work
- **Backward compatibility**: This change only affects currently-skipped items; existing imports remain unchanged

## Code Example

```php
// In shouldSkipItem() method:

// Special handling for tools: skip Storage Units, allow other tools
if (in_array($itemType, ['CSGO_Type_Tool', 'Type_Tool'])) {
    $name = $description['name'] ?? '';
    $marketHashName = $description['market_hash_name'] ?? '';

    // Storage Units have specific names/patterns
    if (str_contains($name, 'Storage Unit') ||
        str_contains($marketHashName, 'Storage Unit')) {
        $this->logger->debug('Skipping Storage Unit (handled separately)', [
            'name' => $name,
            'market_hash_name' => $marketHashName,
        ]);
        return true; // Skip Storage Units
    }

    // Allow other tools (keys, name tags, etc.)
    $this->logger->debug('Allowing tradeable tool import', [
        'name' => $name,
        'market_hash_name' => $marketHashName,
    ]);
    return false; // Do NOT skip
}

// Update skip types - remove music kits and tools (now handled above)
$skipTypes = [
    'CSGO_Type_Collectible',       // Medals, Coins, Badges
    'Type_Collectible',            // Alternative collectible type
];
```

## Alternative Approach: Check for market_hash_name

A simpler approach might be:
- If an item has a `market_hash_name`, it's tradeable → import it
- If an item doesn't have a `market_hash_name`, it's likely non-tradeable → skip it (unless it's a Storage Unit)

This would be more future-proof but might require additional validation.

## Related Tasks

- Task 33: Fix Sealed Graffiti Import (completed) - similar special handling approach
