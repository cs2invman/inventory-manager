# Standardize Console Commands

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-21

## Overview

Standardize all `app:*` console commands to have consistent behavior, output handling, and performance optimization for cron execution. This includes adding optional progress bars, ensuring quiet operation by default, removing interactive prompts, and optimizing for speed and memory efficiency.

## Problem Statement

Current console commands have inconsistent behavior:
- Some commands are verbose by default, others are silent
- Progress bars are hardcoded in some commands, missing in others
- `CreateUserCommand` prompts for password interactively (blocks cron execution)
- Output verbosity is inconsistent (mix of SymfonyStyle, Table, writeln)
- Not all commands are cron-optimized (silent exit when no work)
- Memory management and batch processing strategies vary

For cron jobs, commands must:
- Run silently by default (no output unless errors)
- Never prompt for user input
- Exit gracefully when no work is available
- Provide progress feedback only when requested via flag
- Be memory-efficient for long-running operations

## Requirements

### Functional Requirements

#### 1. Verbosity Standard
- **Default (no flags)**: Silent operation - only output errors
- **`-v` (verbose)**: Show basic progress messages
- **`-vv` (very verbose)**: Show detailed operational info
- **`-vvv` (debug)**: Show debug-level info including memory usage, SQL queries, etc.

#### 2. Progress Bar Standard
- Add `--progress` flag to all commands that process multiple items
- Progress bar only shows when `--progress` flag is present
- Not applicable to commands that:
  - Perform single operations (e.g., `create-user`)
  - Are primarily for display/listing (e.g., `list-users`)
  - Already complete instantly

**Commands needing progress bars:**
- `app:steam:download-items` - downloading chunks
- `app:steam:sync-items` - processing chunks and items
- `app:item:backfill-current-price` - processing batches
- `app:queue:process` - processing queue items
- `app:queue:enqueue-all` - enqueueing items
- `app:discord:test-webhook` - N/A (instant)
- `app:test:item-table` - N/A (test command)
- `app:test:items-controller` - N/A (test command)

#### 3. No Interactive Prompts
- **`CreateUserCommand`**: Require password via `--password` option for non-interactive use
- For interactive use (manual execution), prompts are allowed ONLY if output is a TTY
- Check `$output->isInteractive()` before prompting
- All commands must work in non-interactive mode (for cron)

#### 4. Cron Optimization
- All commands that might have "no work" scenarios must exit silently (return SUCCESS, no output)
- Examples:
  - `app:steam:sync-items`: Exit silently if no files in import directory
  - `app:queue:process`: Exit silently if no pending queue items
  - `app:steam:download-items`: Exit silently if recent file exists (unless --force)

#### 5. Consistent Error Handling
- Always log errors to appropriate logger
- In non-verbose mode: Only show error summary to user
- In verbose mode: Show detailed error messages
- Always return `Command::FAILURE` on error, `Command::SUCCESS` on success

### Non-Functional Requirements

#### Performance
- Use batch processing for operations on multiple entities
- Clear entity manager periodically to prevent memory bloat
- Set appropriate memory limits via `ini_set()`
- Use generators/iterators for large datasets where applicable
- Process in chunks to avoid loading entire datasets into memory

#### Memory Management
- Commands processing 100+ items must clear entity manager every N items (10-50 recommended)
- Use `gc_collect_cycles()` after clearing entity manager
- Log memory usage at debug level (`-vvv`)

#### Logging
- Use structured logging with context arrays
- Log to appropriate logger channel (not generic logger)
- Include relevant metadata: item counts, duration, memory usage

## Technical Approach

### Base Command Pattern

Create a reusable pattern/trait for common functionality:

```php
// Example standard command structure
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $io = new SymfonyStyle($input, $output);
    $verbosity = $output->getVerbosity();
    $showProgress = $input->getOption('progress');

    // Quiet mode: only show errors
    $quiet = $verbosity < OutputInterface::VERBOSITY_VERBOSE;

    // Check for work availability (cron-friendly)
    if ($this->hasNoWork()) {
        return Command::SUCCESS; // Silent exit
    }

    // Setup progress bar if requested
    $progressBar = null;
    if ($showProgress && !$quiet) {
        $progressBar = $io->createProgressBar($totalItems);
        $progressBar->start();
    }

    try {
        // Do work
        foreach ($items as $item) {
            // Process item

            if ($progressBar) {
                $progressBar->advance();
            }

            // Periodic cleanup
            if ($count % 10 === 0) {
                $this->em->clear();
                gc_collect_cycles();
            }
        }

        if ($progressBar) {
            $progressBar->finish();
            $io->newLine(2);
        }

        // Log completion
        $this->logger->info('Command completed', $stats);

        // Show summary only if verbose
        if (!$quiet) {
            $io->success('Completed');
        }

        return Command::SUCCESS;

    } catch (\Throwable $e) {
        $this->logger->error('Command failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Always show errors
        $io->error('Failed: ' . $e->getMessage());

        return Command::FAILURE;
    }
}
```

### Commands to Modify

