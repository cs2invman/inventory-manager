# Refactor Steam Sync Command for Cron-Based Processing

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-06

## Overview

Refactor `SteamSyncItemsCommand` to be optimized for cron-based execution every minute, removing symlink logic and implementing aggressive memory management to handle processing multiple chunk files without running out of memory. The command should process all files found in `var/data/steam-items/import` alphabetically (matching download order), handle the "no files" case gracefully for cron, and implement comprehensive memory optimization strategies.

## Problem Statement

The current `SteamSyncItemsCommand` implementation has several issues:

1. **Symlink dependency**: Falls back to `items-latest.json` symlink if no chunks found, which is legacy behavior no longer needed
2. **Memory exhaustion**: Running out of memory on the last file even after chunked processing optimization (Task 7-2)
3. **Poor error handling for empty directory**: Shows memory error when no files exist, which doesn't make sense
4. **Not cron-optimized**: Expects user interaction and verbose output, not suitable for silent cron execution
5. **Memory accumulation**: Despite `gc_collect_cycles()` calls, memory grows between files suggesting entity manager state, arrays, or other references are not being cleared properly

The goal is to create a command that can run every minute via cron, process any available files efficiently, and exit silently when no work is needed.

## Requirements

### Functional Requirements

- Process all chunk files found in `var/data/steam-items/import` in alphabetical order (natural sort)
- Exit silently (no output, success code) when no files are found in import directory
- Continue processing remaining files if one file fails (log error, skip file, continue)
- Move successfully processed files to `var/data/steam-items/processed` directory
- Implement aggressive memory management between files to prevent exhaustion
- Remove all symlink logic and fallback to `items-latest.json`
- Remove or adapt interactive prompts for cron execution
- Maintain detailed logging for debugging and monitoring

### Non-Functional Requirements

- **Memory efficiency**: Process all 5 chunk files without running out of memory
- **Cron-friendly**: Silent execution when no work, detailed logs when processing
- **Resilience**: Continue processing despite individual file failures
- **Performance**: Complete processing within reasonable time for cron interval
- **Maintainability**: Clear separation of concerns, well-documented memory optimization strategies

## Technical Approach

### Memory Optimization Strategy

The key challenge is preventing memory growth across multiple file processing cycles. Implement a multi-layered approach:

**1. Entity Manager Aggressive Clearing**
- Call `$entityManager->clear()` after EACH file is fully processed and flushed
- Clear between batch transactions within a single file
- This detaches all entities from Doctrine's UnitOfWork, preventing memory accumulation

**2. Unset Large Variables**
- Explicitly `unset()` large arrays after use:
  - JSON content string after decoding
  - Decoded items array after processing
  - Statistics arrays that accumulate data
  - External IDs accumulation array between files

**3. Forced Garbage Collection**
- Call `gc_collect_cycles()` after each file completes
- Consider calling it after large variable unsets
- This forces PHP to reclaim memory from circular references

**4. Reduce Batch Size Within Files**
- Current batch size is 50 items per transaction in `ItemSyncService`
- Consider reducing to 25 items for lower memory footprint per transaction
- More frequent flushes = more DB overhead but less memory accumulation

**5. Stream Processing Consideration**
- Instead of loading entire JSON file into memory, consider streaming JSON parsing
- Use libraries like `JsonStreamingParser` if file sizes are extremely large
- For now, this is likely overkill, but document as future optimization

**6. Memory Limit Management**
- Set appropriate memory limit (current: 768M)
- Monitor memory usage and log warnings at thresholds (e.g., 80% usage)
- Consider adding `--memory-limit` option for testing different configurations

**7. Accumulator Array Strategy**
- The `$allExternalIds` array accumulates IDs across all chunks for deactivation
- This can grow to ~26,000+ items × ~50 bytes = ~1.3MB
- Keep this array, but clear and rebuild between separate command invocations
- Within single execution, this is necessary state

