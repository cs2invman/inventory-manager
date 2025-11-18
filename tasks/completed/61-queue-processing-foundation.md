# Queue Processing System Foundation

**Status**: Completed
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-17
**Completed**: 2025-11-18

## Overview

Create the foundational database schema and core services for a flexible, queue-based processing system that can handle various types of asynchronous item processing tasks. This system will allow the application to defer processing of events (like price updates and new item detection) to a background worker that runs via cron.

## Problem Statement

Currently, the `app:steam:sync-items` command processes items synchronously, which limits our ability to:
- React to specific events (price updates, new items) with custom logic
- Perform expensive calculations without blocking the sync process
- Send notifications based on data changes
- Scale processing of different event types independently

We need a queue system that:
- Stores pending processing tasks in a persistent database table
- Supports multiple process types with a flexible, extensible design
- Prevents duplicate pending entries (same item + process type combination)
- Automatically cleans up processed items (delete on success)
- Only keeps failed items for review, then cleans up after resolution
- Integrates with existing Steam sync workflow

## Requirements

### Functional Requirements
- Create `ProcessQueue` entity with process type, item reference, status tracking
- Prevent duplicate pending entries (unique constraint on item_id + process_type for pending/processing status)
- Delete queue entries after successful processing (table only holds pending and failed items)
- Track processing failures with error messages and timestamp
- Support FIFO (First In, First Out) processing order
- Extensible design that allows new process types to be added easily
- Deduplication during bulk enqueue operations

### Non-Functional Requirements
- Minimal impact on existing sync performance
- Database-backed persistence (survive application restarts)
- Clean separation between queue management and processing logic
- Efficient bulk insertion during sync operations
- Handle concurrent access (multiple queue processors if needed in future)

## Technical Approach

### Database Changes

**New Entity: ProcessQueue**
```php
/**
 * @ORM\Entity(repositoryClass=ProcessQueueRepository::class)
 * @ORM\Table(
 *     name="process_queue",
 *     indexes={
 *         @ORM\Index(name="idx_status_created", columns={"status", "created_at"}),
 *         @ORM\Index(name="idx_process_type", columns={"process_type"})
 *     },
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(
 *             name="uniq_item_type_pending",
 *             columns={"item_id", "process_type", "status"}
 *         )
 *     }
 * )
 */
class ProcessQueue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $processType;

    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Item $item;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'pending'; // pending, processing, failed

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $failedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    // Getters and setters...
}
```

**Process Types (Constants)**
- `PRICE_UPDATED`: Triggered when a new ItemPrice is added
- `NEW_ITEM`: Triggered when a new Item is created during import
- (Future types can be added as needed)

**Migration**
- Create `process_queue` table with indexes for efficient querying
- Indexes on `status + created_at` for FIFO processing
- Index on `process_type` for filtering by type
- **Unique constraint** on `item_id + process_type + status` to prevent duplicate pending entries
- Foreign key to `item` table with CASCADE delete

**Important**: The unique constraint on (item_id, process_type, status) means:
- Only ONE pending entry per item+type combination
- Only ONE processing entry per item+type combination
- Multiple failed entries allowed (for review/debugging)
- When sync tries to enqueue duplicate, it should be silently skipped

### Service Layer

**ProcessQueueService**
Location: `src/Service/ProcessQueueService.php`

