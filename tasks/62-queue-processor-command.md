# Queue Processor Command and Infrastructure

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-17

## Overview

Create the command-line interface and processor registry infrastructure that will run the queue processing system. This includes a cron-optimized command that fetches pending queue items, dispatches them to the appropriate processor, handles failures, and sends Discord notifications for errors.

## Problem Statement

With the queue system foundation in place (Task 61), we need:
- A console command that can be run via cron every 5 minutes
- A processor registry pattern to map process types to processor implementations
- Error handling that notifies via Discord when processing fails
- Logging and monitoring of queue processing operations
- A clean interface for processors to implement

## Requirements

### Functional Requirements
- Console command `app:queue:process` that processes pending queue items
- Processor interface that all processors must implement
- Processor registry service to map process types to processor classes
- Error handling with Discord notifications for failures
- Logging of processing operations (success, failure, counts)
- Cron-optimized: exits silently if no items to process
- Batch processing: process up to 100 items per run (configurable)

### Non-Functional Requirements
- Clean separation between command, registry, and processors
- Easy to add new processor types without modifying command
- Proper error handling and rollback on failures
- Memory efficient for processing large batches
- Idempotent: can be run multiple times safely

## Technical Approach

### Processor Interface

**ProcessorInterface**
Location: `src/Service/QueueProcessor/ProcessorInterface.php`

```php
namespace App\Service\QueueProcessor;

use App\Entity\ProcessQueue;

interface ProcessorInterface
{
    /**
     * Process a single queue item
     *
     * @param ProcessQueue $queueItem
     * @throws \Exception on processing failure
     */
    public function process(ProcessQueue $queueItem): void;

    /**
     * Get the process type this processor handles
     *
     * @return string
     */
    public function getProcessType(): string;
}
```

### Processor Registry

**ProcessorRegistry**
Location: `src/Service/QueueProcessor/ProcessorRegistry.php`

```php
namespace App\Service\QueueProcessor;

class ProcessorRegistry
{
    /** @var array<string, ProcessorInterface> */
    private array $processors = [];

    /**
     * Register a processor
     */
    public function register(ProcessorInterface $processor): void
    {
        $this->processors[$processor->getProcessType()] = $processor;
    }

    /**
     * Get processor for a process type
     *
     * @throws \RuntimeException if processor not found
     */
    public function getProcessor(string $processType): ProcessorInterface
    {
        if (!isset($this->processors[$processType])) {
            throw new \RuntimeException(
                sprintf('No processor registered for type: %s', $processType)
            );
        }

        return $this->processors[$processType];
    }

    /**
     * Check if processor exists for type
     */
    public function hasProcessor(string $processType): bool
    {
        return isset($this->processors[$processType]);
    }

    /**
     * Get all registered processor types
     *
     * @return array<string>
     */
    public function getRegisteredTypes(): array
    {
        return array_keys($this->processors);
    }
}
```

### Processor Registry Configuration

**Configure as a service with autoconfiguration**
Location: `config/services.yaml`

```yaml
services:
    # ... existing services

    # Processor Registry
    App\Service\QueueProcessor\ProcessorRegistry:
        public: true

    # Auto-configure all processors
    _instanceof:
        App\Service\QueueProcessor\ProcessorInterface:
            tags: ['app.queue_processor']

    # Compiler pass to register processors
    App\DependencyInjection\Compiler\ProcessorRegistryPass:
        tags:
            - { name: kernel.compiler_pass }
```

**Compiler Pass**
Location: `src/DependencyInjection/Compiler/ProcessorRegistryPass.php`

```php
namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use App\Service\QueueProcessor\ProcessorRegistry;

class ProcessorRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ProcessorRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(ProcessorRegistry::class);
        $processors = $container->findTaggedServiceIds('app.queue_processor');

        foreach (array_keys($processors) as $id) {
            $registry->addMethodCall('register', [new Reference($id)]);
        }
    }
}
```

### Console Command

**QueueProcessCommand**
Location: `src/Command/QueueProcessCommand.php`

