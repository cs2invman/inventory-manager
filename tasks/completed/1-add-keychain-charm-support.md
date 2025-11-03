# Add Keychain/Charm Support to Inventory System

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Medium
**Created**: 2025-11-02

## Overview

Add support for keychains/charms that can be attached to CS2 weapons. The system should parse keychain data from Steam inventory JSON, store it in the database, display it on the inventory page alongside stickers, and include the keychain value in the total item value calculation.

## Problem Statement

Currently, the inventory system tracks stickers attached to items but does not recognize or handle keychains/charms. In CS2, players can attach one keychain per weapon, which adds aesthetic value and can increase the item's market value. The Steam inventory API provides keychain information in a similar format to stickers (as HTML in the descriptions array with name `keychain_info`).

Example keychain data from Steam API:
```html
<div id="keychain_info" class="keychain_info" ...>
  <center>
    <img width=64 height=48 src="https://cdn.steamstatic.com/.../kc_wpn_ak_jelly...png" title="Charm: Die-cast AK">
    <br>Charm: Die-cast AK
  </center>
</div>
```

## Requirements

### Functional Requirements
- Parse keychain information from Steam inventory JSON during import
- Store keychain data (name, image URL) in the ItemUser entity
- Display keychain image next to stickers on inventory item cards
- Look up keychain price from the `item_price` table using the keychain's market_hash_name
- Add keychain price to the base item price in total value calculations
- Support only one keychain per item (CS2 limitation)

### Non-Functional Requirements
- Maintain backward compatibility with items that don't have keychains
- Ensure consistent visual presentation with existing sticker display
- Handle missing or invalid keychain data gracefully
- Maintain import performance (no significant slowdown)

## Technical Approach

### Database Changes

**Entity: ItemUser** (`src/Entity/ItemUser.php`)
- Add new field: `keychain` (JSON column, nullable)
- Structure:
  ```json
  {
    "name": "Die-cast AK",
    "image_url": "https://cdn.steamstatic.com/.../kc_wpn_ak_jelly...png"
  }
  ```
- No migration needed for adding nullable JSON column (Doctrine handles this)
- Add getter/setter methods: `getKeychain()`, `setKeychain(?array $keychain)`

### Service Layer

**InventoryImportService** (`src/Service/InventoryImportService.php`)
- Add method `extractKeychainInfo(array $descriptions): ?array`
  - Parse HTML from descriptions array where `name === 'keychain_info'`
  - Extract image URL and title from HTML using regex
  - Remove "Charm: " prefix from name
  - Return array with name and image_url, or null if not found
- Update `mapSteamItemToEntity()` method:
  - Call `extractKeychainInfo()` and add to `$data['keychain']`
- Update `executeImport()` method:
  - Persist keychain data: `$itemUser->setKeychain($data['keychain'])`

**InventoryController** (`src/Controller/InventoryController.php`)
- Update `index()` method to fetch keychain prices:
  - For each itemUser, check if keychain exists
  - If keychain exists, query `ItemPrice` for the keychain by constructing market_hash_name
    - Pattern: `"Charm | {keychain_name}"` (e.g., "Charm | Die-cast AK")
  - Add keychain price to item total value
  - Pass keychain price data to template

### Commands/Controllers

No new console commands needed.

**Controller Changes:**
- `InventoryController::index()` - Add keychain price lookup and value calculation

### Frontend Changes

**Template: `templates/inventory/index.html.twig`**

Update the item card stickers overlay section (around line 91-103):
```twig
<!-- Stickers and Keychain Overlay -->
{% if itemUser.stickers or itemUser.keychain %}
    <div class="absolute bottom-2 left-2 flex gap-1">
        {% if itemUser.stickers %}
            {% for sticker in itemUser.stickers %}
                <img
                    src="{{ sticker.image_url }}"
                    alt="{{ sticker.name }}"
                    title="{{ sticker.name }}"
                    class="h-10 w-12 rounded bg-gray-800/80 p-0.5"
                >
            {% endfor %}
        {% endif %}
        {% if itemUser.keychain %}
            <img
                src="{{ itemUser.keychain.image_url }}"
                alt="{{ itemUser.keychain.name }}"
                title="Keychain: {{ itemUser.keychain.name }}"
                class="h-10 w-12 rounded bg-yellow-600/80 p-0.5 border border-yellow-500"
            >
        {% endif %}
    </div>
{% endif %}
```