```php
class ProcessQueueService
{
    public function __construct(
        private ProcessQueueRepository $repository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    /**
     * Add item to processing queue (skips if already queued)
     *
     * @return ProcessQueue|null Returns queue item if added, null if already exists
     */
    public function enqueue(Item $item, string $processType): ?ProcessQueue
    {
        // Check if already queued (pending or processing)
        if ($this->repository->existsPendingOrProcessing($item, $processType)) {
            return null; // Already queued, skip
        }

        $queueItem = new ProcessQueue();
        $queueItem->setItem($item);
        $queueItem->setProcessType($processType);
        $queueItem->setStatus('pending');
        $queueItem->setCreatedAt(new \DateTime());

        $this->em->persist($queueItem);
        $this->em->flush();

        return $queueItem;
    }

    /**
     * Bulk enqueue items (for sync operations)
     * Automatically deduplicates against existing queue entries
     *
     * @param Item[] $items
     * @return int Number of items actually enqueued (after deduplication)
     */
    public function enqueueBulk(array $items, string $processType): int
    {
        if (empty($items)) {
            return 0;
        }

        // Get item IDs that already have pending/processing queue entries
        $itemIds = array_map(fn($item) => $item->getId(), $items);
        $existingItemIds = $this->repository->findExistingItemIds($itemIds, $processType);

        $count = 0;
        $skipped = 0;

        foreach ($items as $item) {
            // Skip if already queued
            if (in_array($item->getId(), $existingItemIds, true)) {
                $skipped++;
                continue;
            }

            $queueItem = new ProcessQueue();
            $queueItem->setItem($item);
            $queueItem->setProcessType($processType);
            $queueItem->setStatus('pending');
            $queueItem->setCreatedAt(new \DateTime());

            $this->em->persist($queueItem);
            $count++;

            // Batch flush every 50 items
            if ($count % 50 === 0) {
                $this->em->flush();
            }
        }

        // Flush remaining
        if ($count % 50 !== 0) {
            $this->em->flush();
        }

        if ($skipped > 0) {
            $this->logger->debug('Skipped duplicate queue entries', [
                'process_type' => $processType,
                'skipped_count' => $skipped,
                'enqueued_count' => $count
            ]);
        }

        return $count;
    }

    /**
     * Get next batch of pending items (FIFO)
     */
    public function getNextBatch(int $limit = 100, ?string $processType = null): array
    {
        return $this->repository->findPendingBatch($limit, $processType);
    }

    /**
     * Mark item as processing
     */
    public function markProcessing(ProcessQueue $queueItem): void
    {
        $queueItem->setStatus('processing');
        $queueItem->setAttempts($queueItem->getAttempts() + 1);
        $this->em->flush();
    }

    /**
     * Mark item as complete and delete
     */
    public function markComplete(ProcessQueue $queueItem): void
    {
        $this->em->remove($queueItem);
        $this->em->flush();
    }

    /**
     * Mark item as failed
     */
    public function markFailed(ProcessQueue $queueItem, string $errorMessage): void
    {
        $queueItem->setStatus('failed');
        $queueItem->setFailedAt(new \DateTime());
        $queueItem->setErrorMessage($errorMessage);
        $this->em->flush();
    }

    /**
     * Get failed items for review
     */
    public function getFailedItems(int $limit = 100): array
    {
        return $this->repository->findFailedItems($limit);
    }
}
```

**ProcessQueueRepository**
Location: `src/Repository/ProcessQueueRepository.php`

```php
class ProcessQueueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessQueue::class);
    }

    /**
     * Check if item already has pending or processing queue entry
     */
    public function existsPendingOrProcessing(Item $item, string $processType): bool
    {
        $count = (int) $this->createQueryBuilder('pq')
            ->select('COUNT(pq.id)')
            ->where('pq.item = :item')
            ->andWhere('pq.processType = :processType')
            ->andWhere('pq.status IN (:statuses)')
            ->setParameter('item', $item)
            ->setParameter('processType', $processType)
            ->setParameter('statuses', ['pending', 'processing'])
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Find item IDs that already have pending/processing queue entries
     * Used for bulk deduplication
     *
     * @param array $itemIds
     * @return array Array of item IDs that already exist in queue
     */
    public function findExistingItemIds(array $itemIds, string $processType): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $result = $this->createQueryBuilder('pq')
            ->select('IDENTITY(pq.item) as item_id')
            ->where('pq.item IN (:itemIds)')
            ->andWhere('pq.processType = :processType')
            ->andWhere('pq.status IN (:statuses)')
            ->setParameter('itemIds', $itemIds)
            ->setParameter('processType', $processType)
            ->setParameter('statuses', ['pending', 'processing'])
            ->getQuery()
            ->getArrayResult();

        return array_column($result, 'item_id');
    }

    /**
     * Find pending items in FIFO order
     */
    public function findPendingBatch(int $limit = 100, ?string $processType = null): array
    {
        $qb = $this->createQueryBuilder('pq')
            ->where('pq.status = :status')
            ->setParameter('status', 'pending')
            ->orderBy('pq.createdAt', 'ASC')
            ->setMaxResults($limit);

        if ($processType !== null) {
            $qb->andWhere('pq.processType = :processType')
               ->setParameter('processType', $processType);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find failed items for review
     */
    public function findFailedItems(int $limit = 100): array
    {
        return $this->createQueryBuilder('pq')
            ->where('pq.status = :status')
            ->setParameter('status', 'failed')
            ->orderBy('pq.failedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count pending items by type
     */
    public function countPendingByType(string $processType): int
    {
        return (int) $this->createQueryBuilder('pq')
            ->select('COUNT(pq.id)')
            ->where('pq.status = :status')
            ->andWhere('pq.processType = :processType')
            ->setParameter('status', 'pending')
            ->setParameter('processType', $processType)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
```

### Integration with ItemSyncService

Update `ItemSyncService::syncItemsFromFile()` to enqueue items:

