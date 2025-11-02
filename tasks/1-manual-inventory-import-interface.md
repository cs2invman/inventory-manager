# Manual Inventory Import Interface

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-02

## Overview

Create a web interface that allows users to manually import their CS2 Steam inventory by pasting JSON data from Steam's inventory API. The interface will accept two separate inputs: tradeable items (contextid=2) and trade-locked items (contextid=16), parse and preview the data, then allow users to confirm before storing it in their inventory database.

## Problem Statement

Currently, there is no way for users to import their CS2 inventory into the system. Users need to manually paste Steam API responses to populate their inventory, which will:
- Allow users to track their items in the system
- Enable price tracking and profit/loss calculations
- Provide a foundation for future automated sync features

## Requirements

### Functional Requirements

1. **UI Interface - Input Form**
   - Two large textarea inputs for pasting JSON data:
     - Tradeable items (contextid=2)
     - Trade-locked items (contextid=16)
   - Display helpful instructions with API URLs
   - Client-side JSON validation (real-time feedback using Alpine.js)
   - Visual indicators (green checkmark / red X) for valid/invalid JSON
   - Submit button to parse and preview the import

2. **UI Interface - Preview/Confirmation Page**
   - Display parsed inventory summary:
     - Total item count
     - Count by rarity (Covert, Classified, Restricted, etc.)
     - Count by type (Knife, Rifle, Pistol, Agent, etc.)
     - Notable items (knives, gloves, high-value items)
   - Show items that will be removed (current items not in import)
   - Show items that will be added (new items in import)
   - Warn about items that couldn't be matched in database
   - Confirm and Cancel buttons

3. **Data Processing**
   - Parse JSON from both textareas
   - Validate JSON structure server-side
   - Map Steam API fields to database entities
   - Handle missing or optional fields gracefully
   - Store parsed data in session for preview

4. **Import Behavior**
   - Replace all existing inventory items for the user
   - Skip automatic price fetching (performance optimization)
   - Do NOT track trade-lock status (ignore contextid differences)

5. **Error Handling**
   - Invalid JSON format (client-side and server-side)
   - Missing required API fields
   - Item not found in database
   - Database constraints violations

### Non-Functional Requirements

- **Security**: Only authenticated users can import inventories
- **Performance**: Process large inventories (200+ items) efficiently
- **Usability**: Clear error messages and helpful instructions
- **Data Integrity**: Use database transactions to ensure all-or-nothing imports

## Technical Approach

### Database Changes

**No database changes required**. All necessary fields already exist in the ItemUser entity.

### Service Layer

**New Service**: `InventoryImportService` (`src/Service/InventoryImportService.php`)

Responsibilities:
- Parse Steam inventory JSON responses
- Generate preview data with statistics
- Map API data to ItemUser entities
- Find or identify items in the database
- Handle the "replace all" import strategy
- Provide detailed import results/statistics

Key Methods:
```php
// Parse and prepare preview data (does not persist to database)
public function prepareImportPreview(User $user, string $tradeableJson, string $tradeLockedJson): ImportPreview

// Execute the actual import from session data
public function executeImport(User $user, string $sessionKey): ImportResult

// Parse inventory JSON response
public function parseInventoryResponse(array $jsonData): array

// Map Steam item data to ItemUser entity data
private function mapSteamItemToEntity(array $asset, array $description, ?array $assetProperty): array

// Find Item entity by classid
private function findItemByClassId(string $classId): ?Item

// Extract sticker information from HTML in descriptions
private function extractStickerInfo(array $descriptions): ?array

// Extract name tag from descriptions
private function extractNameTag(array $descriptions): ?string

// Extract float value and pattern from asset_properties
private function extractFloatAndPattern(array $assetProperties): array

// Generate statistics for preview
private function generatePreviewStats(array $parsedItems, array $currentInventory): array
```