**Visual Design:**
- Keychains appear after stickers in the bottom-left overlay
- Use yellow-tinted background (`bg-yellow-600/80`) to differentiate from stickers
- Add yellow border to make keychains stand out
- Use same size as stickers (h-10 w-12) for consistency

**Price Display:**
- Update the price calculation logic to show: "Base Item Price + Keychain Price"
- Consider adding a tooltip showing the breakdown

### Configuration

No environment variables or Docker configuration changes needed.

## Implementation Steps

1. **Database Schema Update**
   - Add `keychain` JSON column to `item_user` table
   - Generate migration: `docker compose exec php php bin/console make:migration`
   - Review migration file
   - Run migration: `docker compose exec php php bin/console doctrine:migrations:migrate`

2. **Update ItemUser Entity**
   - Add private `?array $keychain = null` property with ORM mapping
   - Add `#[ORM\Column(type: Types::JSON, nullable: true)]` annotation
   - Add `getKeychain(): ?array` method
   - Add `setKeychain(?array $keychain): static` method

3. **Implement Keychain Parsing in InventoryImportService**
   - Create `extractKeychainInfo(array $descriptions): ?array` method
     - Search for description with `name === 'keychain_info'`
     - Parse HTML using regex: `/<img[^>]+src="([^"]+)"[^>]+title="([^"]+)"/`
     - Extract image URL and title
     - Remove "Charm: " prefix from title
     - Return structured array or null
   - Update `mapSteamItemToEntity()` to call `extractKeychainInfo()`
   - Add keychain data to returned array

4. **Update Import Execution**
   - In `executeImport()` method, add keychain persistence:
     ```php
     if (isset($data['keychain'])) {
         $itemUser->setKeychain($data['keychain']);
     }
     ```

5. **Enhance InventoryController for Value Calculation**
   - In `index()` method, after fetching item price:
     - Check if `$itemUser->getKeychain()` exists
     - If exists, construct keychain market hash name: `"Charm | {name}"`
     - Query ItemPrice repository for keychain price
     - Add keychain price to item total value
     - Pass keychain price in `$itemsWithPrices` array

6. **Update Inventory Template**
   - Modify the stickers overlay section to include keychain display
   - Add conditional rendering for keychain with distinct styling
   - Consider adding tooltip showing value breakdown
   - Test responsive layout with stickers + keychain

7. **Test Import with Existing Data**
   - Use existing inventory JSON files for testing
   - Run import: Navigate to `/inventory/import` and upload test JSONs
   - Verify keychain data is parsed and stored correctly
   - Check database to confirm keychain JSON is populated

8. **Test Inventory Display**
   - View inventory page at `/inventory`
   - Verify keychains appear on item cards
   - Verify keychain visual styling (yellow background, border)
   - Check total value includes keychain prices
   - Test with items that have stickers only, keychain only, and both

## Testing Strategy

### Unit Tests

Not immediately required, but consider:
- Test `extractKeychainInfo()` with various HTML formats
- Test keychain data persistence in ItemUser entity
- Test value calculation with and without keychains

### Integration Tests

Not immediately required, but consider:
- Test full import flow with keychain data
- Test inventory display with keychain items

### Manual Testing

**Test Case 1: Import with Keychain Data**
- Prerequisites: Have inventory JSON with keychain items (var/data/steam-items/inventory-tradeable.json)
- Steps:
  1. Navigate to `/inventory/import`
  2. Upload inventory JSON files
  3. Review preview page
  4. Confirm import
- Expected: Import succeeds, keychain data stored in database

**Test Case 2: Verify Database Storage**
- Prerequisites: Completed Test Case 1
- Steps:
  1. Connect to MySQL: `docker compose exec mysql mysql -u root -p cs2inventory`
  2. Query: `SELECT id, asset_id, keychain FROM item_user WHERE keychain IS NOT NULL LIMIT 5;`
- Expected: Keychain JSON data visible in results

**Test Case 3: Inventory Display with Keychains**
- Prerequisites: Keychain items in database
- Steps:
  1. Navigate to `/inventory`
  2. Locate items with keychains
  3. Verify keychain image appears next to stickers
  4. Verify yellow background and border
  5. Hover over keychain to see tooltip
- Expected: Keychains display correctly with distinct styling

**Test Case 4: Value Calculation**
- Prerequisites: Keychain items with known prices in item_price table
- Steps:
  1. Identify an item with keychain in inventory
  2. Look up base item price in database
  3. Look up keychain price using "Charm | {name}" in item_price table
  4. Verify displayed price = base price + keychain price