1. **CreateUserCommand** (`src/Command/CreateUserCommand.php`)
   - Remove interactive password prompt OR make it conditional on `$output->isInteractive()`
   - Make `--password` option required for non-interactive mode
   - Implement quiet mode (only show errors by default)
   - Output success message only in verbose mode

2. **ListUsersCommand** (`src/Command/ListUsersCommand.php`)
   - Implement quiet mode
   - Add `--format` option (table, json, csv) for scripting
   - In quiet mode, output minimal info (or use --format json)
   - Only show table in verbose mode by default

3. **SteamDownloadItemsCommand** (`src/Command/SteamDownloadItemsCommand.php`)
   - Add `--progress` flag (optional progress bar for chunk downloads)
   - Implement quiet mode (silent unless errors)
   - Already has cron-optimization (recent file check)
   - Ensure all `$io->writeln()` calls respect verbosity

4. **SteamSyncItemsCommand** (`src/Command/SteamSyncItemsCommand.php`)
   - Add `--progress` flag (optional progress bar for chunk/item processing)
   - Already has cron-optimization (silent exit if no files)
   - Already has good memory management
   - Refactor verbose output to use verbosity levels

5. **BackfillCurrentPriceCommand** (`src/Command/BackfillCurrentPriceCommand.php`)
   - Change progress bar to be behind `--progress` flag
   - Implement quiet mode
   - Already has good batch processing
   - Add cron-friendly check: if no items to process, exit silently

6. **QueueProcessCommand** (`src/Command/QueueProcessCommand.php`)
   - Add `--progress` flag (optional progress bar)
   - Already has cron-optimization (silent exit if no items)
   - Implement verbosity-aware output
   - All writeln() calls should check verbosity

7. **QueueEnqueueAllCommand** (`src/Command/QueueEnqueueAllCommand.php`)
   - Change progress bar to be behind `--progress` flag
   - Implement quiet mode
   - Already has good batch processing
   - Add cron-friendly check: if no items exist, exit silently

8. **TestDiscordWebhookCommand** (needs to be located and checked)
   - Implement standard verbosity handling
   - This is a utility command, always show output (not for cron)

9. **TestItemTableCommand** (`src/Command/TestItemTableCommand.php`)
   - Test command, keep verbose (not for cron)
   - Optionally add `--progress` for test suites

10. **TestItemsControllerCommand** (`src/Command/TestItemsControllerCommand.php`)
    - Test command, keep verbose (not for cron)
    - Optionally add `--progress` for test suites

### Configuration Updates

Update all commands to include:

```php
protected function configure(): void
{
    $this
        // ... existing options ...
        ->addOption(
            'progress',
            null,
            InputOption::VALUE_NONE,
            'Show progress bar during processing'
        )
    ;
}
```

## Implementation Steps

### Phase 1: Core Infrastructure (Foundation)

1. **Create CommandTrait or BaseCommand** (optional, for reusability)
   - Create `src/Command/Traits/CronOptimizedCommandTrait.php`
   - Include helper methods:
     - `isQuiet(): bool` - check if output should be suppressed
     - `createProgressBarIfRequested(): ?ProgressBar` - create progress bar if --progress flag
     - `clearEntityManagerPeriodically(int $count, int $frequency = 10): void`
     - `logMemoryUsage(string $context): void`

2. **Document standards in CLAUDE.md**
   - Add "Console Command Standards" section
   - Document verbosity levels, --progress flag, quiet mode behavior
   - Provide template for new commands

### Phase 2: Update Individual Commands

#### Step 2.1: Update CreateUserCommand
- Remove/conditionalize interactive password prompt
- Add quiet mode support
- Update success/error output to respect verbosity
- Test non-interactive execution: `echo "password" | docker compose exec -T php php bin/console app:create-user test@example.com John Doe --password=testpass`

#### Step 2.2: Update ListUsersCommand
- Add quiet mode (minimal/JSON output)
- Add `--format` option (table, json, csv)
- Only show styled table in verbose mode
- Test: `docker compose exec php php bin/console app:list-users -q`

#### Step 2.3: Update SteamDownloadItemsCommand
- Add `--progress` flag
- Wrap all informational output in verbosity checks
- Ensure errors always display
- Test cron mode: `docker compose exec php php bin/console app:steam:download-items -q`

#### Step 2.4: Update SteamSyncItemsCommand
- Add `--progress` flag for chunk processing
- Make info messages conditional on verbosity
- Keep memory logging at debug level (`-vvv`)
- Test: `docker compose exec php php bin/console app:steam:sync-items --progress -v`

#### Step 2.5: Update BackfillCurrentPriceCommand
- Make progress bar conditional on `--progress` flag
- Add quiet mode
- Add "no work" detection (exit silently if 0 items to process)
- Test: `docker compose exec php php bin/console app:item:backfill-current-price --progress`

#### Step 2.6: Update QueueProcessCommand
- Add `--progress` flag
- Make output messages verbosity-aware
- Keep silent exit when no items (already implemented)
- Test: `docker compose exec php php bin/console app:queue:process --progress -v`

