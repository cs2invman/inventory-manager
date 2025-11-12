# Built-in Log Rotation for Steam Sync Commands

**Status**: Backlog (Future Enhancement)
**Priority**: Low
**Estimated Effort**: Small
**Created**: 2025-11-11

## Overview

Add built-in log rotation capabilities to `app:steam:download-items` and `app:steam:sync-items` commands to automatically manage log file sizes without requiring external logrotate configuration.

## Problem Statement

Currently, log files (`var/log/steam-download.log` and `var/log/steam-sync.log`) grow indefinitely and require external tools like logrotate to manage. This creates an additional configuration burden for system administrators.

Built-in rotation would:
- Eliminate need for logrotate configuration
- Work consistently across different deployment environments
- Allow configurable retention policies via environment variables
- Prevent disk space issues automatically

## Requirements

### Functional Requirements
- Automatic log file rotation when size exceeds threshold (e.g., 10MB)
- Configurable retention (e.g., keep last 30 days or 10 files)
- Compressed old log files (gzip)
- Rotation happens at command start (before logging begins)
- Existing Monolog integration continues to work

### Configuration (Environment Variables)
- `LOG_ROTATION_MAX_SIZE` - Max file size before rotation (default: 10M)
- `LOG_ROTATION_MAX_FILES` - Number of old files to keep (default: 10)
- `LOG_ROTATION_COMPRESS` - Enable gzip compression (default: true)

### Non-Functional Requirements
- Minimal performance impact (rotation only at command start)
- Safe concurrent rotation (file locking)
- Graceful degradation if rotation fails (log to console)

## Technical Approach

### Option 1: Monolog RotatingFileHandler

Replace `StreamHandler` with `RotatingFileHandler` in `config/packages/monolog.yaml`:

```yaml
steam_download:
    type: rotating_file
    path: "%kernel.logs_dir%/steam-download.log"
    level: info
    max_files: 10  # Keep 10 days of logs
    channels: [steam_download]
    formatter: monolog.formatter.json
```

**Pros:**
- Built into Monolog
- No custom code needed
- Rotation by date (daily files)

**Cons:**
- Rotation is by date, not by size
- Less control over retention policy
- No compression built-in

### Option 2: Custom Log Rotation Service

Create `App\Service\LogRotationService`:
- Checks log file size at command start
- Rotates if exceeds threshold
- Compresses old files
- Deletes oldest files beyond retention

**Pros:**
- Full control over rotation logic
- Size-based rotation
- Built-in compression
- Configurable via env vars

**Cons:**
- Custom code to maintain
- More complex than Monolog solution

## Implementation Steps (When Prioritized)

1. Choose rotation approach (RotatingFileHandler vs custom service)
2. Update Monolog configuration or create service
3. Add environment variables to `.env`
4. Test rotation with large log files
5. Update PRODUCTION.md to document built-in rotation
6. Remove external logrotate recommendation from docs

## Dependencies

### Blocking Dependencies
- Task 53 (Steam Sync Cronjob Logging) must be completed first

### Related Tasks
- Task 53: Steam Sync Cronjob Logging Enhancement (parent task)

## Acceptance Criteria

- [ ] Log files automatically rotate when reaching size threshold
- [ ] Old log files are compressed with gzip
- [ ] Retention policy honored (old files deleted)
- [ ] Configuration via environment variables
- [ ] No external logrotate needed
- [ ] PRODUCTION.md updated to reflect built-in rotation
- [ ] Manual testing: Generate large logs and verify rotation

## Notes & Considerations

- This is a future enhancement, not urgent
- Current recommendation is to use logrotate (documented in Task 53)
- Re-evaluate priority if disk space becomes an issue
- Consider Monolog's RotatingFileHandler as simplest solution
