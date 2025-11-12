# Steam Sync Cronjob Logging Enhancement

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Medium (2-3 hours)
**Created**: 2025-11-11

## Overview

Enhance logging for `app:steam:download-items` and `app:steam:sync-items` commands to provide comprehensive troubleshooting capabilities when running as cronjobs. Each command will have its own dedicated log file with rich contextual information for debugging and progress tracking.

## Problem Statement

Currently, the Steam sync commands use Symfony's default logger which:
- In production, logs to stderr (Docker logs) or goes to the generic application log
- Makes it difficult to isolate cron-specific issues
- Doesn't provide a dedicated audit trail for scheduled sync operations
- Lacks comprehensive progress tracking information
- Makes troubleshooting cronjob failures time-consuming

When running these commands as cronjobs, we need:
- Dedicated log files per command for easy isolation
- Rich contextual information (performance, errors, progress, state transitions)
- Clear documentation on log format and troubleshooting workflows
- Easy-to-follow cron setup instructions

## Requirements

### Functional Requirements
- Each command logs to its own dedicated file in `var/log/`
- Log files: `var/log/cron-steam-download.log` and `var/log/cron-steam-sync.log`
- Batch-level summaries (not individual item logging to keep logs readable)
- Always log to file (whether manual or cron execution)
- JSON-formatted log entries for easy parsing and analysis
- All existing console output remains for manual execution

### Non-Functional Requirements
- Minimal performance impact (logging should not slow down sync significantly)
- Log entries must be structured for easy grep/jq filtering
- Documentation must be clear enough for non-technical admin to set up cron
- Log format must be parseable for potential future log monitoring tools

### Metrics to Track
Based on user requirements, logs should include:

1. **Performance Metrics**:
   - Execution time (total duration)
   - Memory usage (current, peak, percentage)
   - Processing rate (items/second, chunks/second)

2. **Error Details**:
   - Full exception messages and stack traces
   - Failed file paths
   - API errors with response details

3. **Progress Tracking**:
   - Items/chunks processed so far
   - Total items/chunks to process
   - Percentage complete (where applicable)

4. **State Transitions**:
   - Lock acquired/released
   - File movements (chunk → processed)
   - Chunk processing start/complete
   - API calls initiated/completed

## Technical Approach

### Monolog Configuration

Create dedicated logging channels and handlers in `config/packages/monolog.yaml`:

**Add Channels:**
```yaml
monolog:
    channels:
        - deprecation
        - steam_download  # New channel for download command
        - steam_sync      # New channel for sync command
```

**Add Handlers (all environments):**
```yaml
# In when@prod, when@dev (add to existing handlers):
monolog:
    handlers:
        # ... existing handlers ...

        steam_download:
            type: stream
            path: "%kernel.logs_dir%/cron-steam-download.log"
            level: info
            channels: [steam_download]
            formatter: monolog.formatter.json

        steam_sync:
            type: stream
            path: "%kernel.logs_dir%/cron-steam-sync.log"
            level: info
            channels: [steam_sync]
            formatter: monolog.formatter.json
```

### Command Changes

#### SteamDownloadItemsCommand

**Inject dedicated logger:**
```php
public function __construct(
    private readonly SteamWebApiClient $apiClient,
    private readonly LoggerInterface $downloadLogger, // Inject steam_download channel
    private readonly LoggerInterface $logger,          // Keep existing for fallback
    private readonly string $storageBasePath
) {
    parent::__construct();
}
```

**Update service configuration** in `config/services.yaml`:
```yaml
App\Command\SteamDownloadItemsCommand:
    arguments:
        $downloadLogger: '@monolog.logger.steam_download'
```

**Add logging points:**

1. **Command start:**
   - Log when command starts

2. **Recent file check:**
   - Log only if recent file prevents download (with filename)

3. **Per-chunk progress:**
   - Log chunk number, filename, items downloaded
   - Log cumulative item count

4. **Completion:**
   - Log total items, total chunks, duration, memory peak

5. **Cleanup:**
   - Log only if files were deleted (count)

6. **Errors:**
   - Log error message, error class, stack trace

**Example log entry (chunk download):**
```json
{
  "message": "Chunk 2 downloaded",
  "context": {
    "chunk_num": 2,
    "file": "items-chunk-2025-01-15-103000-002-of-005.json",
    "items_in_chunk": 5500,
    "total_items": 11000
  },
  "level_name": "INFO",
  "datetime": "2025-01-15T10:30:15+00:00"
}
```

**Example log entry (completion):**
```json
{
  "message": "Download completed successfully",
  "context": {
    "total_items": 26127,
    "total_chunks": 5,
    "duration_seconds": 12.34,
    "memory_peak": "245MB"
  },
  "level_name": "INFO",
  "datetime": "2025-01-15T10:30:45.123456+00:00"
}
```

#### SteamSyncItemsCommand

**Inject dedicated logger:**
```php
public function __construct(
    private readonly ItemSyncService $syncService,
    private readonly EntityManagerInterface $entityManager,
    private readonly LoggerInterface $syncLogger,  // Inject steam_sync channel
    private readonly LoggerInterface $logger,       // Keep existing for fallback
    private readonly string $storageBasePath,
    private readonly LockFactory $lockFactory
) {
    parent::__construct();
}
```