**ImportPreview DTO** (`src/DTO/ImportPreview.php`)
```php
class ImportPreview {
    public int $totalItems;
    public int $itemsToAdd;
    public int $itemsToRemove;
    public array $statsByRarity;    // ['Covert' => 5, 'Classified' => 10, ...]
    public array $statsByType;      // ['Knife' => 2, 'Rifle' => 15, ...]
    public array $notableItems;     // [['name' => '...', 'rarity' => '...'], ...]
    public array $unmatchedItems;   // Items that couldn't be found in database
    public array $errors;           // Parse/validation errors
    public string $sessionKey;      // Key to retrieve data from session later
}
```

**ImportResult DTO** (`src/DTO/ImportResult.php`)
```php
class ImportResult {
    public int $totalProcessed;
    public int $successCount;
    public int $errorCount;
    public array $errors;
    public array $skippedItems;     // Items that couldn't be matched
}
```

### Controller

**New Controller**: `InventoryImportController` (`src/Controller/InventoryImportController.php`)

Routes:
- `GET /inventory/import` - Display the import form
- `POST /inventory/import/preview` - Parse JSON and show preview page
- `POST /inventory/import/confirm` - Execute the actual import
- `POST /inventory/import/cancel` - Cancel and clear session data

Flow:
1. User visits `/inventory/import` and pastes JSON data
2. User submits form → POST to `/inventory/import/preview`
3. Controller calls `InventoryImportService::prepareImportPreview()`
4. Service stores parsed data in session and returns preview DTO
5. Controller renders preview template with statistics
6. User clicks "Confirm" → POST to `/inventory/import/confirm`
7. Controller calls `InventoryImportService::executeImport()` with session key
8. Service retrieves data from session and persists to database
9. Controller displays success message and redirects to inventory/dashboard

### Frontend Changes

**New Template**: `templates/inventory/import.html.twig` (Input Form)

Structure:
- Extend base layout
- Two-column layout (or stacked on mobile)
- Form with two textareas
- Alpine.js component for JSON validation
- Helpful instructions section with:
  - API URL examples with user's Steam ID placeholder
  - JSON format explanation
  - Step-by-step guide
- Real-time validation indicators (green checkmark / red X)
- Submit button disabled until both textareas have valid JSON

**New Template**: `templates/inventory/import_preview.html.twig` (Preview Page)

Structure:
- Extend base layout
- Summary statistics cards (total items, by rarity, by type)
- Notable items section (knives, gloves, high-value)
- Warning section for unmatched items
- Side-by-side comparison:
  - Items to be added (green)
  - Items to be removed (red)
- Confirm/Cancel action buttons
- Hidden form with session key for confirmation POST

**Styling**: Use Tailwind CSS classes consistent with existing design (dark theme)

### Configuration

No new environment variables needed.

## Implementation Steps

1. **Create DTOs**
   - Create `src/DTO/ImportPreview.php` with all preview data fields
   - Create `src/DTO/ImportResult.php` with import result fields
   - Include proper type hints and documentation

2. **Create Inventory Import Service**
   - Create `src/Service/InventoryImportService.php`
   - Inject dependencies: EntityManagerInterface, ItemRepository, InventoryService
   - Implement `prepareImportPreview()` method:
     - Parse both JSON inputs
     - Combine assets from both contextids
     - Match assets with descriptions by classid/instanceid
     - Match with asset_properties if available
     - Lookup Item entities by classid
     - Generate statistics (by rarity, type, notable items)
     - Compare with current inventory to detect additions/removals
     - Store parsed data in session
     - Return ImportPreview DTO
   - Implement `executeImport()` method:
     - Retrieve parsed data from session
     - Begin database transaction
     - Delete all existing user inventory items
     - Create new ItemUser entities from parsed data
     - Commit transaction
     - Clear session data
     - Return ImportResult DTO
   - Implement field mapping from Steam API structure:
     - **assets array**: assetid, classid, instanceid
     - **descriptions array**: item details, name, market_hash_name, tags, stickers
     - **asset_properties array**: float_value (propertyid=2), pattern (propertyid=1)
   - Extract sticker information from HTML in descriptions
   - Extract name tags from descriptions
   - DO NOT extract or store trade-lock status
   - Add comprehensive error handling and logging