**8. Doctrine Query Optimization**
- Ensure queries use indexed fields (external_id is indexed)
- Disable Doctrine query caching for long-running processes
- Use DQL UPDATE for deactivation instead of loading entities

### Command Refactoring Strategy

**Remove:**
- Symlink fallback logic (lines 74-76)
- Single file processing mode (entire `executeSingleFileSync` method)
- File argument support (only scan import directory)
- Interactive confirmation prompts (lines 116-119)
- Dry-run option (or make it non-interactive)

**Modify:**
- `execute()` method: Check for files, exit silently if none, process all if found
- `executeChunkedSync()`: Remove prompts, add aggressive memory management, improve error handling
- File processing loop: Add try-catch per file, continue on failure, log errors
- Memory management: Add explicit unsets and more frequent garbage collection

**Add:**
- Memory usage tracking and logging
- Per-file error handling with skip-and-continue logic
- Quiet mode optimized for cron (minimal output unless errors)
- Memory profiling helper methods for debugging

### Service Layer Optimization

**Modify `ItemSyncService::syncFromJsonFile()`:**
- Add explicit `unset($jsonContent)` after JSON decoding
- Add explicit `unset($itemsData)` after loop completes
- Reduce batch size from 50 to 25 items (configurable)
- Add memory usage logging at batch boundaries
- Clear entity manager after deactivation step
- Return smaller statistics array (only essential counters)

### Configuration Management

Add new configuration options:
- `SYNC_BATCH_SIZE`: Items per transaction (default: 25)
- `SYNC_MEMORY_LIMIT`: Memory limit for sync command (default: 768M)
- `SYNC_MEMORY_WARNING_THRESHOLD`: Log warning at % usage (default: 80)

## Implementation Steps

### 1. Refactor SteamSyncItemsCommand

**1.1 Remove Legacy Code**
- Remove `executeSingleFileSync()` method entirely
- Remove file argument from `configure()`
- Remove symlink fallback logic from `execute()`
- Remove interactive prompts from `executeChunkedSync()`

**1.2 Implement Silent No-Files Handling**
- In `execute()`, check if `findChunkFiles()` returns empty array
- If empty, immediately return `Command::SUCCESS` with no output
- No logging, no messages - completely silent for cron

**1.3 Refactor executeChunkedSync for Memory Efficiency**
- Remove SymfonyStyle formatting for cron execution (keep for verbose mode)
- Add per-file try-catch blocks
- Implement aggressive memory management between files:
  ```php
  foreach ($chunkFiles as $index => $chunkFile) {
      try {
          // Process file
          $stats = $this->syncService->syncFromJsonFile(...);

          // Aggregate stats
          $aggregatedStats['added'] += $stats['added'];
          // ...

          // Move to processed
          $this->moveToProcessed($chunkFile, $processedDir);

          // AGGRESSIVE MEMORY CLEANUP
          unset($stats);
          $this->entityManager->clear();
          gc_collect_cycles();

          // Log memory usage
          $this->logMemoryUsage($chunkFile);

      } catch (\Throwable $e) {
          // Log error
          $this->logger->error('Failed to process chunk file', [
              'file' => $chunkFile,
              'error' => $e->getMessage(),
          ]);

          // Clean up and continue
          $this->entityManager->clear();
          gc_collect_cycles();
          continue; // Skip this file, process next
      }
  }
  ```

**1.4 Add Memory Monitoring**
- Create `logMemoryUsage(string $context)` helper method
- Log current memory usage, peak memory, and percentage used
- Log warnings when approaching memory limit (80% threshold)

**1.5 Update Progress Reporting**
- Remove progress bars (not useful for cron)
- Add simple log messages per file processed
- Log final summary statistics

### 2. Optimize ItemSyncService

**2.1 Reduce Batch Size**
- Change `$batchSize = 50` to `$batchSize = 25` (line 82)
- Consider making this configurable via constructor parameter
- Add comment explaining memory vs performance tradeoff