```php
// After adding new ItemPrice
if ($priceWasAdded) {
    $this->processQueueService->enqueue($item, ProcessQueue::TYPE_PRICE_UPDATED);
}

// After creating new Item
if ($itemWasCreated) {
    $this->processQueueService->enqueue($item, ProcessQueue::TYPE_NEW_ITEM);
}
```

For bulk operations, collect items and use `enqueueBulk()`:
```php
// At end of chunk processing
if (!empty($itemsWithNewPrices)) {
    $this->processQueueService->enqueueBulk(
        $itemsWithNewPrices,
        ProcessQueue::TYPE_PRICE_UPDATED
    );
}

if (!empty($newlyCreatedItems)) {
    $this->processQueueService->enqueueBulk(
        $newlyCreatedItems,
        ProcessQueue::TYPE_NEW_ITEM
    );
}
```

### Configuration

Add process type constants to ProcessQueue entity:
```php
class ProcessQueue
{
    public const TYPE_PRICE_UPDATED = 'PRICE_UPDATED';
    public const TYPE_NEW_ITEM = 'NEW_ITEM';

    // Future types can be added here
}
```

## Implementation Steps

1. **Create ProcessQueue Entity**
   - Create `src/Entity/ProcessQueue.php` with all properties and relationships
   - Add process type constants
   - Implement getters and setters
   - Add proper annotations for indexes

2. **Create ProcessQueueRepository**
   - Create `src/Repository/ProcessQueueRepository.php`
   - Implement `existsPendingOrProcessing()` method
   - Implement `findExistingItemIds()` method for bulk deduplication
   - Implement `findPendingBatch()` method
   - Implement `findFailedItems()` method
   - Implement `countPendingByType()` method

3. **Create Database Migration**
   - Run: `docker compose exec php php bin/console make:migration`
   - Review migration file to ensure indexes are correct
   - **CRITICAL**: Verify unique constraint on (item_id, process_type, status) is in migration
   - Run: `docker compose exec php php bin/console doctrine:migrations:migrate`

4. **Create ProcessQueueService**
   - Create `src/Service/ProcessQueueService.php`
   - Inject LoggerInterface for deduplication logging
   - Implement `enqueue()` method with deduplication check
   - Implement `enqueueBulk()` method with bulk deduplication and batching
   - Implement `getNextBatch()` method
   - Implement `markProcessing()`, `markComplete()`, `markFailed()` methods
   - Implement `getFailedItems()` method

5. **Update ItemSyncService**
   - Inject `ProcessQueueService` into constructor
   - Track which items get new prices during sync
   - Track which items are newly created
   - Call `enqueueBulk()` at end of chunk processing for both types
   - Add logging for queue operations

6. **Test Queue Operations**
   - Run sync command and verify queue entries are created
   - Check database directly: `SELECT * FROM process_queue ORDER BY created_at DESC LIMIT 10;`
   - Verify items with new prices get PRICE_UPDATED entries
   - Verify new items get NEW_ITEM entries
   - **Test deduplication**: Run sync twice, verify duplicate items NOT added to queue
   - **Test unique constraint**: Verify database prevents duplicate (item_id, process_type, status) entries
   - Check logs for deduplication messages

## Edge Cases & Error Handling

### Duplicate Queue Entries (Prevented)
- **Scenario**: Same item gets multiple price updates during single sync operation
- **Handling**: Deduplication in `enqueueBulk()` - only first occurrence queued
- **Database protection**: Unique constraint on (item_id, process_type, status)
- **Example**: If item #123 has price updated twice in one sync chunk, only ONE PRICE_UPDATED entry created
- **Benefit**: Prevents queue bloat, ensures each item processed once per type

### Attempting to Queue Already-Pending Item
- **Scenario**: Sync tries to queue item that's already pending from previous sync
- **Handling**: `findExistingItemIds()` identifies and skips these items
- **Log**: Debug log shows skipped count
- **Result**: No error, just skip and continue

### Item Deletion
- **Scenario**: Item is deleted while queue entry exists
- **Handling**: CASCADE delete on foreign key handles this automatically
- **Verification**: Ensure ON DELETE CASCADE is in migration

### Database Connection Failures
- **Scenario**: Database connection fails during bulk enqueue
- **Handling**: Let exception bubble up - sync operation should fail if queue can't be populated
- **Reasoning**: Better to retry entire sync than have incomplete queue

### Memory Management
- **Scenario**: Enqueueing thousands of items in one sync operation
- **Handling**: Use batch flushing (every 50 items) in `enqueueBulk()`
- **Clear entity manager periodically**: Consider adding `$this->em->clear()` after flushes for very large batches

