# CSFloat Sync Cronjob Command

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-08

## Overview

Create a console command that runs as a cronjob to synchronize CSFloat marketplace data (def_index, paint_index) for inventory items. The command searches CSFloat API for unmapped items and stores the mapping in the ItemCsfloat table.

## Problem Statement

Users' inventory items need to be linked to CSFloat marketplace for price comparison and direct links. This requires:
- Finding all Items that don't have CSFloat mappings yet
- Searching CSFloat API by market_hash_name
- Storing def_index and paint_index in ItemCsfloat table
- Running periodically via cron without manual intervention
- Handling errors gracefully to avoid breaking inventory display

## Requirements

### Functional Requirements
- Find all Items that don't have ItemCsfloat records
- Search CSFloat API for each unmapped item
- Create ItemCsfloat record with def_index and paint_index
- Skip items not found on CSFloat (don't create records with NULL indexes)
- Process items in batches to respect rate limits
- Log progress and errors for monitoring
- Exit gracefully if no unmapped items found (cron-friendly)

### Non-Functional Requirements
- Respect CSFloat API rate limits (max 100 requests per minute)
- Process batches to avoid memory issues
- Retry failed searches (transient network errors)
- Complete within reasonable time (< 10 minutes for 100 items)
- Idempotent: safe to run multiple times
- Silent output when no work to do (cron-friendly)

## Technical Approach

### Command Design

**Command Name:** `app:csfloat:sync-items`

**Arguments:** None

**Options:**
- `--limit=N`: Limit number of items to process (default: 50)
- `--force-resync`: Re-sync items that already have mappings (updates existing records)
- `--dry-run`: Show what would be processed without making changes

**Exit Codes:**
- `0`: Success (items processed or nothing to do)
- `1`: Error (API key missing, database error, etc.)

### Processing Logic

1. **Find Unmapped Items**
   - Query Items that don't have ItemCsfloat records
   - Use LEFT JOIN with WHERE itemCsfloat.id IS NULL
   - Only include active Items (active = true)
   - Limit to batch size (default: 50)

2. **Process Each Item**
   - Search CSFloat API using CsfloatSearchService
   - If found: Create ItemCsfloat record with indexes
   - If not found: Skip (log warning)
   - If error: Log error, continue to next item

3. **Rate Limiting**
   - CSFloat rate limiter: 100 requests/minute
   - Sleep between batches if needed
   - Respect 429 retry-after headers

4. **Progress Reporting**
   - Show progress bar for interactive runs
   - Log summary: "Processed X items, Y mapped, Z not found, A errors"

### Cron Configuration

**Recommended Schedule:**
```bash
# Run every hour at minute 15
15 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:csfloat:sync-items >> var/log/csfloat-sync.log 2>&1

# Or run daily at 3 AM
0 3 * * * cd /path/to/project && docker compose exec -T php php bin/console app:csfloat:sync-items >> var/log/csfloat-sync.log 2>&1
```

**Cron-Friendly Behavior:**
- Silent when no items to process (exit 0, no output)
- Log to file, not stdout
- Non-interactive mode (no progress bars in cron)
- Short execution time (< 10 minutes)

## Implementation Steps

1. **Create Console Command**
   - Create `src/Command/CsfloatSyncItemsCommand.php`
   - Extend `Command` class
   - Set name: `app:csfloat:sync-items`
   - Add description: "Sync CSFloat marketplace data for inventory items"

2. **Inject Dependencies**
   - `ItemRepository`: Find unmapped items
   - `ItemCsfloatRepository`: Create mappings
   - `CsfloatSearchService`: Search API
   - `EntityManagerInterface`: Persist records
   - `LoggerInterface`: Log progress

3. **Add Command Options**
   - `--limit`: InputOption::VALUE_OPTIONAL, default 50
   - `--force-resync`: InputOption::VALUE_NONE
   - `--dry-run`: InputOption::VALUE_NONE

4. **Implement findUnmappedItems() Query**
   - Add to ItemRepository:
     ```php
     public function findUnmappedItems(int $limit = 50): array
     {
         return $this->createQueryBuilder('i')
             ->leftJoin('i.csfloatMapping', 'cf')
             ->where('cf.id IS NULL')
             ->andWhere('i.active = :active')
             ->setParameter('active', true)
             ->setMaxResults($limit)
             ->getQuery()
             ->getResult();
     }
     ```

5. **Implement Main Processing Loop**
   - Get unmapped items from repository
   - If empty and not verbose, exit silently (return 0)
   - Create ProgressBar for interactive mode
   - Loop through items:
     - Search CSFloat using CsfloatSearchService
     - If found: Create ItemCsfloat record
     - If not found: Log warning, skip
     - If error: Log error, continue
     - Update progress bar
   - Flush EntityManager after batch

6. **Add Error Handling**
   - Try-catch around main loop
   - Log exceptions with item details
   - Continue processing on individual item errors
   - Fail command only on critical errors (DB connection lost, API key invalid)

7. **Add Progress Reporting**
   - Track counters: processed, mapped, notFound, errors
   - Display summary at end:
     ```
     Processed: 50 items
     Mapped: 42 items
     Not found: 6 items
     Errors: 2 items
     ```

8. **Add Dry Run Mode**
   - If `--dry-run`, don't persist to database
   - Show what would be created:
     ```
     [DRY RUN] Would map: AK-47 | Redline (FT) -> def=7, paint=282
     ```

9. **Add Force Resync Mode**
   - If `--force-resync`, process all items (not just unmapped)
   - Update existing ItemCsfloat records with fresh data
   - Use case: CSFloat API data changes, need to refresh

10. **Add Logging**
    - Info: "Starting CSFloat sync: limit={limit}, force={force}"
    - Info: "Found {count} unmapped items"
    - Debug: "Searching CSFloat for: {market_hash_name}"
    - Info: "Mapped: {market_hash_name} -> def={def_index}, paint={paint_index}"
    - Warning: "Not found on CSFloat: {market_hash_name}"
    - Error: "Failed to search CSFloat: {market_hash_name} - {error}"
    - Info: "Sync complete: {summary}"

11. **Optimize Memory Usage**
    - Process in batches of 50 items
    - Clear EntityManager after each batch:
      ```php
      $em->flush();
      $em->clear(); // Detach entities to free memory
      ```

12. **Add Rate Limit Handling**
    - CsfloatSearchService already handles rate limiting
    - Log when rate limits are hit
    - No special handling needed (service retries automatically)

## Edge Cases & Error Handling

- **No unmapped items**: Exit silently with code 0 (cron-friendly)
- **API key not configured**: Log error, exit with code 1
- **CSFloat API down**: Log error for each failed item, continue processing, exit 0
- **Item deleted during sync**: Skip if not found, don't error
- **Network timeout**: Log error, continue to next item
- **Database connection lost**: Log error, exit with code 1 (critical error)
- **Item with no market_hash_name**: Skip with warning (shouldn't happen)
- **Duplicate CSFloat mapping**: Skip (unique constraint on item_id prevents duplicates)
- **Very large batch**: Process in chunks of 50, flush EntityManager between chunks
- **Interrupted by signal**: Flush pending changes before exit

## Dependencies

### Blocking Dependencies
- Task 44: CSFloat database foundation (ItemCsfloat entity must exist)
- Task 45: CSFloat API service (CsfloatSearchService must exist)

### Related Tasks (CSFloat Integration Feature)
- Task 47: Admin settings UI (displays sync status, manual trigger button)
- Task 48: Frontend CSFloat links (uses data populated by this command)

### Can Be Done in Parallel With
- Task 47: Admin settings UI (can be developed while this command is being built)

### External Dependencies
- Symfony Console component (already in project)
- Doctrine ORM (already in project)
- CSFloat API access (requires API key from admin settings)

## Acceptance Criteria

- [ ] Console command created: `app:csfloat:sync-items`
- [ ] Command finds unmapped items using repository query
- [ ] Command searches CSFloat API for each item
- [ ] Command creates ItemCsfloat records on successful search
- [ ] Command skips items not found on CSFloat
- [ ] Command handles errors gracefully (logs, continues)
- [ ] `--limit` option works (default: 50)
- [ ] `--dry-run` option works (no database changes)
- [ ] `--force-resync` option updates existing mappings
- [ ] Progress bar shown in interactive mode
- [ ] Summary displayed at end (processed, mapped, not found, errors)
- [ ] Cron-friendly: silent when no work, logs to file
- [ ] Rate limiting respected (100 requests/minute)
- [ ] Memory efficient (EntityManager cleared between batches)
- [ ] Idempotent: safe to run multiple times

## Manual Verification Steps

### 1. Prepare Test Data

```bash
# Check how many items need mapping
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
SELECT COUNT(*) as unmapped_items
FROM item i
LEFT JOIN item_csfloat cf ON i.id = cf.item_id
WHERE cf.id IS NULL AND i.active = 1;
"
```

### 2. Run Command in Dry Run Mode

```bash
docker compose exec php php bin/console app:csfloat:sync-items --dry-run --limit=10 -v

# Expected output:
# Starting CSFloat sync: limit=10, force=false, dry-run=true
# Found 10 unmapped items
# [DRY RUN] Would map: AK-47 | Redline (Field-Tested) -> def=7, paint=282
# ...
# Processed: 10, Mapped: 8, Not found: 2, Errors: 0
```

### 3. Run Command for Real (Small Batch)

```bash
docker compose exec php php bin/console app:csfloat:sync-items --limit=5 -v

# Should see progress bar and summary
```

### 4. Verify Database Records

```bash
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
SELECT
    i.market_name,
    cf.def_index,
    cf.paint_index,
    cf.created_at
FROM item i
JOIN item_csfloat cf ON i.id = cf.item_id
ORDER BY cf.created_at DESC
LIMIT 5;
"

# Should show newly created mappings
```

### 5. Test Idempotency

```bash
# Run command twice
docker compose exec php php bin/console app:csfloat:sync-items --limit=5

# Second run should find 0 unmapped items (already mapped)
# Output: "Found 0 unmapped items" (silent exit)
```

### 6. Test Force Resync

```bash
# Update an existing mapping to NULL
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
UPDATE item_csfloat SET def_index=NULL, paint_index=NULL WHERE id=1;
"

# Force resync
docker compose exec php php bin/console app:csfloat:sync-items --force-resync --limit=1 -v

# Should update the record with correct indexes
```

### 7. Test Error Handling

```bash
# Temporarily break API key
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
UPDATE admin_config SET config_value='invalid' WHERE config_key='csfloat_api_key';
"

docker compose exec php php bin/console app:csfloat:sync-items --limit=1

# Should log error and exit with code 1
# Restore API key afterward
```

### 8. Test Large Batch Processing

```bash
# Run with larger limit
docker compose exec php php bin/console app:csfloat:sync-items --limit=100 -v

# Monitor memory usage
docker stats cs2inventory-php-1 --no-stream
```

### 9. Set Up Cron Job

```bash
# Add to crontab (on host machine)
crontab -e

# Add line (adjust path):
15 * * * * cd /home/user/cs2inventory && docker compose exec -T php php bin/console app:csfloat:sync-items >> var/log/csfloat-sync.log 2>&1
```

### 10. Verify Cron Execution

```bash
# Wait for cron to run, then check logs
tail -f var/log/csfloat-sync.log

# Should see sync summaries
```

### 11. Test Manual Trigger (via Admin UI - after Task 47)

```bash
# After Task 47 is complete, admin UI should have "Sync Now" button
# Click button, verify command runs and updates display
```

## Notes & Considerations

- **Batch Size**: Default 50 items balances speed vs. rate limits (100/min = ~50 items in 30s)
- **Cron Frequency**: Hourly is sufficient for most users (new items imported rarely)
- **Memory**: Clear EntityManager after batches to prevent memory leaks with large datasets
- **Retry Logic**: CsfloatSearchService handles retries, command just logs final result
- **Skip Unknown Items**: Items not on CSFloat (cases, music kits, etc.) are skipped, not errors
- **Future Enhancements**:
  - Add `--item-id=X` option to sync specific item
  - Add `--user-id=X` option to sync only one user's items
  - Add progress to database (allow resuming interrupted syncs)
  - Add notification on completion (Discord webhook, email)
  - Add metrics: items synced per hour, success rate, etc.
- **Performance**: 100 items takes ~2-3 minutes (API calls + rate limiting)

## Related Tasks

- Task 44: CSFloat database foundation (blocking - must complete first)
- Task 45: CSFloat API service (blocking - must complete first)
- Task 47: Admin settings UI (displays sync status, manual trigger)
- Task 48: Frontend CSFloat links (uses data populated by this command)
