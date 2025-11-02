# Steam Item Sync via SteamWebAPI

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Large
**Created**: 2025-11-02

## Overview

Implement a two-phase system for syncing CS2 item data from SteamWebAPI.com:
1. **Download Phase**: Console command that fetches all CS2 items from the API and stores the raw JSON response to a local file
2. **Sync Phase**: Console command that reads the JSON file and synchronizes item and price data to the database

This approach minimizes API calls and allows for flexible data processing without repeatedly hitting the external API.

## Problem Statement

Currently, the system lacks an automated way to discover and track all available CS2 items and their market data. The SteamWebAPI.com provides comprehensive item information including metadata, pricing history, and sales statistics. We need a reliable, efficient system to:
- Fetch the complete CS2 item catalog
- Store item metadata in the database
- Track price history over time
- Handle items that are added or removed from the marketplace
- Minimize API calls through local caching

## Requirements

### Functional Requirements
- Download complete CS2 item data from `https://www.steamwebapi.com/steam/api/items?key=YOUR_API_KEY&game=cs2`
- Store raw JSON response to the filesystem for later processing
- Parse JSON data and sync items to the `item` table
- Create price history records in the `item_price` table from API price data
- Mark items as inactive if they no longer appear in API responses
- Support idempotent operations (safe to run multiple times)
- Provide detailed console output with progress indicators
- Handle large datasets efficiently (potentially thousands of items)

### Non-Functional Requirements
- **Performance**: Process thousands of items within reasonable time (< 5 minutes for full sync)
- **Reliability**: Implement retry logic with exponential backoff for API failures
- **Data Integrity**: Use database transactions to ensure all-or-nothing commits
- **Maintainability**: Clean separation between download and sync logic
- **Monitoring**: Comprehensive logging for debugging and monitoring
- **Scalability**: Design to handle growing item catalogs

## Technical Approach

### Database Changes

#### Item Entity Modifications
Add new fields to support API data:
- `active` (boolean, default: true) - Track if item is currently available
- `externalId` (string, unique, indexed) - Maps to API's `id` field (e.g., "0019b31d") for deduplication
- `marketHashName` (string) - Maps to API's `markethashname` - Already exists as `hashName`
- `marketName` (string, nullable) - Maps to API's `marketname` - May differ from hashname
- `slug` (string, nullable) - Maps to API's `slug` - URL-friendly identifier from API
- `classId` (string, nullable) - Maps to API's `classid` - Steam class ID
- `instanceId` (string, nullable) - Maps to API's `instanceid` - Steam instance ID
- `groupId` (string, nullable) - Maps to API's `groupid` - Item group identifier
- `borderColor` (string, nullable, length 6) - Maps to API's `bordercolor` - UI border color hex (no # prefix)
- `itemColor` (string, nullable, length 6) - Maps to API's `color` - Item rarity/quality color hex (no # prefix)
- `quality` (string, nullable, length 50) - Maps to API's `quality` - Quality descriptor (e.g., "StatTrak™")
- `points` (integer, nullable) - Maps to API's `points` - API points/score system (e.g., 3688)

**Note**: The API response already includes `rarity` (maps to existing field) and `itemimage` (maps to existing `imageUrl` field).

#### ItemPrice Entity Considerations
Current fields already support most price data:
- `price` - Map to `pricelatestsell`
- `medianPrice` - Map to `pricemedian`
- `lowestPrice` - Map to `pricemin`
- `highestPrice` - Map to `pricemax`
- `volume` - Map to `soldtotal` or `sold30d`
- `source` - Set to "steamwebapi"

Additional price data that could be stored (optional):
- Average prices (7d, 30d, 90d)
- Buy order information
- Sales velocity metrics

#### Complete API Response Field Mapping

Based on actual API response from `tasks/items-sample.json`, here's the complete field mapping:

