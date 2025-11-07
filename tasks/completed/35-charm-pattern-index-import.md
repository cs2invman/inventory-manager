# Fix Charm Pattern Index Import from Steam Inventory

**Status**: Completed
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-07

## Overview

The inventory import process is not extracting the `pattern_index` for charms (keychains). The Steam API provides a `Charm Template` value in `asset_properties` with `propertyid: 3`, but the current implementation only extracts pattern data for `propertyid: 1` (weapons) and `propertyid: 2` (float values).

## Problem Statement

When importing Steam inventory JSON, charms have their pattern index stored in the `asset_properties` array under `propertyid: 3` as "Charm Template":

```json
"asset_properties": [
  {
    "propertyid": 3,
    "int_value": "50743",
    "name": "Charm Template"
  }
]
```

Currently, the `extractFloatAndPattern()` method in `InventoryImportService` only processes:
- `propertyid: 2` → float_value (paint wear)
- `propertyid: 1` → pattern_index (paint seed for weapons)

This means charm pattern indices are being ignored during import, even though the `ItemUser` entity has a `patternIndex` field that should store this value.

## Requirements

### Functional Requirements
- Extract `pattern_index` from `asset_properties` when `propertyid == 3` (Charm Template)
- Continue to extract existing pattern data for weapons (`propertyid == 1`)
- Store the extracted charm pattern_index in `ItemUser.patternIndex` field
- Make the extraction logic future-proof to handle any `propertyid` that contains `int_value` pattern data

### Non-Functional Requirements
- No performance impact (simple conditional logic)
- Backward compatible with existing weapon pattern extraction
- No database schema changes required (field already exists)

## Technical Approach

### Service Layer Modification

**File**: `src/Service/InventoryImportService.php`

**Method**: `extractFloatAndPattern()` (lines 638-658)

**Current logic**:
```php
private function extractFloatAndPattern(array $assetProperties): array
{
    $result = [
        'float_value' => null,
        'pattern_index' => null,
    ];

    foreach ($assetProperties as $property) {
        $propertyId = $property['propertyid'] ?? null;

        if ($propertyId == 2 && isset($property['float_value'])) {
            $result['float_value'] = (float) $property['float_value'];
        }

        if ($propertyId == 1 && isset($property['int_value'])) {
            $result['pattern_index'] = (int) $property['int_value'];
        }
    }

    return $result;
}
```

**Updated logic**:
```php
private function extractFloatAndPattern(array $assetProperties): array
{
    $result = [
        'float_value' => null,
        'pattern_index' => null,
    ];

    foreach ($assetProperties as $property) {
        $propertyId = $property['propertyid'] ?? null;

        // Extract float value (paint wear)
        if ($propertyId == 2 && isset($property['float_value'])) {
            $result['float_value'] = (float) $property['float_value'];
        }

        // Extract pattern index for weapons (Paint Seed) and charms (Charm Template)
        // propertyid 1 = Paint Seed (weapons)
        // propertyid 3 = Charm Template (keychains)
        if (in_array($propertyId, [1, 3]) && isset($property['int_value'])) {
            $result['pattern_index'] = (int) $property['int_value'];
        }
    }

    return $result;
}
```

**Alternative future-proof approach** (if we want to handle ANY int_value pattern):
```php
// Extract pattern index from any property with int_value
// Known property IDs:
//   1 = Paint Seed (weapons)
//   3 = Charm Template (keychains)
if (isset($property['int_value']) && in_array($propertyId, [1, 3])) {
    $result['pattern_index'] = (int) $property['int_value'];
}
```

### No Other Changes Required

The rest of the import flow already handles `pattern_index` correctly:
- Line 452: `$data['pattern_index'] = $floatAndPattern['pattern_index'];`
- Line 307: `$itemUser->setPatternIndex($data['pattern_index']);`
- Line 697: `$itemUser->setPatternIndex($data['pattern_index'] ?? null);`

## Implementation Steps

1. **Update `extractFloatAndPattern()` method**
   - Open `src/Service/InventoryImportService.php`
   - Locate the `extractFloatAndPattern()` method (around line 638)
   - Update the conditional logic to include `propertyid == 3`
   - Add inline comments documenting the property ID meanings

2. **Test the fix**
   - Import a Steam inventory JSON that contains charms
   - Verify that `ItemUser.patternIndex` is populated for charm items
   - Verify that weapon pattern indices still work correctly
   - Check the database to confirm the values are stored

3. **Verify existing functionality**
   - Confirm that weapon pattern indices (propertyid 1) still import correctly
   - Confirm that float values (propertyid 2) still import correctly
   - Check that items without pattern_index still import without errors

## Edge Cases & Error Handling

- **Missing int_value**: The code already handles this with `isset($property['int_value'])`
- **Null propertyid**: Already handled with `$property['propertyid'] ?? null`
- **Items without asset_properties**: Already handled in `mapSteamItemToEntity()` line 449 with null check
- **Multiple pattern properties**: If an item somehow has both propertyid 1 and 3, the last one wins (weapons should only have 1, charms only 3, so this shouldn't happen)

## Acceptance Criteria

- [ ] Charms with `propertyid: 3` have their pattern_index extracted and stored in ItemUser
- [ ] Weapons with `propertyid: 1` continue to have their pattern_index extracted correctly
- [ ] Items without pattern_index properties import without errors
- [ ] Code includes inline comments documenting property ID meanings
- [ ] Manual test: Import inventory with charms, verify pattern_index is populated in database
- [ ] Manual test: Import inventory with weapons, verify existing pattern_index still works

## Notes & Considerations

### Why This Was Missed Initially

The original implementation was focused on weapon skins (propertyid 1 = Paint Seed). Charms were added to CS2 later and use a different property ID (propertyid 3 = Charm Template) for their pattern/template identifier.

### Property ID Reference

Based on Steam API observations:
- `propertyid: 1` = Paint Seed (weapon skin pattern)
- `propertyid: 2` = Paint Wear (float value, 0.00-1.00)
- `propertyid: 3` = Charm Template (keychain/charm pattern)

### Database Field

The `ItemUser.patternIndex` field already exists and is properly defined:
- Column type: `INTEGER`
- Nullable: Yes
- Used for: Storing pattern/template identifiers for items

### Future Considerations

If Steam adds new item types with pattern indices under different property IDs, this code will need to be updated to include those IDs. Consider logging unknown property IDs for monitoring purposes:

```php
// Optional: Log unknown property types for future discovery
if ($propertyId !== null && !in_array($propertyId, [1, 2, 3])) {
    $this->logger->debug('Unknown asset property type', [
        'propertyid' => $propertyId,
        'property' => $property,
    ]);
}
```

## Related Tasks

None - this is a standalone bug fix.
