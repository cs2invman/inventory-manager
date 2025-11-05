# Chunked Sync Optimization

**Status**: Completed
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-05
**Completed**: 2025-11-05

## Overview

Optimize the `app:steam:sync-items` command to process chunk files independently instead of loading a massive JSON file all at once, reducing memory usage from 2GB to ~512MB. Processes chunks from `import/` folder and moves them to `processed/` folder after successful sync.

## Problem Statement

Currently, `app:steam:sync-items` requires 2048M memory limit because it:
- Loads the entire 80MB+ JSON file into memory at once
- Processes all ~26,000 items in a single operation
- Hydrates thousands of Doctrine entities simultaneously

This high memory usage prevents running the command frequently or on resource-constrained environments.

## Requirements

### Functional Requirements
- Process chunk files from `import/` folder one at a time
- Move successfully synced chunks to `processed/` folder
- Aggregate statistics across all chunks for final report
- Support running command multiple times safely (idempotent)
- Defer item deactivation until all chunks are processed
- Maintain same data accuracy and completeness as current implementation
- Support processing single files (backward compatibility)

### Non-Functional Requirements
- **Performance**: Reduce memory usage to ≤512M
- **Reliability**: Handle partial failures gracefully (one chunk failing shouldn't stop processing of other chunks)
- **Maintainability**: Keep chunk processing logic simple and understandable
- **Data Integrity**: Ensure no items are lost and deactivation happens correctly
- **Idempotency**: Running sync multiple times should be safe (relies on existing deduplication logic)

## Technical Approach

### ItemSyncService Changes

Modify service to support deferred deactivation:

**Update `syncFromJsonFile()` signature:**
```php
public function syncFromJsonFile(
    string $filePath,
    bool $skipPrices = false,
    bool $deferDeactivation = false,
    ?callable $progressCallback = null
): array
```

**Add new method:**
```php
public function deactivateItemsNotInList(array $externalIds): int
```

**Logic Changes:**
- When `$deferDeactivation = true`: skip the deactivation step at the end
- Return `processedExternalIds` in stats array for deferred deactivation
- Extract deactivation logic to the new public method
- Update logging to indicate when deactivation is deferred

**Return value example:**
```php
return [
    'added' => 123,
    'updated' => 456,
    'deactivated' => 0,  // Always 0 when deferred
    'price_records_created' => 789,
    'skipped' => 12,
    'total' => 5000,
    'processedExternalIds' => [...], // NEW: array of external IDs processed
];
```

### Sync Command Changes (SteamSyncItemsCommand)

**New Helper Methods:**
- `findChunkFiles(string $importDir): array` - Discover chunk files in import folder, sorted by chunk number
- `moveToProcessed(string $filepath, string $processedDir): void` - Move file safely
- `ensureProcessedDirectory(string $baseDir): string` - Create/verify processed folder

**Execute Method Flow:**

1. **Determine what to process:**
   - If file argument provided: process that specific file (backward compatibility)
   - If no argument: look for chunks in `{storageBasePath}/import/` folder
   - Find all files matching pattern `items-chunk-*.json`
   - Sort by chunk number for consistent processing

2. **Setup:**
   - Create `processed/` directory if it doesn't exist
   - Initialize aggregated statistics
   - Create progress bar based on number of chunks

3. **Process each chunk:**
   - Load chunk file from import folder
   - Call `syncFromJsonFile()` with `deferDeactivation = true`
   - Collect external IDs from chunk stats
   - Track cumulative statistics
   - Move successfully synced chunk to processed folder
   - Update progress bar
   - If chunk fails: log error, continue with next chunk

4. **Finalize:**
   - After all chunks processed: call `deactivateItemsNotInList()` with all collected external IDs
   - Update statistics with deactivation count
   - Display aggregated statistics

**File Discovery Logic:**
```php
private function findChunkFiles(string $importDir): array
{
    if (!is_dir($importDir)) {
        return [];
    }

    $pattern = $importDir . '/items-chunk-*.json';
    $files = glob($pattern);

    if (empty($files)) {
        return [];
    }

    // Sort by chunk number (extracted from filename)
    usort($files, function($a, $b) {
        preg_match('/-(\d+)-of-(\d+)\.json$/', $a, $matchesA);
        preg_match('/-(\d+)-of-(\d+)\.json$/', $b, $matchesB);

        $chunkA = isset($matchesA[1]) ? (int)$matchesA[1] : 0;
        $chunkB = isset($matchesB[1]) ? (int)$matchesB[1] : 0;

        return $chunkA <=> $chunkB;
    });

    return $files;
}
```

**Memory Limit:**
- Reduce from 2048M to 512M

### Directory Structure

```
var/data/steam-items/
├── import/                                    # Pending chunks to be synced
│   ├── items-chunk-2025-11-05-143022-001-of-006.json  (before sync)
│   ├── items-chunk-2025-11-05-143022-002-of-006.json
│   └── ... (gets emptied as chunks are processed)
└── processed/                                 # Successfully synced chunks
    ├── items-chunk-2025-11-04-120000-001-of-006.json
    ├── items-chunk-2025-11-04-120000-002-of-006.json
    └── items-chunk-2025-11-05-143022-001-of-006.json  (after sync)
```

### Configuration

No new environment variables needed. Uses existing:
- `STEAM_ITEMS_STORAGE_PATH`

## Implementation Steps

### 1. Update ItemSyncService

**Add deferDeactivation parameter:**
```php
public function syncFromJsonFile(
    string $filePath,
    bool $skipPrices = false,
    bool $deferDeactivation = false,
    ?callable $progressCallback = null
): array
```

**Extract deactivation logic:**
```php
public function deactivateItemsNotInList(array $externalIds): int
{
    if (empty($externalIds)) {
        return 0;
    }

    $qb = $this->entityManager->createQueryBuilder();
    $qb->update(Item::class, 'i')
        ->set('i.active', ':inactive')
        ->where('i.externalId IS NOT NULL')
        ->andWhere($qb->expr()->notIn('i.externalId', ':current_ids'))
        ->andWhere('i.active = :active')
        ->setParameter('inactive', false)
        ->setParameter('current_ids', $externalIds)
        ->setParameter('active', true);

    $count = $qb->getQuery()->execute();

    $this->logger->info('Deactivated missing items', [
        'count' => $count,
    ]);

    return $count;
}
```

**Modify syncFromJsonFile:**
- Skip deactivation section when `$deferDeactivation = true`
- Add `processedExternalIds` to return array
- Add logging when deactivation is deferred

### 2. Refactor SteamSyncItemsCommand

**Add Helper Methods:**

```php
private function findChunkFiles(string $importDir): array
{
    // Implementation shown above
}

private function ensureProcessedDirectory(string $baseDir): string
{
    $processedDir = rtrim($baseDir, '/') . '/processed';
    if (!is_dir($processedDir)) {
        mkdir($processedDir, 0755, true);
    }
    return $processedDir;
}

private function moveToProcessed(string $filepath, string $processedDir): void
{
    $filename = basename($filepath);
    $destination = $processedDir . '/' . $filename;

    if (!rename($filepath, $destination)) {
        throw new \RuntimeException("Failed to move file to processed folder: {$filepath}");
    }
}
```

**Rewrite execute() method:**

1. Determine input files
2. Check if processing chunks or single file
3. If chunks:
   - Find all chunk files in import folder
   - Error if no chunks found
   - Create processed directory
   - Initialize aggregated stats
   - Loop through chunks:
     - Sync with deferred deactivation
     - Collect external IDs
     - Move to processed
     - Update progress
   - Run deactivation on all collected IDs
4. If single file:
   - Process as before (no deactivation deferral)
5. Display statistics

### 3. Update Progress Reporting

**For chunk processing:**
```
Syncing Items from Import Folder
Found 6 chunk files to process

Processing chunk 1 of 6 (items-chunk-2025-11-05-143022-001-of-006.json)...
[========================================] 5000/5000
Chunk 1: Added 120, Updated 4880, Skipped 0

Processing chunk 2 of 6 (items-chunk-2025-11-05-143022-002-of-006.json)...
[========================================] 5000/5000
Chunk 2: Added 110, Updated 4890, Skipped 0

...

All chunks processed. Running item deactivation...
Deactivated 15 items no longer in Steam catalog.

Sync Complete
─────────────────────────────────────
Added:                  723
Updated:                25,200
Deactivated:            15
Price records created:  18,450
Skipped:                62
Total processed:        26,000
Duration:               3m 45s
```

### 4. Update Cleanup Logic
- Add cleanup for processed folder (keep 7 days)
- Can be run from download command or add separate cleanup command

### 5. Testing & Verification
- Manual test: Ensure task 7-1 completed and chunks exist in `import/` folder
- Run `app:steam:sync-items` without arguments
- Verify all chunks in `import/` folder are processed
- Verify chunks moved from `import/` to `processed/` folder
- Verify database contains all items
- Verify deactivation happens after all chunks processed
- Monitor memory usage (should be <512M)
- Manual test: Run sync multiple times (verify idempotency - no errors)
- Manual test: Delete a chunk file mid-sync, verify command handles gracefully
- Manual test: Single file processing still works (backward compatibility)

## Edge Cases & Error Handling

### Service Layer
- **Empty external IDs array**: Return 0, don't run query (handled in new method)
- **Deactivation query fails**: Log warning, don't fail entire sync (existing behavior)
- **Memory pressure despite chunks**: Existing `gc_collect_cycles()` should help

### Sync Command
- **No chunk files found in import folder**: Clear error message, suggest running download command first
- **Import folder doesn't exist**: Clear error message, suggest running download command first
- **Chunk file is corrupted/invalid JSON**: Log error, skip that chunk, continue with others
- **Database constraint violation**: Existing batch transaction handling catches this, logs and continues
- **Processed folder doesn't exist**: Create it automatically
- **Move to processed fails**: Try to continue, log warning (chunk was already synced to DB)
- **Partial set of chunks**: Process whatever is available, deactivation uses only those IDs
- **Chunks from different batches (different timestamps)**: Process all chunks found (command doesn't care about timestamps)

### Deactivation Logic
- **Multiple sync runs with same chunks**: Idempotent - deactivation runs each time with same IDs
- **Running sync while import folder is being populated**: Processes whatever chunks exist at start time

## Dependencies

- **Task 7-1**: Must be completed first to generate chunk files in import folder
- No external dependencies
- No blocking issues

## Acceptance Criteria

- [ ] Sync command uses ≤512M memory when syncing ~26,000 items from chunks
- [ ] Sync command reads chunk files from `import/` folder automatically when no argument provided
- [ ] Sync command creates `processed/` folder automatically
- [ ] Sync command moves successfully synced chunks from `import/` to `processed/` folder
- [ ] Chunks processed in order (001, 002, 003, etc.)
- [ ] Deactivation only happens after all chunks are processed
- [ ] Statistics correctly aggregate across all chunks
- [ ] Running sync multiple times is safe (idempotent)
- [ ] Partial chunk sync failure doesn't prevent other chunks from being synced
- [ ] Single file processing still works (backward compatibility)
- [ ] Error messages are clear and actionable
- [ ] Progress reporting shows chunk-level progress
- [ ] Memory usage verified to be under 512M during execution

## Notes & Considerations

### Memory calculation estimate
Current single-file sync:
- JSON string in memory: ~80MB
- Decoded PHP array: ~200MB (2.5x inflation typical)
- Doctrine entity hydration: ~400MB (additional overhead)
- Processing overhead: ~400MB
- Total: ~1000-1500MB (requires 2048M limit)

Chunked sync:
- JSON string in memory: ~16MB per chunk
- Decoded PHP array: ~40MB per chunk
- Doctrine entity hydration: ~80MB per chunk
- Processing overhead: ~80MB
- Entity manager cleared after each chunk
- Total per chunk: ~200-300MB
- Safe with 512M limit

### Why defer deactivation?
- If we deactivate after chunk 1, items in chunks 2-6 would be marked inactive
- Deactivation needs the complete list of external IDs from ALL chunks
- Single deactivation query at the end is more efficient anyway

### Idempotency considerations
- Database has unique constraints on external_id
- ItemSyncService already handles duplicates (updates instead of inserts)
- Running sync multiple times processes same chunks again but safely
- Deactivation query is idempotent (sets active=false on same items)

### Backward compatibility
- Sync command can still process single files if path argument provided
- Old single-file workflow continues to work unchanged
- Existing scripts/cron jobs using file arguments are unaffected

### Cleanup strategy
- Processed chunks kept for 7 days for debugging/verification
- Old chunks automatically cleaned up by download command
- Can manually delete processed chunks if disk space is tight

### Error recovery
- If sync fails mid-chunk: next run will re-process all chunks (some updates, but safe)
- If move to processed fails: chunk already synced to DB, next run will re-process it (safe)
- Database transactions ensure each chunk is all-or-nothing

## Related Tasks

- **Task 7-1**: Chunked Download Optimization (provides the chunk files)
- Future: Add command to re-process chunks from processed folder (for debugging)
- Future: Add database table to track chunk sync history and timestamps
- Future: Add `--skip-move` flag to keep chunks in import folder after sync (for testing)