**Item Metadata Fields:**
```
API Field → Database Field (Item entity)
=========================================
id → externalId (string, unique)
markethashname → hashName (existing field)
marketname → marketName (new field, nullable)
slug → slug (new field, nullable)
classid → classId (new field, nullable)
instanceid → instanceId (new field, nullable)
groupid → groupId (new field, nullable)
bordercolor → borderColor (new field, nullable, no # prefix)
color → itemColor (new field, nullable, no # prefix)
quality → quality (new field, nullable)
rarity → rarity (existing field)
itemimage → imageUrl (existing field)
points → points (new field, nullable, integer)
```

**Price Fields (for ItemPrice entity):**
```
API Field → Database Field (ItemPrice entity)
=============================================
pricelatestsell → price (primary price field)
pricemedian → medianPrice
pricemin → lowestPrice
pricemax → highestPrice
soldtotal → volume (or sold30d)
priceupdatedat → priceDate (parse datetime object)
```

**Additional Price Fields (available but not currently mapped):**
- `pricelatest`, `pricelatestsell24h/7d/30d/90d` - Historical latest prices
- `pricemedian24h/7d/30d/90d` - Historical median prices
- `priceavg`, `priceavg24h/7d/30d/90d` - Average prices
- `pricesafe` - Safe price estimate
- `pricemix` - Mixed price calculation
- `buyorderprice`, `buyordermedian`, `buyorderavg`, `buyordervolume` - Buy order data
- `offervolume` - Current offers
- `soldtoday`, `sold24h/7d/30d/90d` - Sales velocity
- `lateststeamsellat` - Last sale timestamp
- `hourstosold` - Time to sell estimate

**Special Fields (informational only, not stored):**
- `infoprice` - Descriptive text about price data source

**Important Notes:**
1. The `priceupdatedat` field is an object with structure: `{date, timezone_type, timezone}`
2. The `lateststeamsellat` has the same datetime object structure
3. Colors (`bordercolor`, `color`) are hex values WITHOUT the `#` prefix (e.g., "99ccff", "eb4b4b")
4. Many price fields can be `null` - handle gracefully
5. The API response is an array of items (top-level is array, not object)

### Service Layer

#### SteamWebApiClient Service
**Location**: `src/Service/SteamWebApiClient.php`

Responsibilities:
- Make HTTP requests to SteamWebAPI.com
- Handle API authentication (API key from environment)
- Implement retry logic with exponential backoff (3 attempts, 2s initial delay)
- Return raw JSON response or throw specific exceptions
- Log all API interactions

Key methods:
- `fetchAllItems(): string` - Returns JSON string of all CS2 items
- Inject Symfony HttpClient for HTTP operations
- Use PSR-3 LoggerInterface for logging

#### ItemSyncService
**Location**: `src/Service/ItemSyncService.php`

Responsibilities:
- Parse JSON data from file
- Map API fields to Item entity properties
- Handle item creation and updates
- Mark missing items as inactive
- Create ItemPrice records from price data
- Track sync statistics (added, updated, deactivated)
- Manage database transactions

Key methods:
- `syncFromJsonFile(string $filePath): array` - Returns sync statistics
- `processItem(array $itemData): void` - Process single item
- `createPriceHistory(Item $item, array $priceData): void` - Create price records
- `deactivateMissingItems(array $currentIds): int` - Mark items not in sync as inactive

### Console Commands

#### app:steam:download-items
**Location**: `src/Command/SteamDownloadItemsCommand.php`

Purpose: Download raw JSON data from API and save to file

Arguments:
- None

Options:
- `--output-dir` - Override default storage directory (default: `var/data/steam-items`)
- `--force` - Download even if recent file exists

Behavior:
1. Create output directory if it doesn't exist
2. Call SteamWebApiClient to fetch data
3. Generate timestamped filename: `items-{Y-m-d-His}.json`
4. Write JSON response to file
5. Create/update symlink `items-latest.json` pointing to newest file
6. Display success message with file path and size
7. Handle errors gracefully with retry logic

Output format:
```
Downloading CS2 items from SteamWebAPI...
✓ Downloaded 15,432 items (2.3 MB)
✓ Saved to: var/data/steam-items/items-2025-11-02-143022.json
✓ Updated symlink: var/data/steam-items/items-latest.json
```