3. **Update Item Repository (if needed)**
   - Add method to find Item by classid: `findByClassId(string $classId): ?Item`
   - This may already exist; verify first

4. **Create Import Controller**
   - Create `src/Controller/InventoryImportController.php`
   - Inject dependencies: InventoryImportService, SessionInterface
   - Implement routes:
     - `GET /inventory/import` → Render import form
     - `POST /inventory/import/preview` → Parse JSON, generate preview, render preview page
     - `POST /inventory/import/confirm` → Execute import, show success message
     - `POST /inventory/import/cancel` → Clear session, redirect to form
   - Add form validation for JSON structure
   - Handle JSON parsing errors gracefully
   - Use flash messages for success/error notifications

5. **Create Import Form Template**
   - Create `templates/inventory/import.html.twig`
   - Design two-column (or stacked mobile) form layout
   - Add two large textareas with labels:
     - "Tradeable Items JSON (contextid=2)"
     - "Trade-Locked Items JSON (contextid=16)"
   - Add Alpine.js component for real-time JSON validation:
     ```javascript
     x-data="{
       tradeableJson: '',
       tradeLockedJson: '',
       isValidTradeable: false,
       isValidTradeLocked: false,
       validateJson(text) {
         try {
           JSON.parse(text);
           return true;
         } catch {
           return false;
         }
       }
     }"
     ```
   - Display validation indicators (✓ green or ✗ red) next to each textarea
   - Disable submit button if either JSON is invalid
   - Add instructional section with API URLs
   - Style with Tailwind dark theme
   - Add loading state during submission

6. **Create Preview Template**
   - Create `templates/inventory/import_preview.html.twig`
   - Display summary statistics cards:
     - Total items count
     - Count by rarity (pie chart or list)
     - Count by type (list)
   - Display notable items (Knives, Gloves, high-rarity items)
   - Display warning section for unmatched items
   - Display comparison sections:
     - "Items to be added" (green-themed)
     - "Items to be removed" (red-themed)
   - Add confirm button (POST to /inventory/import/confirm)
   - Add cancel button (POST to /inventory/import/cancel)
   - Include hidden form field with session key
   - Style with Tailwind dark theme

7. **Update Navigation**
   - Add "Import Inventory" link to main navigation
   - Update `templates/dashboard/index.html.twig` or base layout
   - Use appropriate icon (upload/import icon)

8. **Testing**
   - Test with sample JSON files (tradeable and trade-locked)
   - Test with invalid JSON
   - Test with empty inventory
   - Test preview page rendering
   - Test cancel flow
   - Test confirm flow
   - Verify transaction rollback on errors

9. **Add Tests** (if time permits)
   - Unit tests for InventoryImportService
   - Test JSON parsing with various structures
   - Test field mapping with edge cases
   - Test session storage and retrieval
   - Test error handling for missing required fields
   - Test transaction rollback

## Field Mapping Details

### Steam API → Database Mapping

Based on the sample JSON files provided:

**From `assets` array:**
- `assetid` → `ItemUser.assetId`
- `classid` → Used to find matching `Item` entity
- `instanceid` → Used to find matching `Item` entity
- `contextid` → **IGNORED** (not stored in database)

**From `descriptions` array** (matched by classid/instanceid):
- `name` → Matched against `Item.name` (for verification)
- `market_hash_name` → Used to find `Item.hashName`
- `icon_url` → Could update `Item.imageUrl` (optional)
- `tradable` → **IGNORED** (not stored in database)
- `tags[]` → Extract category, weapon, exterior, quality, rarity
  - Find tag with `category: "Exterior"` → Use `internal_name` to determine wear category
  - Find tag with `localized_tag_name: "StatTrak™"` → Set `ItemUser.isStattrak`
  - Find tag with `localized_tag_name: "Souvenir"` → Set `ItemUser.isSouvenir`