**Update service configuration** in `config/services.yaml`:
```yaml
App\Command\SteamSyncItemsCommand:
    arguments:
        $syncLogger: '@monolog.logger.steam_sync'
```

**Enhance existing logging:**

The command already has good logging structure. Enhance with:

1. **File discovery:**
   - Log when files are found (with count and filenames)

2. **Chunk processing:**
   - Log chunk progress with filename
   - Log item stats (added/updated/skipped)
   - Log cumulative totals
   - Log memory usage

3. **Completion:**
   - Log final stats and duration

4. **Errors:**
   - Log chunk failures with filename and error

**Example log entry (chunk processing):**
```json
{
  "message": "Chunk 2/5 completed",
  "context": {
    "file": "items-chunk-2025-01-15-120000-002-of-005.json",
    "chunk_stats": {"added": 145, "updated": 5234, "skipped": 121},
    "total_processed": 11000,
    "memory_peak": "345MB"
  },
  "level_name": "INFO",
  "datetime": "2025-01-15T10:32:15.123456+00:00"
}
```

### PRODUCTION.md Documentation Updates

Add simplified cron and logging documentation:

1. **Cron Job Configuration** - Basic cron setup commands
2. **Log File Documentation** - Log locations, format, and key fields

## Implementation Steps

### 1. Update Monolog Configuration

**File:** `config/packages/monolog.yaml`

- Add `steam_download` and `steam_sync` to channels list
- Add `steam_download` handler in `when@prod` section
- Add `steam_sync` handler in `when@prod` section
- Add same handlers to `when@dev` section (for testing)
- Use JSON formatter for both handlers
- Set level to `info` (captures info, warning, error)

### 2. Update Service Configuration

**File:** `config/services.yaml`

- Add explicit service definition for `App\Command\SteamDownloadItemsCommand`
- Bind `$downloadLogger` to `@monolog.logger.steam_download`
- Add explicit service definition for `App\Command\SteamSyncItemsCommand`
- Bind `$syncLogger` to `@monolog.logger.steam_sync`

### 3. Enhance SteamDownloadItemsCommand

**File:** `src/Command/SteamDownloadItemsCommand.php`

**Changes:**
- Add `$downloadLogger` parameter to constructor
- Add logging at command start (with config summary)
- Add logging for recent file check result
- Add logging before API calls
- Enhance chunk download logging with cumulative stats
- Add logging for file writes
- Add logging for cleanup operations
- Add comprehensive error logging with context
- Replace existing `$this->logger` calls with `$this->downloadLogger` for operational logs
- Keep `$this->logger` for critical errors only

**New logging points:**

```php
// At start of execute()
$this->downloadLogger->info('Download command started');

// After recent file check (only if skipping)
if ($recentFile) {
    $this->downloadLogger->info('Recent file found, skipping download', [
        'file' => basename($recentFile),
    ]);
    return Command::SUCCESS;  // Exit early
}

// After each chunk
$this->downloadLogger->info("Chunk {$page} downloaded", [
    'chunk_num' => $page,
    'file' => $filename,
    'items_in_chunk' => $chunkItemCount,
    'total_items' => $totalItemsDownloaded,
]);

// After cleanup (only if files deleted)
if ($deletedCount > 0) {
    $this->downloadLogger->info('Cleaned up old files', [
        'files_deleted' => $deletedCount,
    ]);
}

// On completion
$this->downloadLogger->info('Download completed successfully', [
    'total_items' => $totalItemsDownloaded,
    'total_chunks' => $actualTotalChunks,
    'duration_seconds' => $duration,
    'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
]);

// On error (in catch block)
$this->downloadLogger->error('Download failed', [
    'error' => $e->getMessage(),
    'error_class' => get_class($e),
    'trace' => $e->getTraceAsString(),
]);
```

**Modify `cleanupOldFiles()`:**
- Return count of deleted files for logging

### 4. Enhance SteamSyncItemsCommand

**File:** `src/Command/SteamSyncItemsCommand.php`

**Changes:**
- Add `$syncLogger` parameter to constructor
- Replace `$this->logger` calls with `$this->syncLogger` throughout
- Simplify log messages to essential context only

**New/enhanced logging points:**

```php
// After finding files (only if files found)
if (!empty($chunkFiles)) {
    $this->syncLogger->info('Files found for processing', [
        'count' => count($chunkFiles),
        'files' => array_map('basename', $chunkFiles),
    ]);
}

// In executeChunkedSync(), after each chunk
$this->syncLogger->info("Chunk {$chunkNum}/{$chunkCount} completed", [
    'file' => $filename,
    'chunk_stats' => [
        'added' => $stats['added'],
        'updated' => $stats['updated'],
        'skipped' => $stats['skipped'],
    ],
    'total_processed' => $aggregatedStats['total'],
    'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
]);

// In catch block for chunk errors
$this->syncLogger->error('Chunk processing failed', [
    'file' => $filename,
    'error' => $e->getMessage(),
    'error_class' => get_class($e),
]);

// Final completion log
$this->syncLogger->info('Sync completed successfully', [
    'total_stats' => $aggregatedStats,
    'chunks_processed' => $chunkCount,
    'duration_seconds' => $duration,
    'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
]);
```