#### app:steam:sync-items
**Location**: `src/Command/SteamSyncItemsCommand.php`

Purpose: Parse JSON file and sync data to database

Arguments:
- `file` (optional) - Path to JSON file (default: uses items-latest.json symlink)

Options:
- `--dry-run` - Show what would be changed without committing
- `--skip-prices` - Only sync item metadata, skip price history

Behavior:
1. Validate JSON file exists and is readable
2. Parse JSON data
3. Begin database transaction
4. Iterate through items with progress bar
5. For each item:
   - Check if exists by `externalId` or `hashName`
   - Create new or update existing Item entity
   - Create ItemPrice record with current data
6. Mark items not in JSON as inactive
7. Commit transaction
8. Display sync statistics

Output format:
```
Syncing items from: var/data/steam-items/items-latest.json
Processing items... [============================] 100% (15,432/15,432)

Sync complete:
  • Added: 127 items
  • Updated: 15,245 items
  • Deactivated: 60 items
  • Price records created: 15,372
  • Skipped: 0 items
  • Duration: 2m 34s
```

### Configuration

#### Environment Variables
Add to `.env`:
```
###> SteamWebAPI Configuration ###
STEAM_WEB_API_KEY=your_api_key_here
STEAM_WEB_API_BASE_URL=https://www.steamwebapi.com/steam/api
STEAM_ITEMS_STORAGE_PATH=var/data/steam-items
###< SteamWebAPI Configuration ###
```

#### Service Configuration
In `config/services.yaml`:
```yaml
parameters:
    steam_web_api.base_url: '%env(STEAM_WEB_API_BASE_URL)%'
    steam_web_api.key: '%env(STEAM_WEB_API_KEY)%'
    steam_items.storage_path: '%kernel.project_dir%/%env(STEAM_ITEMS_STORAGE_PATH)%'

services:
    App\Service\SteamWebApiClient:
        arguments:
            $baseUrl: '%steam_web_api.base_url%'
            $apiKey: '%steam_web_api.key%'
```

### Docker Configuration

No Docker changes required - commands run within existing PHP container:
```bash
docker compose exec php php bin/console app:steam:download-items
docker compose exec php php bin/console app:steam:sync-items
```

### Cron Job Integration

Add to system cron or use Symfony Scheduler:
```bash
# Download items every 30 minutes
*/30 * * * * docker compose exec -T php php bin/console app:steam:download-items

# Sync items 5 minutes after download
5,35 * * * * docker compose exec -T php php bin/console app:steam:sync-items
```

Or using Symfony Messenger (recommended):
- Schedule download as recurring message every 30 minutes
- Schedule sync 5 minutes after download completes

## Implementation Steps

### 1. Database Schema Updates
**Substeps:**
1. Modify `src/Entity/Item.php`:
   - Add `active` field (boolean, default true, indexed)
   - Add `externalId` field (string, unique, indexed, length 50)
   - Add `marketName` field (string, nullable, length 255)
   - Add `slug` field (string, nullable, length 255)
   - Add `classId` field (string, nullable, length 50)
   - Add `instanceId` field (string, nullable, length 50)
   - Add `groupId` field (string, nullable, length 50)
   - Add `borderColor` field (string, nullable, length 6)
   - Add `itemColor` field (string, nullable, length 6)
   - Add `quality` field (string, nullable, length 50)
   - Add `points` field (integer, nullable)
   - Update getters/setters for all new fields
   - Add index on `active` field for efficient queries
   - Add index on `externalId` for fast lookups
2. Generate migration: `php bin/console make:migration`
3. Review migration SQL for correctness
4. Document new fields in entity docblocks with API field mappings

### 2. Create SteamWebApiClient Service
**Substeps:**
1. Create `src/Service/SteamWebApiClient.php`
2. Inject `HttpClientInterface` and `LoggerInterface`
3. Implement constructor with base URL and API key parameters
4. Implement `fetchAllItems()` method:
   - Build request URL with API key and game parameter
   - Set timeout (60 seconds for large response)
   - Implement retry logic (3 attempts, exponential backoff: 2s, 4s, 8s)
   - Log each attempt and result
   - Return JSON string on success
   - Throw custom `SteamWebApiException` on failure