**2.2 Add Aggressive Variable Cleanup**
- After line 59 (JSON decode): Add `unset($jsonContent);`
- After main processing loop (line 157): Add `unset($itemsData);`
- After accumulating external IDs: Add periodic array_slice() to limit growth if needed

**2.3 Improve Batch Transaction Handling**
- Ensure `clear()` is called after each batch commit (already exists at line 133)
- Add explicit unset of batch-level variables
- Log memory usage at batch boundaries for debugging

**2.4 Optimize Deactivation Query**
- Current query at line 432 is already optimized (DQL UPDATE)
- Ensure this runs in a separate transaction (already wrapped at line 163)
- Add memory logging before/after deactivation

**2.5 Return Minimal Statistics**
- Current stats array is small, no change needed
- Ensure no large data structures are returned

### 3. Update Configuration

**3.1 Add Environment Variables**
- Add to `.env.example`:
  ```
  # Steam Item Sync Configuration
  SYNC_BATCH_SIZE=25
  SYNC_MEMORY_LIMIT=768M
  SYNC_MEMORY_WARNING_THRESHOLD=80
  ```

**3.2 Update services.yaml**
- Bind new parameters to ItemSyncService if batch size is configurable:
  ```yaml
  App\Service\ItemSyncService:
      arguments:
          $batchSize: '%env(int:SYNC_BATCH_SIZE)%'
  ```

### 4. Add Memory Profiling Helpers

**4.1 Create Memory Helper Methods in Command**
```php
private function logMemoryUsage(string $context): void
{
    $current = memory_get_usage(true);
    $peak = memory_get_peak_usage(true);
    $limit = $this->parseMemoryLimit(ini_get('memory_limit'));
    $percentage = ($current / $limit) * 100;

    $this->logger->info('Memory usage', [
        'context' => $context,
        'current' => $this->formatBytes($current),
        'peak' => $this->formatBytes($peak),
        'limit' => $this->formatBytes($limit),
        'percentage' => round($percentage, 2) . '%',
    ]);

    if ($percentage > 80) {
        $this->logger->warning('Memory usage high', [
            'percentage' => round($percentage, 2) . '%',
        ]);
    }
}

private function parseMemoryLimit(string $limit): int
{
    // Parse PHP memory_limit format (e.g., "768M", "1G")
    // Return bytes
}
```

**4.2 Add Memory Logging at Key Points**
- Before starting file processing loop
- After each file completes
- After deactivation step completes
- Before command exit

### 5. Testing and Validation

**5.1 Manual Testing Scenarios**
- Empty import directory (should exit silently)
- Single chunk file
- All 5 chunk files (full import)
- Corrupted JSON file (should skip and continue)
- Out of disk space (should handle gracefully)

**5.2 Memory Profiling**
- Run with Xdebug memory profiler or similar
- Monitor memory usage per file
- Ensure memory is released between files
- Validate memory doesn't exceed 768M limit

**5.3 Cron Integration Testing**
```bash
# Add to crontab for testing
* * * * * docker compose exec -T php php bin/console app:steam:sync-items >> /var/log/steam-sync.log 2>&1

# Monitor logs
tail -f var/log/steam-sync.log

# Test empty directory behavior
rm var/data/steam-items/import/*.json
# Run cron, should be silent

# Test normal processing
docker compose exec php php bin/console app:steam:download-items
# Wait 1 minute, cron should process files
```

**5.4 Error Handling Testing**
- Create invalid JSON file in import directory
- Verify it's skipped and other files process
- Verify error is logged but command succeeds

## Edge Cases & Error Handling

### 1. Empty Import Directory
- **Scenario**: No files in `var/data/steam-items/import`
- **Handling**: Exit immediately with `Command::SUCCESS`, no output, no logs
- **Rationale**: Normal state for cron execution, not an error