**Keep existing logging:**
- Lock acquisition failures (already logged)
- Memory warnings (already logged)
- Signal handling warnings (already logged)

### 5. Update PRODUCTION.md Documentation

**File:** `PRODUCTION.md`

**Replace the "Syncing Steam Items" section** (currently at lines 183-199) with simplified documentation:

````markdown
## Cron Job Configuration

### Setting Up Cron Jobs

**Open crontab editor:**
```bash
crontab -e
```

**Add the cron entries** (adjust path and schedule as needed):
```bash
# CS2 Inventory - Download Steam items weekly (Sundays at 3 AM)
0 3 * * 0 cd /home/user/cs2inventory && docker compose exec -T php php bin/console app:steam:download-items >> /dev/null 2>&1

# CS2 Inventory - Sync downloaded items every 2 minutes
*/2 * * * * cd /home/user/cs2inventory && docker compose exec -T php php bin/console app:steam:sync-items >> /dev/null 2>&1
```

**Notes:**
- Use `-T` flag with `docker compose exec` for cron compatibility
- Console output redirected to `/dev/null` - logs go to dedicated files
- `app:steam:sync-items` exits silently if no files to process
- Adjust paths to match your deployment location

## Command Logs

Each command writes to its own log file in `var/log/`:

| Command | Log File |
|---------|----------|
| `app:steam:download-items` | `var/log/cron-steam-download.log` |
| `app:steam:sync-items` | `var/log/cron-steam-sync.log` |

**Location:** `./var/log/` (relative to project root)

### Log Format

All logs are JSON-formatted, one entry per line.

**Download log example:**
```json
{
  "message": "Download completed successfully",
  "context": {
    "total_items": 26127,
    "total_chunks": 5,
    "duration_seconds": 12.34,
    "memory_peak": "245MB"
  },
  "level_name": "INFO",
  "datetime": "2025-01-15T10:30:45+00:00"
}
```

**Sync log example:**
```json
{
  "message": "Chunk 2/5 completed",
  "context": {
    "file": "items-chunk-2025-01-15-120000-002-of-005.json",
    "chunk_stats": {"added": 145, "updated": 5234, "skipped": 121},
    "total_processed": 11000,
    "memory_peak": "345MB"
  },
  "level_name": "INFO",
  "datetime": "2025-01-15T10:32:15+00:00"
}
```

**View logs:**
```bash
# View recent entries
tail -n 50 var/log/cron-steam-download.log
tail -n 50 var/log/cron-steam-sync.log

# Follow in real-time
tail -f var/log/cron-steam-sync.log
```
````

## Edge Cases & Error Handling

- Monolog will create log files if they don't exist
- Log write failures should not break command execution
- Context fields should use null coalescing for missing data
- Monolog's JSON formatter handles escaping automatically
- Lock prevents concurrent sync executions

## Dependencies

None - this is a standalone enhancement.

**Related:** Task 999 (backlog) - Built-in log rotation enhancement

## Acceptance Criteria

- [ ] Monolog configuration adds `steam_download` and `steam_sync` channels
- [ ] Monolog configuration adds dedicated handlers for both channels
- [ ] Service configuration injects dedicated loggers into both commands
- [ ] `SteamDownloadItemsCommand` logs to `var/log/cron-steam-download.log`
- [ ] `SteamSyncItemsCommand` logs to `var/log/cron-steam-sync.log`
- [ ] All log entries are JSON-formatted and parseable
- [ ] Download logs include: start, per-chunk progress (filename, items), completion (totals, duration, memory), errors
- [ ] Sync logs include: file discovery (filenames), per-chunk progress (filename, stats, total), completion (stats, duration, memory), errors
- [ ] Error logs include: error message, error class, stack trace
- [ ] All context fields use appropriate data types (no redundant fields)
- [ ] PRODUCTION.md has cron setup instructions
- [ ] PRODUCTION.md documents log file locations and format
- [ ] PRODUCTION.md includes example log entries for both commands
- [ ] PRODUCTION.md includes basic log viewing commands
- [ ] Manual testing: Run download command and verify log entries
- [ ] Manual testing: Run sync command and verify log entries
- [ ] Manual testing: Verify JSON is valid and parseable
- [ ] Manual testing: Trigger error (e.g., invalid file) and verify error logging
- [ ] Manual testing: Set up cron job and verify logs are written

## Notes & Considerations

### Performance Considerations
- JSON logging has minimal overhead
- No significant impact on sync performance expected

### Security Considerations
- Log files contain file paths but no sensitive data
- API keys and credentials should never be logged
- Log files will have standard permissions (644)

### Future Improvements
- Built-in log rotation (task #999 - backlog)
- Structured log aggregation if needed
- Alerting on error patterns