5. Add comprehensive error handling:
   - Network errors
   - Timeout errors
   - Invalid JSON responses
   - HTTP error codes
6. Create custom exception: `src/Exception/SteamWebApiException.php`

### 3. Create ItemSyncService
**Substeps:**
1. Create `src/Service/ItemSyncService.php`
2. Inject `EntityManagerInterface` and `LoggerInterface`
3. Implement `syncFromJsonFile(string $filePath): array`:
   - Read and parse JSON file
   - Validate JSON structure
   - Begin transaction
   - Process each item
   - Collect statistics
   - Commit transaction
   - Return statistics array
4. Implement `processItem(array $itemData): void`:
   - Map API fields to Item entity
   - Check for existing item by `externalId` or `hashName`
   - Create new or update existing entity
   - Handle null/missing fields gracefully
   - Validate data integrity
5. Implement `createPriceHistory(Item $item, array $priceData): void`:
   - Extract price fields from API data
   - Create ItemPrice entity
   - Set source to "steamwebapi"
   - Set price date from `priceupdatedat` or current time
   - Persist entity
6. Implement `deactivateMissingItems(array $currentIds): int`:
   - Find all active items not in current ID list
   - Set `active` = false
   - Return count of deactivated items
7. Add field mapping documentation in docblocks

### 4. Create Download Command
**Substeps:**
1. Create `src/Command/SteamDownloadItemsCommand.php`
2. Extend `Command` and add `#[AsCommand]` attribute
3. Inject `SteamWebApiClient`, `LoggerInterface`, and storage path parameter
4. Implement `configure()`:
   - Set command name: `app:steam:download-items`
   - Set description
   - Add `--output-dir` option
   - Add `--force` option
5. Implement `execute()`:
   - Create SymfonyStyle for output
   - Determine output directory
   - Check if directory exists, create if needed
   - Check for recent file (skip if < 25 minutes old, unless --force)
   - Display "Downloading..." message
   - Call API client
   - Generate timestamped filename
   - Write JSON to file
   - Create/update symlink to latest file
   - Display success message with file size
   - Handle exceptions and display error messages
   - Return appropriate exit codes
6. Add progress indicators for long operations
7. Implement file rotation (keep last 7 days of downloads)

### 5. Create Sync Command
**Substeps:**
1. Create `src/Command/SteamSyncItemsCommand.php`
2. Extend `Command` and add `#[AsCommand]` attribute
3. Inject `ItemSyncService`, `LoggerInterface`, and storage path parameter
4. Implement `configure()`:
   - Set command name: `app:steam:sync-items`
   - Set description
   - Add optional `file` argument
   - Add `--dry-run` option
   - Add `--skip-prices` option
5. Implement `execute()`:
   - Create SymfonyStyle for output
   - Determine input file (argument or default to items-latest.json)
   - Validate file exists and is readable
   - Display file info (path, size, age)
   - If dry-run, load and validate JSON only
   - Create progress bar for item processing
   - Call ItemSyncService
   - Display detailed statistics table
   - Handle exceptions gracefully
   - Return appropriate exit codes
6. Add memory usage monitoring for large datasets
7. Implement --verbose mode for detailed output

### 6. Environment Configuration
**Substeps:**
1. Update `.env`:
   - Add STEAM_WEB_API_KEY
   - Add STEAM_WEB_API_BASE_URL
   - Add STEAM_ITEMS_STORAGE_PATH
2. Update `.env.example` with same variables (empty values)
3. Document required environment variables in README
4. Add validation for required env vars in services

### 7. Service Configuration
**Substeps:**
1. Update `config/services.yaml`:
   - Add parameters for API config
   - Configure SteamWebApiClient with constructor arguments
   - Configure commands with storage path
2. Ensure proper dependency injection
3. Set services as public if needed for commands

### 8. Storage Directory Setup
**Substeps:**
1. Create `var/data/steam-items/` directory
2. Add `.gitignore` entry for JSON files but keep directory
3. Update Docker volume configuration if needed
4. Set proper file permissions