### 2. Corrupted JSON File
- **Scenario**: File exists but contains invalid JSON
- **Handling**: Catch exception in per-file try-catch, log error, skip file, continue to next
- **Rationale**: One bad file shouldn't stop processing of other valid files
- **Cleanup**: Don't move corrupted file to processed/ - leave in import/ for manual inspection

### 3. Database Connection Lost Mid-Processing
- **Scenario**: Database connection drops during transaction
- **Handling**: Catch in per-file try-catch, log error, attempt to reconnect, continue
- **Rationale**: Transient network issues shouldn't halt processing
- **Recovery**: Next cron execution will retry failed files

### 4. Memory Limit Reached
- **Scenario**: Despite optimizations, memory limit is hit
- **Handling**: PHP will throw fatal error, cron will record in logs
- **Mitigation**: Memory logging will show which file caused issue
- **Resolution**: Increase memory limit or further optimize batch size

### 5. Duplicate External IDs
- **Scenario**: API returns duplicate items (bug in API or data)
- **Handling**: Already handled by ItemSyncService (finds by external_id, updates if exists)
- **Logging**: Log warning if unexpected duplicates within same file

### 6. Deactivation Step Fails
- **Scenario**: Deactivation query fails (timeout, connection loss)
- **Handling**: Already wrapped in try-catch (line 187-194 in current code)
- **Impact**: Items synced but orphaned items not deactivated - will retry next sync
- **Logging**: Warning logged, processing continues

### 7. Processed Directory Full/Unavailable
- **Scenario**: Can't write to processed/ directory (permissions, disk space)
- **Handling**: Catch exception in `moveToProcessed()`, log error, don't delete source file
- **Rationale**: Better to have duplicate in import/ than lose data

### 8. Concurrent Execution
- **Scenario**: Cron runs every minute, but previous execution still running
- **Handling**: Use file lock to prevent concurrent execution
- **Implementation**:
  ```php
  private function acquireLock(): bool
  {
      $lockFile = sys_get_temp_dir() . '/steam-sync.lock';
      $this->lockHandle = fopen($lockFile, 'c');
      return flock($this->lockHandle, LOCK_EX | LOCK_NB);
  }
  ```

## Dependencies

- No external dependencies
- Internal refactoring only
- Relies on existing ItemSyncService
- Relies on existing Docker infrastructure

## Acceptance Criteria

### Functional Criteria
- [ ] Command processes all files in `var/data/steam-items/import` alphabetically
- [ ] Command exits silently (no output, success code) when import directory is empty
- [ ] Successfully processed files are moved to `var/data/steam-items/processed`
- [ ] If one file fails, remaining files continue to be processed
- [ ] All symlink logic and `items-latest.json` fallback is removed
- [ ] Single file processing mode (`executeSingleFileSync`) is removed
- [ ] Interactive prompts are removed (no user confirmation needed)

### Memory Management Criteria
- [ ] Command successfully processes all 5 chunk files without running out of memory
- [ ] Memory usage is logged after each file processes
- [ ] Warning is logged when memory usage exceeds 80% of limit
- [ ] EntityManager is cleared after each file completes
- [ ] Large variables (JSON content, items array) are explicitly unset
- [ ] Garbage collection is forced after each file completes
- [ ] Memory usage does not continuously grow across files (saw-tooth pattern expected)

### Error Handling Criteria
- [ ] Corrupted JSON file is logged as error and skipped
- [ ] Database connection errors are caught and logged
- [ ] Processing continues after per-file errors
- [ ] Failed files remain in import/ directory (not moved to processed/)
- [ ] Successful files are moved even if later files fail

### Logging & Monitoring Criteria
- [ ] Detailed logs written for each file processed
- [ ] Memory usage logged at key checkpoints
- [ ] Errors logged with context (filename, error message, stack trace)
- [ ] Final summary statistics logged
- [ ] No console output when no files to process