```php
namespace App\Command;

use App\Service\ProcessQueueService;
use App\Service\QueueProcessor\ProcessorRegistry;
use App\Service\DiscordWebhookService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:queue:process',
    description: 'Process pending items in the processing queue'
)]
class QueueProcessCommand extends Command
{
    public function __construct(
        private ProcessQueueService $queueService,
        private ProcessorRegistry $processorRegistry,
        private DiscordWebhookService $discordService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of items to process',
                100
            )
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED,
                'Only process specific type',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $type = $input->getOption('type');

        // Get pending items
        $queueItems = $this->queueService->getNextBatch($limit, $type);

        if (empty($queueItems)) {
            // Cron-optimized: exit silently if no items
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Processing %d queue items...</info>',
            count($queueItems)
        ));

        $processed = 0;
        $failed = 0;

        foreach ($queueItems as $queueItem) {
            try {
                // Mark as processing
                $this->queueService->markProcessing($queueItem);

                // Get processor for this type
                $processor = $this->processorRegistry->getProcessor(
                    $queueItem->getProcessType()
                );

                // Process the item
                $processor->process($queueItem);

                // Mark complete and delete
                $this->queueService->markComplete($queueItem);

                $processed++;

                $output->writeln(sprintf(
                    '  ✓ Processed %s for item #%d',
                    $queueItem->getProcessType(),
                    $queueItem->getItem()->getId()
                ));

            } catch (\Exception $e) {
                $failed++;

                // Mark as failed
                $errorMessage = sprintf(
                    '%s: %s',
                    get_class($e),
                    $e->getMessage()
                );
                $this->queueService->markFailed($queueItem, $errorMessage);

                // Log the error
                $this->logger->error('Queue processing failed', [
                    'queue_id' => $queueItem->getId(),
                    'process_type' => $queueItem->getProcessType(),
                    'item_id' => $queueItem->getItem()->getId(),
                    'error' => $errorMessage,
                    'trace' => $e->getTraceAsString()
                ]);

                // Send Discord notification
                $this->sendFailureNotification($queueItem, $e);

                $output->writeln(sprintf(
                    '  ✗ <error>Failed %s for item #%d: %s</error>',
                    $queueItem->getProcessType(),
                    $queueItem->getItem()->getId(),
                    $e->getMessage()
                ));
            }

            // Clear entity manager every 10 items to prevent memory issues
            // Note: Safe to clear because we're done with the queue item
            // (either deleted on success or updated to 'failed' status)
            if (($processed + $failed) % 10 === 0) {
                $this->em->clear();
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Completed: %d processed, %d failed</info>',
            $processed,
            $failed
        ));

        return Command::SUCCESS;
    }

    private function sendFailureNotification(
        \App\Entity\ProcessQueue $queueItem,
        \Exception $e
    ): void {
        try {
            $message = sprintf(
                "**Queue Processing Failed** :warning:\n\n" .
                "**Type:** %s\n" .
                "**Item:** %s (ID: %d)\n" .
                "**Error:** %s\n" .
                "**Attempts:** %d",
                $queueItem->getProcessType(),
                $queueItem->getItem()->getName(),
                $queueItem->getItem()->getId(),
                $e->getMessage(),
                $queueItem->getAttempts()
            );

            $this->discordService->sendMessage('system_events', $message);
        } catch (\Exception $discordException) {
            // Log but don't throw - don't want Discord failures to crash processing
            $this->logger->error('Failed to send Discord notification', [
                'error' => $discordException->getMessage()
            ]);
        }
    }
}
```

### Configuration

**Environment Variables**
Add to `.env`:
```env
# Queue Processing
QUEUE_PROCESS_BATCH_SIZE=100
```

### Cron Setup

**Crontab Entry**
```bash
# Process queue every 5 minutes
*/5 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:queue:process >> var/log/queue-process.log 2>&1
```

## Implementation Steps

1. **Create ProcessorInterface**
   - Create `src/Service/QueueProcessor/ProcessorInterface.php`
   - Define `process()` method signature
   - Define `getProcessType()` method signature
   - Add proper PHPDoc documentation