### 9. Database Migration
**Substeps:**
1. Review generated migration file
2. Test migration on development database
3. Run migration: `php bin/console doctrine:migrations:migrate`
4. Verify schema changes
5. Document rollback procedure

### 10. Integration Testing
**Substeps:**
1. Test download command:
   - Successful download
   - API failure handling
   - File creation and symlink
   - Force flag behavior
2. Test sync command:
   - Fresh database sync (all new items)
   - Incremental sync (some existing items)
   - Dry-run mode
   - Skip-prices mode
   - Invalid JSON file
3. Test together:
   - Download then sync
   - Verify data accuracy
   - Check price history creation
4. Test edge cases:
   - Empty API response
   - Malformed JSON
   - Duplicate items
   - Missing required fields
   - Very large datasets

## Testing Strategy

### Unit Tests

#### SteamWebApiClient Tests
- Mock HTTP client responses
- Test successful API call
- Test retry logic on transient failures
- Test timeout handling
- Test invalid JSON response
- Test authentication failures

#### ItemSyncService Tests
- Test item creation from API data
- Test item updates (existing items)
- Test field mapping accuracy
- Test price history creation
- Test inactive item marking
- Test transaction rollback on errors
- Test statistics calculation

### Integration Tests

#### Command Tests
- Test download command with mocked API
- Test sync command with test JSON file
- Test command output formatting
- Test exit codes
- Test option flags

#### Database Tests
- Test entity persistence
- Test unique constraint handling
- Test foreign key relationships
- Test transaction behavior
- Test large dataset handling

### Manual Testing Scenarios

#### Initial Sync
1. Empty database
2. Run download command
3. Verify JSON file created
4. Run sync command
5. Verify all items imported
6. Check price history records
7. Verify console output accuracy

#### Incremental Sync
1. Database with existing items
2. Modify JSON file (add, update, remove items)
3. Run sync command
4. Verify:
   - New items added
   - Existing items updated
   - Removed items marked inactive
   - Price history appended

#### Failure Recovery
1. Simulate API failure during download
2. Verify retry attempts
3. Verify error logging
4. Verify graceful failure message

#### Performance Testing
1. Test with full dataset (15,000+ items)
2. Measure download time
3. Measure sync time
4. Monitor memory usage
5. Verify database performance

## Edge Cases & Error Handling

### API Edge Cases
- **Empty response**: Log warning, don't update database
- **Partial response**: Process available items, log incomplete data
- **Rate limiting**: Respect backoff headers, implement exponential backoff
- **Invalid API key**: Fail fast with clear error message
- **Timeout**: Retry up to 3 times before failing

### Data Edge Cases
- **Duplicate items in response**: Use first occurrence, log warning
- **Missing required fields**: Skip item, log error with item ID
- **Invalid data types**: Skip field or item, log warning
- **Extremely long strings**: Truncate to field max length, log warning
- **Invalid JSON**: Fail command, display clear error message

### Database Edge Cases
- **Unique constraint violation**: Update existing item instead
- **Foreign key violation**: Should not occur with current schema
- **Deadlock**: Retry transaction up to 3 times
- **Disk full**: Fail gracefully, display storage error
- **Connection lost**: Rollback transaction, display connection error

### File System Edge Cases
- **Directory not writable**: Fail with permission error
- **Disk full**: Fail before writing partial file
- **File locked**: Wait briefly and retry
- **Symlink target missing**: Log warning, create new symlink
- **JSON file corrupted**: Display parse error with file path

### Performance Edge Cases
- **Memory exhaustion**: Use streaming JSON parser if needed
- **Long-running sync**: Add periodic flush to database
- **Concurrent executions**: Add lock file to prevent simultaneous runs
- **Database timeout**: Increase transaction timeout for large syncs

## Dependencies

### External Dependencies
- Symfony HttpClient component (already in project)
- SteamWebAPI.com API key (must be obtained)
- Filesystem write permissions for `var/data/`