### Cron Integration Criteria
- [ ] Command can run every minute via cron without issues
- [ ] Silent execution when no work (perfect for cron)
- [ ] Exit code 0 (success) even when no files found
- [ ] Exit code 0 (success) when files processed successfully
- [ ] Exit code 1 (failure) only on critical unrecoverable errors
- [ ] Logs are suitable for monitoring and alerting

### Performance Criteria
- [ ] Processing completes within reasonable time (< 5 minutes for 5 files)
- [ ] No significant performance degradation vs current implementation
- [ ] Database queries remain optimized (indexed fields used)

## Notes & Considerations

### Memory Growth Root Causes

Based on the current implementation, memory likely grows due to:

1. **Doctrine EntityManager State**: Even with `clear()`, internal state may accumulate
2. **Statistics Arrays**: Aggregating stats across files keeps data in memory
3. **External IDs Array**: `$allExternalIds` grows to ~26,000 items across all files
4. **JSON Content**: Large strings (~20-30MB per file) may not be GC'd immediately
5. **PHP Internal Buffers**: Output buffering, error handlers may cache data

### Alternative: One File Per Execution

If memory issues persist despite aggressive optimizations, consider alternative approach:
- Process only first file found per execution
- Cron runs every minute, picks up next file
- Guarantees fresh memory state per file
- Slower overall but more reliable

Implementation would be simple:
```php
$chunkFiles = $this->findChunkFiles($importDir);
if (empty($chunkFiles)) {
    return Command::SUCCESS; // Silent exit
}

// Process only first file
$fileToProcess = $chunkFiles[0];
// ... process single file ...
```

Document this as fallback if "all files" approach still has memory issues.

### Cron Configuration

Recommended cron configuration for production:

```bash
# Run every minute
* * * * * docker compose exec -T php php bin/console app:steam:sync-items

# Alternative: Run with log output for debugging
* * * * * docker compose exec -T php php bin/console app:steam:sync-items >> /var/log/steam-sync.log 2>&1

# Alternative: Run every 5 minutes for less aggressive processing
*/5 * * * * docker compose exec -T php php bin/console app:steam:sync-items
```

### Testing Memory Optimizations

To validate memory optimizations, add temporary debug logging:

```php
$this->logger->debug('Memory checkpoint', [
    'location' => 'before JSON decode',
    'memory' => memory_get_usage(true),
]);
```

Add these checkpoints at:
- Before JSON file read
- After JSON decode, before unset
- After unset
- Before entity processing
- After entity manager clear
- After garbage collection

This will show exactly where memory is allocated and released.

### Future Improvements

- **Streaming JSON Parser**: For extremely large files (>100MB)
- **Redis Cache**: Replace APCu for distributed deployments
- **Async Processing**: Use Symfony Messenger for background processing
- **Progress Tracking**: Store progress in database for resumable processing
- **Monitoring Dashboard**: Web UI to view processing status and memory usage

## Related Tasks

- **Task 7-1**: Chunked Download Optimization (completed)
- **Task 7-2**: Chunked Sync Optimization (completed) - This task extends 7-2
- **Task 2**: Steam Item Sync API (completed) - Original sync implementation

## Documentation Updates

After implementation, update:

- `CLAUDE.md`: Update "Development Commands" section with new sync behavior
- `CLAUDE.md`: Add "Cron Jobs" section describing automated sync setup
- `.env.example`: Add new SYNC_* environment variables with comments
- `README.md`: Add section on automated data synchronization (if user-facing)

## Success Metrics

The implementation will be considered successful when:

1. ✅ Command runs every minute via cron without errors
2. ✅ All 5 chunk files process successfully without memory exhaustion
3. ✅ Memory usage remains below 768M throughout execution
4. ✅ Processing completes in < 5 minutes
5. ✅ No console output when import directory is empty
6. ✅ Failed files don't stop processing of remaining files
7. ✅ Detailed logs provide visibility into processing and errors