- Expected: Total value correctly includes keychain value

**Test Case 5: Backward Compatibility**
- Prerequisites: Items without keychains in inventory
- Steps:
  1. View inventory page
  2. Verify items without keychains display normally
  3. Verify no errors or broken layouts
- Expected: Items without keychains unaffected

**Test Case 6: Empty/Missing Keychain Data**
- Prerequisites: Modify test JSON to have malformed keychain_info
- Steps:
  1. Import inventory with malformed data
  2. Check logs for warnings/errors
  3. Verify import completes successfully
- Expected: Graceful handling of invalid data, no crashes

## Edge Cases & Error Handling

1. **Missing keychain_info in descriptions**
   - Handle: Return null from `extractKeychainInfo()`, don't set keychain field
   - Impact: Item imports normally without keychain

2. **Malformed HTML in keychain_info**
   - Handle: Regex fails to match, return null, log warning
   - Impact: Keychain data skipped, import continues

3. **Keychain price not found in database**
   - Handle: Set keychain price to 0.0, don't add to total value
   - Impact: Item displays with keychain but without price contribution
   - Consider: Add logging to track missing keychain prices for future price fetch

4. **Multiple keychains in data (should not happen)**
   - Handle: Take only the first one (Steam API limitation)
   - Impact: Only one keychain stored per item

5. **Keychain image URL returns 404**
   - Handle: Browser handles broken image gracefully
   - Impact: Placeholder/broken image icon shows
   - Consider: Add fallback image or hide if load fails (optional enhancement)

6. **Very long keychain names**
   - Handle: Use CSS text-overflow for tooltip
   - Impact: Long names truncated in display

7. **Items with many stickers + keychain**
   - Handle: Ensure overlay doesn't overflow or obscure item image
   - Impact: May need to adjust layout or use scrolling (test with 4 stickers + 1 keychain)

## Dependencies

- No external dependencies required
- Uses existing Doctrine ORM for database
- Uses existing Symfony Twig for templating
- Uses existing ItemPrice entity for price lookups

**Blocking Issues:**
- None - can be implemented immediately

**Related Systems:**
- Item price fetching system (may need to add keychain items to price scraper later)
- Steam inventory import system (already handles similar sticker parsing)

## Acceptance Criteria

- [ ] Database migration created and applied successfully
- [ ] `ItemUser` entity has `keychain` field with getters/setters
- [ ] `InventoryImportService` parses keychain data from Steam JSON
- [ ] Keychain data persists to database during import
- [ ] Inventory page displays keychain images on item cards
- [ ] Keychains have distinct yellow styling (background + border)
- [ ] Item total value includes keychain price from `item_price` table
- [ ] Overall inventory total value includes all keychain values
- [ ] Items without keychains display normally (backward compatibility)
- [ ] Malformed or missing keychain data handled gracefully (no crashes)
- [ ] Manual testing completed for all test cases
- [ ] No console errors or warnings in browser
- [ ] No PHP errors or warnings in logs

## Notes & Considerations

### Keychain Naming Convention
Steam uses the market hash name format: **"Charm | {keychain_name}"**
Examples:
- "Charm | Die-cast AK"
- "Charm | Piñatita"

This format must be used when querying the `item_price` table.

### Future Improvements
- Add a dedicated keychain price scraper (similar to item price scraper)
- Add admin interface to manage keychain prices manually
- Add visual indicator when keychain price is missing
- Consider adding keychain rarity/collection information
- Add search/filter by keychain in inventory
- Show keychain price breakdown in tooltip or detail modal

### Visual Design Considerations
- Keychains should be visually distinct from stickers
- Yellow background chosen to represent "charm" aesthetic
- Border helps separate keychain from sticker images
- Consider adding a small icon/label for clarity

### Performance Considerations
- Keychain price lookup adds one additional query per item with keychain
- For large inventories (100+ items), consider:
  - Batch fetching keychain prices
  - Caching keychain price data
  - Lazy loading keychain images

### API Parsing Notes
The keychain HTML structure is similar to stickers:
- Found in `descriptions` array with `name: "keychain_info"`
- Contains HTML with image tag and title
- Unlike stickers, keychains have a wrapper div with `id="keychain_info"`
- Only one keychain per item (unlike stickers which can have up to 5)

## Related Tasks

- (Future) Task 2: Implement keychain price scraper
- (Future) Task 3: Add keychain filter to inventory page