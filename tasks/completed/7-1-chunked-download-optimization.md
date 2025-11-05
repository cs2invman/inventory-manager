# Chunked Download Optimization

**Status**: Completed
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-05
**Completed**: 2025-11-05

## Overview

Optimize the `app:steam:download-items` command to download CS2 items in chunks of 5,000 instead of all ~26,000 at once, reducing memory usage from 1GB to ~512MB. Chunks are saved to an `import/` folder for processing by the sync command.

## Problem Statement

Currently, `app:steam:download-items` requires 1024M memory limit because it:
- Downloads all ~26,000 items in a single API call
- Loads the entire 80MB+ JSON response into memory
- Saves everything to one massive file

This high memory usage prevents running the command frequently or on resource-constrained environments.

## Requirements

### Functional Requirements
- Download items in chunks of 5,500 items per API call
- Store each chunk as a separate JSON file in `import/` folder
- Support running command multiple times (each run does a fresh download of all chunks)
- Maintain same data accuracy and completeness as current implementation
- Minimize total number of API calls to SteamWebAPI

### Non-Functional Requirements
- **Performance**: Reduce memory usage to ≤512M
- **Reliability**: Handle partial failures gracefully (one chunk failing shouldn't stop the entire process)
- **Maintainability**: Keep chunk management logic simple and understandable
- **Data Integrity**: Ensure no items are lost during chunked processing

## Technical Approach

### API Client Changes (SteamWebApiClient)

Add new method to support paginated requests:
```php
public function fetchItemsPaginated(int $max, int $page): string
```

- Uses existing `/items` endpoint with `max` and `page` query parameters
- `max`: Number of items per page (5500)
- `page`: Page number (1-indexed: 1, 2, 3, etc.)
- Returns JSON string for the chunk
- Maintains existing retry/error handling logic
- Same logging pattern as `fetchAllItems()`

Implementation:
- Modify `buildUrl()` to accept optional `max` and `page` parameters
- Copy retry logic from `fetchAllItems()` but with pagination params
- Add logging for paginated requests (include max/page in logs)
- Keep existing `fetchAllItems()` method for backward compatibility

### Download Command Changes (SteamDownloadItemsCommand)

Replace single download with chunked download loop:

**New Constants:**
```php
private const CHUNK_SIZE = 5000;
```

**New Helper Methods:**
- `parseItemCount(string $json): int` - Extract total item count from API response
- `generateChunkFilename(string $timestamp, int $chunkNum, int $totalChunks): string` - Create consistent chunk filenames
- `ensureImportDirectory(string $baseDir): string` - Create/verify import folder exists

**Execute Method Flow:**
1. Create `import/` directory if it doesn't exist
2. Download first chunk (max=5500, page=1)
3. Parse response to determine total item count
4. Calculate number of chunks needed: `ceil(totalCount / 5500)`
5. Loop through all chunks (page=1, 2, 3, 4, 5)
6. Save each chunk to `import/items-chunk-{timestamp}-{num}-of-{total}.json`
7. Display progress for each chunk
8. Update cleanup logic to handle chunk files in `import/` and `processed/` folders

**File Naming Pattern:**
```
items-chunk-2025-11-05-143022-001-of-005.json
items-chunk-2025-11-05-143022-002-of-005.json
...
```
- Timestamp identifies the download batch
- Chunk numbers are zero-padded (001, 002, etc.) for proper sorting
- Total chunks included for easy verification

**Progress Reporting:**
```
Downloading chunk 1 of 5... (5500 items, 17.8 MB)
Downloading chunk 2 of 5... (5500 items, 17.4 MB)
...
```

**Memory Limit:**
- Reduce from 1024M to 512M

### Directory Structure

```
var/data/steam-items/
├── items-2025-11-02-194741.json              # Old single-file downloads (unchanged)
├── items-2025-11-03-170233.json
├── items-2025-11-04-151429.json
├── items-latest.json -> items-2025-11-04-151429.json
└── import/                                    # NEW: Pending chunks to be synced
    ├── items-chunk-2025-11-05-143022-001-of-005.json
    ├── items-chunk-2025-11-05-143022-002-of-005.json
    ├── items-chunk-2025-11-05-143022-003-of-005.json
    ├── items-chunk-2025-11-05-143022-004-of-005.json
    └── items-chunk-2025-11-05-143022-005-of-005.json
```

### Configuration

No new environment variables needed. Uses existing:
- `STEAM_WEB_API_BASE_URL`
- `STEAM_WEB_API_KEY`
- `STEAM_ITEMS_STORAGE_PATH`

## Implementation Steps

### 1. Update SteamWebApiClient Service
- Add `fetchItemsPaginated(int $max, int $page): string` method
- Modify `buildUrl()` to accept optional `array $extraParams = []` parameter
- Use `array_merge()` to combine default params (key, game) with extra params (max, page)
- Copy retry logic from `fetchAllItems()`
- Add logging for paginated requests

**Example buildUrl modification:**
```php
private function buildUrl(string $endpoint, array $extraParams = []): string
{
    $url = rtrim($this->baseUrl, '/') . $endpoint;
    $separator = str_contains($url, '?') ? '&' : '?';

    $params = array_merge([
        'key' => $this->apiKey,
        'game' => 'cs2',
    ], $extraParams);

    return $url . $separator . http_build_query($params);
}
```

### 2. Refactor SteamDownloadItemsCommand

**Add Constants:**
```php
private const CHUNK_SIZE = 5500;
```

**Add Helper Methods:**

```php
private function parseItemCount(string $json): int
{
    $items = json_decode($json, true);
    return is_array($items) ? count($items) : 0;
}

private function generateChunkFilename(string $timestamp, int $chunkNum, int $totalChunks): string
{
    $paddedNum = str_pad((string)$chunkNum, 3, '0', STR_PAD_LEFT);
    $paddedTotal = str_pad((string)$totalChunks, 3, '0', STR_PAD_LEFT);
    return "items-chunk-{$timestamp}-{$paddedNum}-of-{$paddedTotal}.json";
}

private function ensureImportDirectory(string $baseDir): string
{
    $importDir = rtrim($baseDir, '/') . '/import';
    if (!is_dir($importDir)) {
        mkdir($importDir, 0755, true);
    }
    return $importDir;
}
```

**Rewrite execute() method:**
- Determine output directory (same as before)
- Create import directory using helper
- Download first chunk (page=1) to determine total count
- Calculate total chunks needed
- Loop through all chunks (starting from page 1, incrementing)
- Save each chunk to import folder
- Track total bytes downloaded across all chunks
- Display summary at the end

**Update cleanup logic:**
- Clean old chunk files from `import/` folder (7 days)
- Clean old chunk files from `processed/` folder (7 days) - preparing for task 7-2
- Keep existing cleanup for root-level single files

### 3. Update Recent File Check
- Skip recent file check when using chunked downloads
- Consider any run as a "fresh" download of all chunks
- Option: Add flag `--force` to bypass check (already exists)

### 4. Testing & Verification
- Manual test: Run `app:steam:download-items`
- Verify `import/` folder is created
- Verify 5 chunk files created (26000 / 5500 = 4.7, rounded up)
- Verify each chunk contains ≤5500 items
- Verify correct file naming pattern
- Monitor memory usage with `docker stats` or similar (should be <512M)
- Verify all chunks together contain same items as old single-file approach
- Test partial failure: Kill command mid-download, verify next run starts fresh

## Edge Cases & Error Handling

### API Client
- **Page beyond total items**: API returns empty array, handle gracefully
- **Network failure mid-chunk**: Existing retry logic handles it
- **All retries exhausted**: Throw exception, command handles it

### Download Command
- **API returns fewer items than expected**: Log warning, continue with actual count
- **First chunk fails**: Command exits with error (can't determine total count)
- **Subsequent chunk fails**: Log error, continue with next chunk (don't fail entire download)
- **Disk space exhaustion**: Catch and fail gracefully with clear error message
- **Permission denied on import directory**: Fail early with clear error message
- **Import directory already has old chunks**: Overwrite them with new download (fresh batch)

### Cleanup
- **Processed folder doesn't exist yet**: Skip processing folder cleanup (will be created by task 7-2)
- **Partial chunk set in import folder**: Cleanup removes them after 7 days

## Dependencies

- No external dependencies
- No blocking issues
- Task 7-2 will consume the chunks produced by this task

## Acceptance Criteria

- [ ] Download command uses ≤512M memory when downloading ~26,000 items
- [ ] Download command creates 5 chunk files (26000 / 5500 = 4.73, rounded up to 5)
- [ ] Each chunk file contains ≤5500 items (last chunk may have fewer)
- [ ] Chunk files use naming pattern: `items-chunk-{timestamp}-{num}-of-{total}.json`
- [ ] Download command creates `import/` folder automatically
- [ ] All chunks saved to `import/` folder
- [ ] Progress reporting shows chunk-level progress (e.g., "Downloading chunk 3 of 5...")
- [ ] Partial chunk download failure doesn't stop other chunks from being downloaded
- [ ] API calls total 5 for ~26,000 items (one per chunk)
- [ ] Old chunk files cleaned up after 7 days from `import/` folder
- [ ] Command completes successfully and displays summary statistics
- [ ] Memory usage verified to be under 512M during execution

## Notes & Considerations

### Why 5500 items per chunk?
- Balances memory usage vs API calls
- 26,000 items / 5,500 = 5 API calls (optimal balance)
- Each chunk is ~17-19MB JSON (manageable in memory)
- Reduces API calls while staying well under memory limits
- Leaves headroom for processing overhead

### Memory calculation estimate
Current single download:
- JSON string in memory: ~80MB
- Decoded PHP array: ~200MB (2.5x inflation typical)
- Processing overhead: ~100MB
- Total: ~380MB (but requires 1024M limit for safety)

Chunked download:
- JSON string in memory: ~18MB per chunk
- Decoded PHP array: ~45MB per chunk
- Processing overhead: ~25MB
- Total per chunk: ~88MB
- Safe with 512M limit, actual usage ~120-180MB

### Backward compatibility
- Old single-file downloads remain in root directory, untouched
- `items-latest.json` symlink continues to work
- Sync command (in task 7-2) can still process single files
- Future: Add `--no-chunks` flag to revert to single-file download if needed

### API usage optimization
- Current: 1 API call for all items
- New: 5 API calls for all items
- API rate limits should not be an issue with 5 sequential calls
- Each call has smaller response, more reliable
- 5500 items per chunk optimizes the trade-off between fewer calls and lower memory

### Chunk timestamp consistency
- All chunks in a batch share the same timestamp
- Makes it easy to identify which chunks belong together
- Helps with debugging and manual verification

## Related Tasks

- **Task 7-2**: Chunked Sync Optimization (will process these chunks)
- Future: Add `--chunk-size` option to customize chunk size
- Future: Add `--parallel` option to download chunks concurrently