2. **Create ProcessorRegistry**
   - Create `src/Service/QueueProcessor/ProcessorRegistry.php`
   - Implement `register()` method
   - Implement `getProcessor()` method with error handling
   - Implement `hasProcessor()` method
   - Implement `getRegisteredTypes()` method

3. **Create Compiler Pass**
   - Create `src/DependencyInjection/Compiler/ProcessorRegistryPass.php`
   - Implement auto-registration of tagged processors
   - Test with a dummy processor to verify it works

4. **Update services.yaml**
   - Add ProcessorRegistry service definition
   - Add `_instanceof` configuration for auto-tagging
   - Register compiler pass

5. **Create QueueProcessCommand**
   - Create `src/Command/QueueProcessCommand.php`
   - Implement `configure()` with options
   - Implement `execute()` with batch processing loop
   - Add error handling with try-catch
   - Implement `sendFailureNotification()` method
   - Add memory management (clear entity manager every 10 items)
   - Add logging for successes and failures

6. **Test Command**
   - Run: `docker compose exec php php bin/console app:queue:process`
   - Verify command exits cleanly if no items in queue
   - Add a test queue item manually in database
   - Verify command finds item and attempts to process it
   - Verify error handling works (no processor registered yet)

7. **Add Logging Configuration**
   - Create dedicated log channel for queue processing
   - Update `config/packages/monolog.yaml` if needed

8. **Document Cron Setup**
   - Add cron example to CLAUDE.md
   - Document log file location

9. **Document Maintenance Procedures**
   - Document how to find stuck 'processing' items
   - Document how to manually reset stuck items to 'pending'
   - Add cleanup query to CLAUDE.md for reference

## Edge Cases & Error Handling

### No Processor Registered
- **Scenario**: Queue item exists but no processor registered for its type
- **Handling**: Mark as failed with "No processor registered" message
- **Notification**: Send Discord alert
- **Resolution**: Indicates missing processor implementation or wrong process type

### Processor Throws Exception
- **Scenario**: Processor code throws exception during processing
- **Handling**: Catch exception, mark as failed, log error, send Discord notification
- **Queue Item**: Remains in database with status='failed' for review
- **Recovery**: Manual review and possible retry after fixing processor code

### Database Connection Lost or Command Crash
- **Scenario**: Database connection drops or command crashes during processing
- **Handling**: Let exception bubble up, command exits with error code
- **Recovery**: Cron will retry in 5 minutes
- **Stuck items**: Items with status='processing' will remain stuck (not fetched by next run)
- **Manual cleanup needed**: Admin must manually reset stuck items to 'pending' or delete them
- **Query to find stuck items**: `SELECT * FROM process_queue WHERE status='processing' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)`
- **Future enhancement**: Add auto-cleanup of stale 'processing' items (older than X minutes)

### Discord Notification Fails
- **Scenario**: Discord API is down or webhook invalid
- **Handling**: Log error but continue processing other items
- **Reasoning**: Don't want notification failures to stop queue processing

### Memory Exhaustion
- **Scenario**: Processing large batch of items
- **Handling**: Clear entity manager every 10 items
- **Configuration**: Limit batch size via `--limit` option
- **Monitoring**: Log memory usage at intervals

### Concurrent Execution
- **Scenario**: Cron runs command while previous execution still running
- **Handling**: Items marked 'processing' won't be fetched again (due to unique constraint)
- **Protection**: Database unique constraint on (item_id, process_type, status) prevents race conditions
- **Result**: Only one instance can mark an item as 'processing' at a time
- **Safe**: Multiple command instances can run safely without duplicate processing

## Dependencies

### Blocking Dependencies
- Task 61: Queue Processing System Foundation (MUST be completed - needs ProcessQueue entity and service)

### Related Tasks (same feature)
- Task 63: Price Trend Calculation Processor (parallel - can be developed alongside)
- Task 64: Price Anomaly Detection Processor (parallel - can be developed alongside)
- Task 65: New Item Notification Processor (parallel - can be developed alongside)