### Concurrent Processing
- **Scenario**: Multiple cron jobs running simultaneously
- **Handling**: Use `status = 'processing'` to prevent double-processing
- **Future consideration**: Add locking mechanism if concurrent processors are needed

## Dependencies

### Blocking Dependencies
None - this is the foundation task

### Related Tasks (same feature)
- Task 62: Queue Processor Command and Infrastructure (depends on this)
- Task 63: Price Trend Calculation Processor (depends on Task 62)
- Task 64: Price Anomaly Detection Processor (depends on Task 62)
- Task 65: New Item Notification Processor (depends on Task 62)

### Can Be Done in Parallel With
None - all other tasks depend on this foundation

### External Dependencies
- Doctrine ORM (already in project)
- Existing Item entity
- Existing ItemSyncService

## Acceptance Criteria

- [ ] ProcessQueue entity created with all required fields
- [ ] Entity includes `failedAt` field (NOT `processedAt`)
- [ ] Database migration created and executed successfully
- [ ] ProcessQueue table exists with proper indexes
- [ ] **Unique constraint on (item_id, process_type, status) enforced**
- [ ] Foreign key to item table with CASCADE delete configured
- [ ] ProcessQueueRepository created with deduplication methods (`existsPendingOrProcessing`, `findExistingItemIds`)
- [ ] ProcessQueueRepository created with all query methods
- [ ] ProcessQueueService created with all CRUD methods
- [ ] ProcessQueueService includes deduplication logic in `enqueue()` and `enqueueBulk()`
- [ ] Bulk enqueue method includes batch flushing for memory efficiency
- [ ] ItemSyncService updated to enqueue PRICE_UPDATED events
- [ ] ItemSyncService updated to enqueue NEW_ITEM events
- [ ] Manual verification: Run sync command and check process_queue table contains entries
- [ ] Manual verification: Verify items with new prices create PRICE_UPDATED entries
- [ ] Manual verification: Verify new items create NEW_ITEM entries
- [ ] **Manual verification: Run sync twice on same data, verify NO duplicate entries created**
- [ ] **Manual verification: Try to manually insert duplicate (item_id, type, status), verify constraint prevents it**
- [ ] Manual verification: Check logs show "skipped duplicate queue entries" when appropriate
- [ ] Manual verification: Deleting an item cascades to delete queue entries
- [ ] Manual verification: Failed items remain in table, pending/processing items are deleted on success
- [ ] Integration with existing features verified: sync still works as before

## Notes & Considerations

### Why Database-Backed Instead of Message Queue
- Simplicity: No need for RabbitMQ/Redis infrastructure
- Persistence: Queue survives application restarts
- Queryability: Can inspect queue state with SQL
- Good enough: For 5-minute cron jobs, database is sufficient
- Future migration: Can move to message queue if needed

### Why Delete After Processing (Not Update Status)
- Keeps table size manageable - only holds work that needs doing
- Failed items are preserved for debugging (status='failed')
- FIFO ordering works better with smaller table
- Successful processing means "done and gone" - no historical audit trail needed
- **Table never grows unbounded** - only contains pending + processing + failed items
- Failed items can be manually deleted after review/resolution

### Deduplication Strategy
- **Unique constraint**: Database-level protection against duplicates
- **Application-level check**: Prevents unnecessary INSERT attempts
- **Bulk optimization**: Single query to filter out existing items before bulk insert
- **Why include status in constraint**: Allows item to fail, then be re-queued after resolution
- **Example**: Item fails processing → status='failed', if re-queued → new pending entry can be created

### When Same Item Can Appear Multiple Times
- Different process types: Item can have both PRICE_UPDATED and NEW_ITEM entries
- Failed + pending: Item can have failed entry (for review) AND new pending entry (retry)
- **NOT allowed**: Multiple pending entries for same item+type
- **NOT allowed**: Multiple processing entries for same item+type

### Performance Considerations
- Indexes on `status + created_at` ensure fast FIFO queries
- Batch flushing prevents memory exhaustion
- CASCADE delete prevents orphaned queue entries
- Consider adding `processed_at` index if failed items table grows large

### Extensibility
- New process types: Just add constant and implement processor
- Future: Could add `priority` column if needed (keep FIFO for MVP)
- Future: Could add `metadata` JSON column for processor-specific data
- Future: Could add retry logic (max_attempts, backoff strategy)

## Related Tasks

- Task 62: Queue Processor Command and Infrastructure (depends on this)
- Task 63: Price Trend Calculation Processor (depends on Task 62)
- Task 64: Price Anomaly Detection Processor (depends on Task 62)
- Task 65: New Item Notification Processor (depends on Task 62)

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