**From `descriptions` array (nested descriptions field):**
- Find description with `name: "nametag"` → `ItemUser.nameTag` (parse from value)
- Find description with `name: "sticker_info"` → Parse HTML to extract `ItemUser.stickers`

**From `asset_properties` array:**
- Find property with `propertyid: 2` → `ItemUser.floatValue` (from `float_value`)
- Find property with `propertyid: 1` → `ItemUser.patternIndex` (from `int_value`)

**From `actions` array:**
- Extract inspect link → `ItemUser.inspectLink`

### Missing/Calculated Fields

Fields that **cannot** be determined from Steam API (leave as NULL):
- `ItemUser.paintSeed` - Not available in basic inventory API
- `ItemUser.storageBoxName` - User-defined, not from Steam
- `ItemUser.stattrakCounter` - Not available in basic inventory API
- `ItemUser.acquiredDate` - Not available from Steam
- `ItemUser.acquiredPrice` - User-defined
- `ItemUser.currentMarketValue` - Not fetching during import (per requirements)
- `ItemUser.notes` - User-defined

Fields that **are automatically calculated**:
- `ItemUser.wearCategory` - Calculated from floatValue by entity lifecycle callback
- `ItemUser.createdAt` - Set by entity constructor
- `ItemUser.updatedAt` - Set by entity constructor

## Testing Strategy

### Manual Testing Scenarios

1. **Happy Path**
   - Login as a user
   - Navigate to import page
   - Paste valid JSON for both tradeable and trade-locked inventories
   - Submit form
   - Verify success message shows correct item counts
   - Check database to confirm items were created
   - Verify existing items were deleted

2. **Empty Inventory Import**
   - Paste empty arrays `{"assets":[], "descriptions":[]}`
   - Verify all existing items are deleted
   - Verify success message

3. **Invalid JSON**
   - Paste malformed JSON
   - Verify error message displays
   - Verify no database changes occurred

4. **Partial Data**
   - Paste only tradeable items (leave trade-locked empty)
   - Verify import succeeds with only tradeable items

5. **Items Not in Database**
   - Paste JSON with items not in `item` table
   - Verify graceful handling (skip or error message)

6. **Large Inventory**
   - Test with 200+ items
   - Verify performance is acceptable (< 5 seconds)
   - Check memory usage

### Unit Tests (Optional)

- Test JSON parsing with various structures
- Test field mapping with edge cases
- Test error handling for missing required fields
- Test transaction rollback on error

## Edge Cases & Error Handling

### Edge Case 1: Item ClassID Not Found in Database
**Scenario**: Steam API returns an item that doesn't exist in the `item` table.