#### Step 2.7: Update QueueEnqueueAllCommand
- Make progress bar conditional on `--progress` flag
- Add quiet mode
- Add "no items in DB" check (exit silently if count=0)
- Test: `docker compose exec php php bin/console app:queue:enqueue-all PRICE_UPDATED --progress`

#### Step 2.8: Verify Test Commands
- Check `app:test:item-table` - keep verbose (test utility)
- Check `app:test:items-controller` - keep verbose (test utility)
- Check `app:discord:test-webhook` - keep verbose (test utility)

### Phase 3: Testing & Validation

1. **Test each command in different modes:**
   - Quiet mode (default): `docker compose exec php php bin/console app:command`
   - Verbose mode: `docker compose exec php php bin/console app:command -v`
   - With progress: `docker compose exec php php bin/console app:command --progress`
   - Debug mode: `docker compose exec php php bin/console app:command -vvv`

2. **Test cron-friendly scenarios:**
   - Run sync with no files in import directory (should exit silently)
   - Run queue process with no pending items (should exit silently)
   - Run download with recent file (should exit silently unless --force)

3. **Test non-interactive mode:**
   - Pipe input/output to ensure no hanging prompts
   - Example: `echo "" | docker compose exec -T php php bin/console app:create-user ...`

4. **Memory testing:**
   - Run batch commands with -vvv to verify memory logging
   - Check that entity manager clearing happens
   - Monitor memory usage during large operations

### Phase 4: Documentation

1. **Update CLAUDE.md:**
   - Add "Console Command Standards" section
   - Document verbosity conventions
   - Document `--progress` flag usage
   - Provide template for future commands

2. **Update command descriptions:**
   - Ensure all `description` attributes are clear and consistent
   - Document all options in `configure()` method

3. **Update cron examples in CLAUDE.md:**
   - Show proper quiet execution examples
   - Document which commands are cron-suitable

## Edge Cases & Error Handling

### CreateUserCommand Edge Cases
- User already exists: Always show error (even in quiet mode)
- Password not provided in non-interactive mode: Show clear error
- Invalid email format: Validate and show error

### Batch Processing Edge Cases
- Empty dataset: Exit silently in quiet mode
- Partial failures in batch: Log failures, continue processing, return success if >0 items processed
- Out of memory: Catch and log with context, return failure

### Cron Execution Edge Cases
- Lock already acquired (command already running): Exit silently with success
- Lock timeout: Release lock, log warning, exit with failure
- Disk full: Catch and log, exit with failure
- Database connection lost: Retry once, then fail with error

### Progress Bar Edge Cases
- Output is not a TTY: Don't create progress bar even if --progress specified
- Progress bar with very large datasets: Consider percentage-based updates instead of per-item

## Dependencies

### Blocking Dependencies
None - this task is self-contained

### External Dependencies
None - uses existing Symfony console components

### Can Be Done in Parallel With
Any other task not modifying the same command files

## Acceptance Criteria

- [ ] All `app:*` commands (except test commands) run silently by default
- [ ] Progress bars are behind `--progress` flag for applicable commands
- [ ] No interactive prompts block cron execution
- [ ] Commands processing multiple items use `--progress` flag consistently
- [ ] Commands with "no work" scenarios exit silently (return SUCCESS)
- [ ] Errors are always displayed regardless of verbosity
- [ ] All commands respect Symfony verbosity levels (-v, -vv, -vvv)
- [ ] Memory management: Entity manager cleared periodically in batch operations
- [ ] Structured logging with context used throughout
- [ ] CLAUDE.md updated with console command standards
- [ ] All commands tested in quiet, verbose, and progress modes
- [ ] CreateUserCommand works in non-interactive mode
- [ ] Cron examples in CLAUDE.md updated to use quiet mode

## Notes & Considerations

### Performance Considerations
- Progress bars add minimal overhead (~1-5% for iteration)
- Entity manager clearing is essential for memory management
- Batch size tuning may be needed based on actual memory usage
- Use database-level pagination instead of loading all IDs into memory

### Backwards Compatibility
- Adding `--progress` flag is backwards compatible (optional)
- Changing default verbosity from verbose to quiet is a **breaking change** for scripts that parse output
- Consider adding deprecation notice if any scripts depend on current output format
- Test commands should remain verbose (users expect output)

### Symfony Best Practices
- Use `SymfonyStyle` consistently for all output
- Use `OutputInterface` verbosity constants for checks
- Use command return codes: `Command::SUCCESS`, `Command::FAILURE`, `Command::INVALID`
- Use input validation helpers where applicable

### Future Enhancements (NOT in this task)
- Add `--format=json` to all commands for machine-readable output
- Add `--dry-run` flag to commands that make changes
- Add unified configuration for batch sizes, memory limits

## Related Tasks

None

---

## Task Completion Instructions

When this task is fully complete:

1. **Update this file**:
   - Change `**Status**: Not Started` to `**Status**: Completed`
   - Add completion date: `**Completed**: 2025-11-21`

2. **Move to completed folder**:
   - Move this file from `tasks/` to `tasks/completed/`
   - Keep the same filename

3. **Verify all acceptance criteria** are checked off before marking as complete