### Internal Dependencies
- Item entity must exist (already does)
- ItemPrice entity must exist (already does)
- Doctrine ORM configured (already is)
- Console component configured (already is)

### Blocking Issues
- None identified - all prerequisites are in place

## Acceptance Criteria

### Download Command
- [ ] Command `app:steam:download-items` is available
- [ ] Successfully downloads JSON from SteamWebAPI.com
- [ ] Saves JSON to timestamped file in `var/data/steam-items/`
- [ ] Creates/updates `items-latest.json` symlink
- [ ] Implements retry logic with exponential backoff
- [ ] Displays clear progress and success messages
- [ ] Handles errors gracefully with informative messages
- [ ] Respects `--force` flag to override recent file check
- [ ] Logs all API interactions

### Sync Command
- [ ] Command `app:steam:sync-items` is available
- [ ] Reads JSON file (argument or default symlink)
- [ ] Creates new Item entities for new items
- [ ] Updates existing Item entities
- [ ] Creates ItemPrice records with correct mapping
- [ ] Marks missing items as inactive
- [ ] Displays progress bar during processing
- [ ] Shows detailed statistics after completion
- [ ] Respects `--dry-run` flag (no database changes)
- [ ] Respects `--skip-prices` flag
- [ ] Uses single transaction (all-or-nothing)
- [ ] Completes within 5 minutes for 15,000 items

### Database Schema
- [ ] Item entity has all new fields
- [ ] Migration successfully applied
- [ ] Indexes created for performance
- [ ] Unique constraints enforced
- [ ] Existing data preserved

### Data Integrity
- [ ] No duplicate items created
- [ ] Price history correctly linked to items
- [ ] Inactive items queryable separately
- [ ] All API fields mapped correctly
- [ ] Null values handled gracefully

### Error Handling
- [ ] API failures trigger retries
- [ ] Transaction rolls back on errors
- [ ] Clear error messages displayed
- [ ] All errors logged appropriately
- [ ] Commands exit with correct codes

### Configuration
- [ ] Environment variables documented
- [ ] Services properly configured
- [ ] API key validated on use
- [ ] Storage path configurable

### Documentation
- [ ] Commands documented in CLAUDE.md
- [ ] Environment variables documented in .env.example
- [ ] Field mappings documented in code
- [ ] Cron job examples provided

## Notes & Considerations

### API Rate Limiting
SteamWebAPI.com may have rate limits. Consider:
- The 30-minute download frequency should be safe
- Monitor API response headers for rate limit info
- Add circuit breaker if repeated failures occur
- Document rate limits when discovered

### Data Volume Management
- JSON files can be large (2-5 MB for full dataset)
- Implement automatic cleanup of old JSON files (keep last 7 days)
- Consider compression for archived files
- Monitor disk usage in `var/data/`

### Price History Growth
- ItemPrice table will grow continuously
- Consider adding data retention policy (e.g., keep 1 year)
- Add indexes on `priceDate` for efficient queries
- May need partitioning for very large datasets

### Future Enhancements
- **Incremental updates**: Only process changed items
- **Delta detection**: Calculate and store price changes
- **Alerts**: Trigger notifications on significant price changes
- **API caching**: Cache responses in Redis for redundancy
- **Parallel processing**: Process items in parallel for speed
- **Validation service**: Validate item data against business rules
- **Statistics endpoint**: API endpoint to view sync statistics
- **Monitoring**: Add metrics for sync performance and success rates

### Security Considerations
- API key stored in environment (not committed to git)
- Validate and sanitize all API data before persistence
- Prevent path traversal in file operations
- Limit file sizes to prevent disk exhaustion
- Rate limit command execution to prevent abuse

### Maintenance Tasks
- Monitor log files for recurring errors
- Review inactive items periodically
- Validate data accuracy against Steam marketplace
- Update field mappings if API changes
- Archive old JSON files to prevent disk bloat

## Related Tasks

- None currently - this is the first task
- Future tasks may include:
  - Price trend analysis and alerting
  - Integration with Discord notifications
  - User inventory syncing
  - Market opportunity detection