**Solution**:
- Log a warning with the classid and item name
- Add to errors array in ImportResult
- Continue processing other items (don't fail entire import)
- Display list of skipped items to user

### Edge Case 2: Duplicate Asset IDs
**Scenario**: The same assetId appears in both tradeable and trade-locked data.

**Solution**:
- When merging both inventories, deduplicate by assetId
- Keep only the first occurrence of each assetId
- Log a warning if duplicates are detected
- Continue processing without failing the import

### Edge Case 3: Malformed Sticker HTML
**Scenario**: Sticker information HTML doesn't match expected format.

**Solution**:
- Use try-catch around HTML parsing
- Log parsing error
- Set stickers to null for that item
- Continue processing

### Edge Case 4: Missing asset_properties
**Scenario**: Item has no asset_properties array (no float value/pattern).

**Solution**:
- These fields are nullable in database
- Simply leave them as null
- This is normal for items without wear (agents, stickers, etc.)

### Edge Case 5: Database Constraint Violation
**Scenario**: Unique constraint on assetId fails.

**Solution**:
- Use transaction to rollback entire import
- Display clear error message to user
- Suggest clearing existing inventory first

### Edge Case 6: Very Large JSON Input
**Scenario**: User pastes massive JSON (e.g., multiple inventories combined).

**Solution**:
- Set reasonable PHP memory limit
- Set request timeout limit
- Add client-side character count display
- Consider pagination or batch processing for > 500 items

## Dependencies

### Internal Dependencies
- Existing `Item` entities must be populated in database
- User must be authenticated
- ItemUser, Item, User entities must exist

### External Dependencies
- None (users manually provide JSON, no external API calls)

### Blocking Issues
- Need to verify that Item entities have been synchronized from SteamWebAPI
- If Item table is empty, imports will fail

## Acceptance Criteria

- [ ] User can access inventory import page at `/inventory/import`
- [ ] Page displays two clearly labeled textareas with instructions
- [ ] API URL examples are provided with proper formatting
- [ ] User can paste JSON data for both tradeable and trade-locked items
- [ ] Client-side JSON validation works with real-time feedback (green ✓ / red ✗)
- [ ] Submit button is disabled until both textareas contain valid JSON
- [ ] Form submission triggers preview page (POST to `/inventory/import/preview`)
- [ ] Preview page displays comprehensive statistics:
  - [ ] Total item count
  - [ ] Count by rarity (Covert, Classified, etc.)
  - [ ] Count by type (Knife, Rifle, etc.)
  - [ ] Notable items (knives, gloves)
  - [ ] Items to be added (green)
  - [ ] Items to be removed (red)
  - [ ] Unmatched items warning
- [ ] Preview page has Confirm and Cancel buttons
- [ ] Clicking Cancel clears session and returns to import form
- [ ] Clicking Confirm executes the import (POST to `/inventory/import/confirm`)
- [ ] Import service retrieves parsed data from session
- [ ] All existing inventory items are deleted before import
- [ ] New items are created with correct field mappings:
  - [ ] assetId is stored correctly
  - [ ] Item is matched by classid/instanceid
  - [ ] Float values are extracted and stored
  - [ ] Pattern indices are extracted and stored
  - [ ] Stickers are parsed from HTML and stored as JSON
  - [ ] Name tags are extracted and stored
  - [ ] StatTrak status is detected from item name or tags
  - [ ] Souvenir status is detected from tags
  - [ ] Inspect links are extracted and stored
  - [ ] Wear category is automatically calculated from float value
  - [ ] Trade-lock status fields are NOT stored (contextid and tradable ignored)
- [ ] Import process is wrapped in database transaction
- [ ] Success message displays total item count
- [ ] Error messages display for invalid JSON or processing errors
- [ ] Items not found in database are logged and reported to user
- [ ] Import completes in reasonable time for 200+ items
- [ ] Session data is cleared after successful import
- [ ] Navigation includes link to import page
- [ ] Both pages use consistent Tailwind styling with dark theme
- [ ] Mobile responsive design
- [ ] Loading state shown during processing

## Notes & Considerations

### Future Enhancements
1. **Automated Sync**: Build on this manual import to create automated Steam API sync
2. **Price Fetching**: Add option to fetch current market prices after import
3. **Differential Sync**: Instead of "replace all", implement smart sync that detects additions/removals
4. **Import History**: Track when inventories were last imported
5. **Validation Preview**: Show preview of parsed items before confirming import
6. **Storage Box Detection**: Parse storage container information if available in API
7. **Progress Bar**: For large imports, show real-time progress

### Known Limitations
1. Cannot get exact acquired dates from Steam API
2. Cannot get purchase prices from Steam API
3. Paint seed values not available in basic inventory API (need inspect API)
4. StatTrak counter values not available in basic inventory API

### Security Considerations
- Sanitize all JSON input to prevent injection attacks
- Validate user ownership (don't allow importing for other users)
- Rate limit the import endpoint to prevent abuse
- Consider adding CSRF protection to form

### Performance Considerations
- Use batch inserts if processing > 100 items
- Consider using Symfony Messenger for async processing if imports take > 5 seconds
- Index `classid` field on Item table for faster lookups
- Use database transaction but commit in batches if needed

## Related Tasks

- **Task 2**: Automated Steam Inventory Synchronization (future)
- **Task 3**: Item Price Auto-Update Integration (future)
- **Task 4**: Inventory Value Dashboard (depends on this task)