### Can Be Done in Parallel With
- Tasks 63, 64, 65 can all be developed in parallel once this is done
- Processors are independent of each other

### External Dependencies
- DiscordWebhookService (already exists)
- Symfony Console component
- Monolog logger

## Acceptance Criteria

- [ ] ProcessorInterface created with proper method signatures
- [ ] ProcessorRegistry created with register/get/has methods
- [ ] ProcessorRegistryPass compiler pass created and registered
- [ ] services.yaml updated with processor autoconfiguration
- [ ] QueueProcessCommand created with all options
- [ ] Command processes items in FIFO order
- [ ] Command exits silently when no items to process
- [ ] Command marks items as 'processing' before processing
- [ ] Command deletes items after successful processing
- [ ] Command marks items as 'failed' on exception
- [ ] Failed processing sends Discord notification to 'system_events' webhook
- [ ] Error messages include queue ID, item ID, process type, and error details
- [ ] Entity manager cleared every 10 items to prevent memory issues
- [ ] Command supports --limit option to control batch size
- [ ] Command supports --type option to filter by process type
- [ ] Manual verification: Run command with no queue items (should exit cleanly)
- [ ] Manual verification: Add test queue item, command should process it
- [ ] Manual verification: Force processor error, verify Discord notification sent
- [ ] Manual verification: Check logs contain processing details
- [ ] Manual verification: Verify concurrent execution safety (run command twice simultaneously)
- [ ] Manual verification: Test stuck 'processing' items aren't fetched by next run
- [ ] Documentation includes query to find and cleanup stuck 'processing' items
- [ ] Integration verified: Command works with ProcessQueueService from Task 61

## Notes & Considerations

### Why Compiler Pass for Auto-Registration
- Automatic: New processors are registered automatically
- Clean: No manual registration in services.yaml
- Type-safe: Only classes implementing ProcessorInterface are registered
- Discoverable: Can query registry for all registered types

### Why Delete on Success, Keep on Failure
- Success: No need to keep processed items, reduces table size
- Failure: Keep for debugging and manual review
- Audit trail: Failed items show what went wrong and when
- Recovery: Can manually retry failed items if needed

### Why Discord for Failures
- Immediate notification of processing issues
- Can monitor queue health without checking logs
- Helps catch issues with new processor implementations
- User requested this in requirements

### Performance Considerations
- Batch size of 100 balances throughput and memory usage
- Entity manager clearing prevents memory leaks
- FIFO ordering ensures fairness
- Indexes from Task 61 make queries fast

### Stuck 'processing' Items
- **Issue**: If command crashes mid-processing, items remain with status='processing'
- **Impact**: These items won't be fetched by subsequent runs (query filters for 'pending' only)
- **Detection**: Look for 'processing' items older than expected processing time (e.g., 1 hour)
- **Resolution**: Manually update to 'pending' or delete and let sync re-queue
- **Why not auto-fix**: Risk of race condition if multiple commands running
- **Future**: Could add `--cleanup-stuck` option to safely reset old 'processing' items

### Future Enhancements (NOT in MVP)
- Auto-cleanup of stuck 'processing' items (with configurable timeout)
- Retry logic with exponential backoff
- Priority queue support
- Dead letter queue for permanently failed items
- Metrics/monitoring dashboard
- Parallel processing with worker pools
- Heartbeat mechanism to detect crashed processors

## Related Tasks

- Task 61: Queue Processing System Foundation (blocking - must be completed first)
- Task 63: Price Trend Calculation Processor (parallel - can develop after this)
- Task 64: Price Anomaly Detection Processor (parallel - can develop after this)
- Task 65: New Item Notification Processor (parallel - can develop after this)

---

## Task Completion Instructions

When this task is fully complete:

1. **Update this file**:
   - Change `**Status**: Not Started` to `**Status**: Completed`
   - Add completion date: `**Completed**: [Date]`

2. **Move to completed folder**:
   - Move this file from `tasks/` to `tasks/completed/`
   - Keep the same filename

3. **Verify all acceptance criteria** are checked off before marking as complete
