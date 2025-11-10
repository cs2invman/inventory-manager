# Fix Item Sync Rarity Color, Category, and Subcategory Bugs

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-10

## Overview

Fix critical bugs in `ItemSyncService` that cause:
1. **Wrong rarity colors for stickers/charms** - Using weapon rarity color mapping instead of API's "color" field (8,174+ items affected)
2. **NULL rarity colors for Common items** - Missing color when API provides null rarity (38 items affected)
3. **Wrong category for all items** - Everything categorized as "Weapon" regardless of type (78+ charms, 8,174+ stickers affected)
4. **Missing subcategory data** - No items have subcategory filled despite API providing data via tag2/tag3

These bugs cause incorrect UI rendering, broken filtering, and missing categorization data.

## Problem Statement

After thorough investigation of a fresh import, four critical bugs were found in `src/Service/ItemSyncService.php`:

### Bug 1: Wrong Color Source for Stickers/Charms (CRITICAL)
**Root Cause:**
- Stickers, charms, graffiti, patches use a **different rarity color system** than weapons
- API provides the actual color in `"color"` field (e.g., `"8847ff"` = purple)
- Current code ignores this and uses weapon rarity mapping: "Remarkable" → gold (#e4ae39)
- Should use: API's `"color"` field directly → `#8847ff` (purple)

**Evidence:**
- Sticker "Say Cheese (Holo)":
  - API: `"color": "8847ff"`, `"rarity": "Remarkable"`
  - DB: `rarity_color = "#e4ae39"` (gold) ← WRONG!
  - Should be: `#8847ff` (purple)
- Affects: 8,174+ stickers/charms with wrong colors

**Category-Based Color Rules:**
- **Stickers, Charms, Graffiti, Patches, Music Kits**: Use API's `"color"` field directly
- **Weapons, Knives, Gloves**: Use rarity-based color mapping from `getRarityColor()`

**Impact:**
- Wrong rarity bar colors displayed on 8,174+ items
- Visual inconsistency with Steam/other CS2 sites
- Users cannot correctly identify item rarity by color

### Bug 2: Common Rarity Items Missing Color
**Root Cause:**
- When Steam API returns `"rarity": null`, line 230 defaults to 'Common': `$item->setRarity($itemData['rarity'] ?? 'Common');`
- Then `mapItemFields()` checks `if (isset($data['rarity']))` at line 301
- Since `isset(null)` returns FALSE, the rarity color logic never executes
- Result: Item has `rarity='Common'` but `rarity_color=NULL`

**Evidence:**
- 38 Common charms have NULL rarity_color
- Query: `SELECT COUNT(*) FROM item WHERE rarity = 'Common' AND rarity_color IS NULL` returns 38
- All are charms where API provided `"rarity": null`

**Impact:**
- Rarity indicator bar doesn't render on item cards (template checks `if item.rarityColor` at line 74)
- Affects user experience and item value perception

### Bug 3: Incorrect Category Assignment
**Root Cause:**
- Line 229 hardcodes: `$item->setCategory('Weapon');` for all new items
- `mapItemFields()` has NO logic to update category from API data
- Steam API provides category in `"tag1"` field: "Charm", "Rifle", "SMG", "Pistol", "Sticker", etc.
- This hardcoded value is never overridden

**Evidence:**
- Query: `SELECT COUNT(*) FROM item WHERE name LIKE 'Charm |%' AND category = 'Weapon'` returns 78
- Query: `SELECT COUNT(*) FROM item WHERE name LIKE 'Sticker |%' AND category = 'Weapon'` returns 8,174+
- ALL charms and stickers are categorized as "Weapon"
- API data shows `"tag1": "Charm"` for charms, `"tag1": "Sticker"` for stickers

**Impact:**
- Impossible to filter by actual item category
- Misleading data (charms/stickers are NOT weapons)
- Future filtering/search features will be broken

### Bug 4: Missing Subcategory Data
**Root Cause:**
- `mapItemFields()` has NO logic to map subcategory from API data
- API provides subcategory in `"tag2"` field for weapons (e.g., "M4A4", "AK-47", "AWP")
- Field exists in database but is never populated

**Evidence:**
- Query: `SELECT COUNT(*) FROM item WHERE subcategory IS NOT NULL` returns 0
- ALL items have NULL subcategory
- API data shows `"tag2": "M4A4"` for M4A4 skins, `"tag2": "AK-47"` for AK skins, etc.

**Impact:**
- Cannot filter by weapon type (e.g., show all AK-47 skins)
- Missing valuable categorization data
- Reduced search/filter capabilities

## API Data Analysis

### Sticker example (SHOWS THE COLOR BUG):
```json
{
  "markethashname": "Sticker | Say Cheese (Holo)",
  "color": "8847ff",           ← Should use THIS for rarity_color (#8847ff)
  "rarity": "Remarkable",      ← Currently mapping THIS to #e4ae39 (WRONG!)
  "bordercolor": "D2D2D2",
  "quality": "Normal",
  "tag1": "Sticker",           ← category
  "tag2": null,                ← subcategory (none for stickers)
  "tag3": "Say Cheese (Holo)"  ← item name part
}
```

### Charm with NULL rarity (missing color bug):
```json
{
  "markethashname": "Charm | Lil' Curse",
  "color": "4b69ff",           ← Should use THIS for rarity_color (#4b69ff)
  "rarity": null,              ← Defaults to 'Common', but color logic doesn't run
  "quality": null,
  "tag1": null,                ← category (need fallback detection)
  "tag2": null,
  "tag3": "Lil' Curse"
}
```

### Charm with rarity value:
```json
{
  "markethashname": "Charm | Backsplash",
  "color": "4b69ff",           ← Should use THIS for rarity_color (#4b69ff)
  "rarity": "High Grade",
  "quality": "Normal",
  "tag1": "Charm",             ← category
  "tag2": null,                ← subcategory (none for charms)
  "tag3": "Backsplash"
}
```

### Weapon example (uses rarity-based coloring):
```json
{
  "markethashname": "StatTrak™ M4A4 | Howl (Minimal Wear)",
  "color": "e4ae39",           ← This matches rarity color (both gold for Contraband)
  "rarity": "Contraband",      ← Use getRarityColor() mapping for weapons
  "quality": "StatTrak™",
  "tag1": "Rifle",             ← category
  "tag2": "M4A4",              ← subcategory (weapon type)
  "tag3": "Howl",              ← skin name
  "tag6": "Contraband"
}
```

## Requirements

### Functional Requirements
- Stickers/charms/graffiti/patches must use API's `color` field for `rarity_color`
- Weapons/knives must use rarity-based color mapping
- All items with null API rarity must have `rarity_color` set
- Items must be categorized based on API's `tag1` or intelligent name-based detection
- Items must have `subcategory` populated from API's `tag2` when available
- Existing 8,000+ items in database must be updated to fix incorrect data
- Future syncs must correctly set all these values

### Non-Functional Requirements
- Fix must handle both new items and updates to existing items
- Performance impact should be minimal
- Must handle missing/null API data gracefully
- Migration must be reversible

## Technical Approach

### Fix 1: Category-Aware Rarity Color Assignment

**The Key Insight:** Different item categories use different color sources!

**Location:** `src/Service/ItemSyncService.php` in `mapItemFields()` method

**Replace the existing rarity section (lines 301-306)** with category-aware logic:

```php
// Map rarity and rarity color based on category
if (isset($data['rarity'])) {
    $item->setRarity($data['rarity']);
}

// Determine category first (needed for color logic)
$category = $this->determineCategory($data, $item);
$item->setCategory($category);

// Set rarity color based on item category
// Stickers, charms, graffiti, patches, music kits: Use API's "color" field directly
// Weapons, knives, gloves: Use rarity-based color mapping
if (isset($data['color']) && $data['color']) {
    // Determine if this item type should use API color directly
    $useApiColor = in_array($category, ['Sticker', 'Charm', 'Graffiti', 'Patch', 'Music Kit', 'Agent'], true);

    if ($useApiColor) {
        // Use API's color field directly (add # prefix)
        $item->setRarityColor('#' . $data['color']);
    } else {
        // Weapons/knives: Use rarity-based mapping
        if ($item->getRarity()) {
            $rarityColor = $this->getRarityColor($item->getRarity());
            $item->setRarityColor($rarityColor);
        }
    }
} elseif ($item->getRarity()) {
    // No API color provided, use rarity-based mapping as fallback
    $rarityColor = $this->getRarityColor($item->getRarity());
    $item->setRarityColor($rarityColor);
}

// Map subcategory from tag2 (weapon type, collection, etc.)
if (isset($data['tag2']) && $data['tag2'] !== null) {
    $item->setSubcategory($data['tag2']);
}
```

### Fix 2: Add Category Detection Method

**Location:** `src/Service/ItemSyncService.php` - new method after `getRarityColor()`

```php
/**
 * Determine item category from API data or name patterns
 */
private function determineCategory(array $data, Item $item): string
{
    // Primary: Use API's tag1 field
    if (isset($data['tag1']) && $data['tag1'] !== null) {
        return $data['tag1'];
    }

    // Fallback: Detect from item name
    $name = $item->getName() ?? $item->getHashName() ?? '';

    if (str_starts_with($name, 'Charm |')) {
        return 'Charm';
    }
    if (str_starts_with($name, 'Sticker |')) {
        return 'Sticker';
    }
    if (str_starts_with($name, 'Graffiti |')) {
        return 'Graffiti';
    }
    if (str_starts_with($name, 'Patch |')) {
        return 'Patch';
    }
    if (str_starts_with($name, 'Music Kit |')) {
        return 'Music Kit';
    }
    if (str_contains($name, '★') || str_contains($name, 'Knife')) {
        return 'Knife';
    }
    if (str_starts_with($name, 'Agent |')) {
        return 'Agent';
    }

    // Default to Weapon for guns
    return 'Weapon';
}
```

### Fix 3: Ensure Color Always Set (processItem fallback)

**Location:** `src/Service/ItemSyncService.php` in `processItem()` method (around line 236)

**After calling `mapItemFields()`, add final fallback:**

```php
// Map API fields to Item entity
$this->mapItemFields($item, $itemData);

// Final fallback: Ensure rarity color is always set
// This catches edge cases where API provides neither "color" nor "rarity"
if (!$item->getRarityColor() && $item->getRarity()) {
    $rarityColor = $this->getRarityColor($item->getRarity());
    $item->setRarityColor($rarityColor);
}

// Ensure item is active
$item->setActive(true);
```

### Why This Approach Works

**For Bug 1 (Wrong color source):**
- Categories like Sticker/Charm use API's `color` field directly
- Categories like Weapon/Knife use rarity-based mapping
- Handles both systems correctly

**For Bug 2 (NULL colors):**
- Multiple fallback layers ensure color is always set
- Even items with `rarity: null` get proper color

**For Bug 3 (Wrong category):**
- Primary: Uses API's `tag1` field
- Fallback: Pattern detection from name
- No more hardcoded 'Weapon' for everything

**For Bug 4 (Missing subcategory):**
- Maps API's `tag2` to subcategory field
- Enables filtering by weapon type (M4A4, AK-47, etc.)

### Fix 4: Update Existing Items via Re-sync

**Challenge:** We cannot fix rarity colors for 8,000+ stickers/charms via SQL migration because:
- The correct colors come from API's `color` field
- This data isn't stored anywhere we can query from SQL
- Each item needs its specific color from the API data

**Solution:** Re-run steam sync after code changes

**Option A: Full Re-sync (Recommended)**
```bash
# After deploying code changes, re-run the sync
docker compose exec php php bin/console app:steam:sync-items
```
- Updates all 14,000+ items with correct category, subcategory, and rarity_color
- Safe: Sync updates existing items (action='updated')
- Time: 5-10 minutes for full dataset

**Option B: Quick Category Fix via Migration (Partial Fix)**
If you need category fixes immediately before re-sync:

```sql
-- Fix categories based on name patterns (quick win while waiting for full sync)
UPDATE item SET category = 'Charm' WHERE name LIKE 'Charm |%';
UPDATE item SET category = 'Sticker' WHERE name LIKE 'Sticker |%';
UPDATE item SET category = 'Graffiti' WHERE name LIKE 'Graffiti |%';
UPDATE item SET category = 'Patch' WHERE name LIKE 'Patch |%';
UPDATE item SET category = 'Music Kit' WHERE name LIKE 'Music Kit |%';
UPDATE item SET category = 'Knife' WHERE name LIKE '%Knife%' OR name LIKE '%★%';
UPDATE item SET category = 'Agent' WHERE name LIKE 'Agent |%';
UPDATE item SET category = 'Rifle' WHERE name LIKE '%AK-47%' OR name LIKE '%M4A4%' OR name LIKE '%M4A1-S%' OR name LIKE '%AWP%';
UPDATE item SET category = 'SMG' WHERE name LIKE '%MP5%' OR name LIKE '%MP7%' OR name LIKE '%MP9%' OR name LIKE '%UMP%' OR name LIKE '%P90%';
UPDATE item SET category = 'Pistol' WHERE name LIKE '%Glock%' OR name LIKE '%USP%' OR name LIKE '%P2000%' OR name LIKE '%Desert Eagle%';

-- Note: This only fixes categories, not rarity colors or subcategories
-- Full re-sync still needed for complete fix
```

**Recommendation:** Deploy code changes, then run full re-sync to fix everything at once.

## Implementation Steps

### Step 1: Update ItemSyncService Code

1. **Edit `src/Service/ItemSyncService.php`**

2. **Replace rarity section in `mapItemFields()` method** (lines 301-306):
   - Remove existing rarity color logic
   - Add new category-aware rarity color logic (see Technical Approach - Fix 1)
   - Add subcategory mapping from tag2

3. **Add `determineCategory()` method**:
   - Place it after the `getRarityColor()` method
   - Implement category detection from tag1 or name patterns (see Technical Approach - Fix 2)

4. **Update `processItem()` method** (around line 236):
   - Remove hardcoded `$item->setCategory('Weapon');` from line 229
   - Add final rarity_color fallback after `mapItemFields()` (see Technical Approach - Fix 3)

### Step 2: Test Code Changes with Sample Items

```bash
# Download latest items (if needed)
docker compose exec php php bin/console app:steam:download-items

# Test sync with new logic
docker compose exec php php bin/console app:steam:sync-items
```

Verify a few items in database:
```bash
# Check sticker gets correct API color (purple #8847ff, not gold)
docker compose exec php php bin/console doctrine:query:sql \
  "SELECT name, category, subcategory, rarity, rarity_color FROM item WHERE name = 'Sticker | Say Cheese (Holo)'"
# Expected: category='Sticker', rarity_color='#8847ff' (purple)

# Check charm categorization
docker compose exec php php bin/console doctrine:query:sql \
  "SELECT name, category, rarity, rarity_color FROM item WHERE name LIKE 'Charm |%' LIMIT 3"
# Expected: category='Charm', rarity_color set (from API color field)

# Check weapon has subcategory
docker compose exec php php bin/console doctrine:query:sql \
  "SELECT name, category, subcategory, rarity, rarity_color FROM item WHERE name LIKE '%M4A4%' LIMIT 3"
# Expected: category='Rifle', subcategory='M4A4', rarity_color from getRarityColor()
```

### Step 3: Deploy and Re-sync All Items

After verifying test items work correctly:

```bash
# Full re-sync to fix all existing items
docker compose exec php php bin/console app:steam:sync-items

# This will:
# - Update all 14,000+ items with correct categories
# - Fix all rarity colors (8,000+ stickers/charms get API colors)
# - Populate subcategories for weapons
# - Take 5-10 minutes
```

### Step 4: Verify Database Fixes

```bash
# Check no items missing rarity_color
docker compose exec php php bin/console doctrine:query:sql \
  "SELECT COUNT(*) FROM item WHERE rarity_color IS NULL"
# Expected: 0

# Check stickers properly categorized
docker compose exec php php bin/console doctrine:query:sql \
  "SELECT COUNT(*) FROM item WHERE name LIKE 'Sticker |%' AND category = 'Sticker'"
# Expected: 8,174

# Check charms properly categorized
docker compose exec php php bin/console doctrine:query:sql \
  "SELECT COUNT(*) FROM item WHERE name LIKE 'Charm |%' AND category = 'Charm'"
# Expected: 78

# Check subcategories populated
docker compose exec php php bin/console doctrine:query:sql \
  "SELECT COUNT(*) FROM item WHERE subcategory IS NOT NULL"
# Expected: >1000 (weapons should have subcategories)

# Sample subcategory distribution
docker compose exec php php bin/console doctrine:query:sql \
  "SELECT category, subcategory, COUNT(*) as count FROM item WHERE subcategory IS NOT NULL GROUP BY category, subcategory ORDER BY count DESC LIMIT 20"
# Expected: See weapon types (M4A4, AK-47, AWP, etc.)
```

### Step 5: Manual UI Verification

1. **Test Sticker Colors:**
   - Find "Sticker | Say Cheese (Holo)" in inventory
   - Verify rarity bar is PURPLE (#8847ff), not gold
   - Compare with Steam Market to confirm color matches

2. **Test Charm Colors:**
   - Find "Charm | Lil' Curse" or other Common charm
   - Verify rarity bar displays (should be blue #4b69ff from API)

3. **Test Categorization:**
   - Verify items show correct category labels
   - Test filtering by category (if implemented)

4. **Test Subcategories:**
   - Find weapons like M4A4, AK-47
   - Verify subcategory is shown/filterable

## Edge Cases & Error Handling

### Edge Case 1: API Provides Neither Rarity Nor Tag1
**Scenario:** Item has `"rarity": null` AND `"tag1": null`
**Handling:**
- Rarity defaults to 'Common' (line 230), then rarity_color gets set by new fallback
- Category detected from name pattern via `detectCategoryFromName()`
- If no pattern matches, defaults to 'Weapon'

### Edge Case 2: Existing Items Already Correct
**Scenario:** Some items already have correct category/rarity_color
**Handling:**
- Migration WHERE clauses prevent unnecessary updates
- Code only sets rarity_color if currently NULL
- idempotent operations

### Edge Case 3: Unknown Item Types
**Scenario:** New item type not in our pattern detection
**Handling:**
- Falls back to 'Weapon' category
- Manual review of new item types when they appear
- Can add new patterns to `detectCategoryFromName()` as needed

### Edge Case 4: Re-running Sync on Same Data
**Scenario:** User runs sync multiple times
**Handling:**
- Code now ensures values are always set correctly
- Updates existing items with correct values
- Idempotent (safe to run multiple times)

## Dependencies

### Blocking Dependencies
None - standalone bug fix

### External Dependencies
- Doctrine ORM (existing)
- Database access (existing)
- Steam API data format (established)

## Acceptance Criteria

### Code Changes
- [ ] Code updated in `ItemSyncService.php`:
  - [ ] Category-aware rarity color logic in `mapItemFields()` (uses API color for stickers/charms)
  - [ ] Subcategory mapping from tag2 added
  - [ ] New method `determineCategory()` implemented
  - [ ] Rarity color fallback added to `processItem()`
  - [ ] Hardcoded 'Weapon' category removed from `processItem()`

### Database Verification (after re-sync)
- [ ] 0 items with NULL rarity_color
- [ ] 8,174 stickers with category = 'Sticker' (not 'Weapon')
- [ ] 78 charms with category = 'Charm' (not 'Weapon')
- [ ] >1000 items with populated subcategory field
- [ ] Specific item checks:
  - [ ] "Sticker | Say Cheese (Holo)" has rarity_color = '#8847ff' (purple, not gold)
  - [ ] "Charm | Lil' Curse" has rarity_color set (from API color field)
  - [ ] Weapon items have subcategory = weapon type (e.g., 'M4A4', 'AK-47')

### Manual UI Verification
- [ ] Stickers display correct rarity bar colors (match Steam colors, not weapon colors)
- [ ] Charms display rarity bars (including Common rarity items)
- [ ] Items properly categorized (Sticker, Charm, Rifle, etc.)
- [ ] Weapons show subcategory information (if displayed in UI)
- [ ] Compare 5-10 random items with Steam Market to confirm color accuracy

## Notes & Considerations

### Why These Bugs Went Unnoticed

1. **Color Bug:** Visual difference is subtle without side-by-side comparison
   - Users would need to compare with Steam/csgoskins.gg to notice wrong colors
   - Rarity bar still appeared, just with wrong color

2. **Common items bug:** Only affected 38 charms with null API rarity
   - Small percentage of total items
   - Other Common items with proper API data worked fine

3. **Category bug:** Category field not prominently displayed in UI
   - No category-based filtering implemented yet
   - Data was there but not visible/useful

4. **Subcategory bug:** Field exists but never populated
   - Not displayed anywhere in UI
   - Missing feature went unnoticed

### Different Rarity Color Systems in CS2

**CS2 has TWO rarity color systems:**

1. **Weapon/Knife System:** Rarity-based colors
   - Contraband = Gold
   - Covert = Red
   - Classified = Pink/Magenta
   - Restricted = Purple
   - Mil-Spec = Blue
   - Industrial/Consumer/Base = Light Blue

2. **Sticker/Charm/Graffiti System:** Direct color from API
   - Each item has its own specific color
   - Not tied to rarity tiers
   - API provides exact color hex in "color" field
   - See: https://csgoskins.gg/categories/sticker

**Our Code Must Handle Both:**
- Detect category first
- Use appropriate color source based on category
- This is what the fix implements

### Rarity Color Format

- Stored as 7 characters including '#' prefix (e.g., '#b0c3d9')
- Verified by: `SELECT LENGTH(rarity_color) FROM item` returns 7
- getRarityColor() returns colors with '#' prefix

### Testing Strategy

Since this project doesn't use automated tests, testing approach:
1. Code review (manual inspection)
2. Database queries (verify counts before/after)
3. UI testing (visual verification)
4. Fresh sync test (end-to-end verification)

### Alternative Approaches Considered

**Option 1: Only fix with migration (no code changes)** ❌
- Pros: Quick fix for existing data
- Cons: Future syncs would recreate the bugs

**Option 2: Only fix code (no migration)** ❌
- Pros: Future syncs work correctly
- Cons: 38+ existing items stay broken until next sync

**Option 3: Fix both code AND data (migration)** ✅ **CHOSEN**
- Pros: Fixes current data AND prevents future occurrences
- Cons: Slightly more work (acceptable for permanent fix)

### Performance Impact

- Code changes: Negligible (adds 2-3 simple checks per item sync)
- Migration: Fast (updates ~100-200 items, no index changes)
- No indexes need updating (category and rarity_color are not indexed)

### Related Code Locations

- Bug 1: `src/Service/ItemSyncService.php:230, 236, 301-306`
- Bug 2: `src/Service/ItemSyncService.php:229, 256` (mapItemFields)
- UI Impact: `templates/components/item_card.html.twig:74-76`
- Entity: `src/Entity/Item.php:43-50`

## Follow-up Tasks

After this fix, consider:
1. **Add category-based filtering to inventory UI**
   - Filter by: Sticker, Charm, Rifle, SMG, Pistol, Knife, etc.
   - Now possible with correctly populated category field

2. **Add subcategory-based filtering for weapons**
   - Filter by weapon type: M4A4, AK-47, AWP, etc.
   - Now possible with populated subcategory field

3. **Display category/subcategory in item cards**
   - Show category badge on item cards
   - Show weapon type for guns

4. **Review `type` field usage** (line 228 in processItem)
   - Currently uses `quality` field from API
   - May need similar mapping logic

5. **Add data quality monitoring**
   - Log items with missing API data
   - Create console command to audit item data completeness

## Related Tasks

None - standalone bug fix
