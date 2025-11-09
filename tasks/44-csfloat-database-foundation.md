# CSFloat Database Foundation

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-08

## Overview

Create database entity and migration for storing CSFloat marketplace mapping data. This foundation enables linking CS2 inventory items to CSFloat marketplace listings using def_index and paint_index identifiers.

## Problem Statement

CS2 items need to be mapped to CSFloat marketplace for price comparison and direct marketplace links. CSFloat uses `def_index` (item definition index) and `paint_index` (skin/finish index) to identify items. We need to store this mapping in the database for efficient linking without repeated API lookups.

## Requirements

### Functional Requirements
- Store CSFloat identifiers (def_index, paint_index) for each Item
- One-to-one relationship: Item → ItemCsfloat
- Track when mapping was created/updated
- Support nullable fields (not all items may be on CSFloat)

### Non-Functional Requirements
- Indexed foreign key for fast Item lookups
- Unique constraint on Item to prevent duplicate mappings
- Timestamps for tracking data freshness
- Migration must be reversible

## Technical Approach

### Database Schema

**Table: `item_csfloat`**

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | INT (PK, AUTO_INCREMENT) | NO | Primary key |
| item_id | INT (FK → item.id) | NO | Reference to Item entity |
| def_index | INT | YES | CSFloat definition index (weapon type) |
| paint_index | INT | YES | CSFloat paint index (skin/finish) |
| created_at | DATETIME (IMMUTABLE) | NO | When mapping was created |
| updated_at | DATETIME (IMMUTABLE) | NO | Last update timestamp |

**Indexes:**
- `UNIQUE idx_item_csfloat_item_id` on `item_id` (one-to-one relationship)
- `idx_item_csfloat_def_paint` on `(def_index, paint_index)` (for reverse lookups)

**Foreign Keys:**
- `item_id` → `item.id` (ON DELETE CASCADE)

### Entity Relationship

```
Item (1) ←→ (0..1) ItemCsfloat
```

**Why nullable def_index/paint_index?**
- Not all CS2 items are tradeable on CSFloat (e.g., base items, some cases)
- API searches may fail temporarily
- Allows creating placeholder records during sync

### Entity Class

Location: `src/Entity/ItemCsfloat.php`

**Properties:**
- `id`: int (auto-increment)
- `item`: Item (ManyToOne relationship)
- `defIndex`: ?int (nullable)
- `paintIndex`: ?int (nullable)
- `createdAt`: DateTimeImmutable
- `updatedAt`: DateTimeImmutable

**Lifecycle Callbacks:**
- `#[ORM\PreUpdate]` to update `updatedAt` timestamp

**Repository:**
- `ItemCsfloatRepository` with standard Doctrine repository pattern
- Custom method: `findByItem(Item $item): ?ItemCsfloat`
- Custom method: `findByIndexes(int $defIndex, int $paintIndex): ?ItemCsfloat`
- Custom method: `findUnmappedItems(): array` (Items without ItemCsfloat records)

## Implementation Steps

1. **Create ItemCsfloat Entity**
   - Create `src/Entity/ItemCsfloat.php`
   - Add ORM annotations for table, indexes, foreign keys
   - Define properties: id, item, defIndex, paintIndex, timestamps
   - Add getters/setters with proper type hints
   - Add lifecycle callback for updatedAt

2. **Create ItemCsfloatRepository**
   - Create `src/Repository/ItemCsfloatRepository.php`
   - Extend `ServiceEntityRepository`
   - Add `findByItem(Item $item)` method
   - Add `findByIndexes(int $defIndex, int $paintIndex)` method
   - Add `findUnmappedItems()` query (LEFT JOIN where ItemCsfloat is NULL)

3. **Update Item Entity**
   - Add OneToOne relationship to ItemCsfloat
   - Add `csfloatMapping` property with `mappedBy="item"`
   - Add getter: `getCsfloatMapping(): ?ItemCsfloat`
   - No setter needed (relationship managed from ItemCsfloat side)

4. **Generate Migration**
   ```bash
   docker compose exec php php bin/console make:migration
   ```

5. **Review Migration**
   - Verify table creation SQL
   - Check indexes and foreign keys
   - Ensure ON DELETE CASCADE is set
   - Verify nullable constraints

6. **Run Migration**
   ```bash
   docker compose exec php php bin/console doctrine:migrations:migrate
   ```

7. **Verify Database Schema**
   ```bash
   docker compose exec php php bin/console doctrine:schema:validate
   ```

## Edge Cases & Error Handling

- **Item deleted**: ON DELETE CASCADE removes ItemCsfloat mapping automatically
- **Duplicate mapping**: Unique index on item_id prevents duplicates at DB level
- **NULL indexes**: Allowed for items not found on CSFloat or during initial sync
- **Concurrent updates**: Doctrine handles optimistic locking via updatedAt
- **Migration rollback**: Down migration drops table cleanly

## Dependencies

### Blocking Dependencies
None (foundation task)

### Related Tasks (CSFloat Integration Feature)
- Task 45: CSFloat API service - uses ItemCsfloat to store mappings (parallel)
- Task 46: CSFloat sync cronjob - populates ItemCsfloat table (depends on this + Task 45)
- Task 47: Admin settings UI - displays mapping status (depends on this)
- Task 48: Frontend CSFloat links - uses ItemCsfloat to generate links (depends on this)

### Can Be Done in Parallel With
- Task 45: CSFloat API service (both are foundation tasks)
- Task 42: Discord admin settings UI (unrelated feature)

### External Dependencies
- Doctrine ORM (already in project)
- PHP 8.4 (already in project)
- MariaDB 11.x (already in project)

## Acceptance Criteria

- [ ] ItemCsfloat entity created with all required properties
- [ ] ItemCsfloatRepository created with custom query methods
- [ ] Item entity updated with OneToOne relationship
- [ ] Migration generated and reviewed
- [ ] Migration successfully applied to database
- [ ] `doctrine:schema:validate` passes without errors
- [ ] Unique constraint on item_id enforced
- [ ] Foreign key cascade delete verified
- [ ] Indexes created for performance (item_id, def_index+paint_index)
- [ ] Nullable def_index/paint_index allowed

## Manual Verification Steps

### 1. Verify Entity Creation

```bash
# Check entity exists
docker compose exec php php bin/console debug:autowiring ItemCsfloatRepository
```

### 2. Run Migration

```bash
# Generate migration
docker compose exec php php bin/console make:migration

# Review migration file
cat migrations/VersionXXXX_create_item_csfloat.php

# Apply migration
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

### 3. Validate Schema

```bash
# Should show no errors
docker compose exec php php bin/console doctrine:schema:validate
```

### 4. Test Database Structure

```bash
# Connect to database
docker compose exec mariadb mysql -u app_user -p cs2inventory

# Verify table structure
DESCRIBE item_csfloat;

# Check indexes
SHOW INDEXES FROM item_csfloat;

# Check foreign keys
SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM
    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE
    TABLE_NAME = 'item_csfloat'
    AND REFERENCED_TABLE_NAME IS NOT NULL;
```

### 5. Test Relationship

```bash
# Create test record via SQL
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
INSERT INTO item_csfloat (item_id, def_index, paint_index, created_at, updated_at)
SELECT id, 7, 38, NOW(), NOW() FROM item LIMIT 1;
"

# Verify cascade delete
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
SELECT COUNT(*) as before_delete FROM item_csfloat;
DELETE FROM item WHERE id = (SELECT item_id FROM item_csfloat LIMIT 1);
SELECT COUNT(*) as after_delete FROM item_csfloat;
"
# Should show one less record after delete
```

### 6. Test Repository Methods (after Tasks 45-46)

```php
// Will be tested when CSFloat sync command is implemented
// findUnmappedItems() should return Items without CSFloat mapping
// findByItem() should return mapping for specific Item
// findByIndexes() should return ItemCsfloat by def_index/paint_index
```

## Notes & Considerations

- **No pricing data**: This task only stores identifiers (def_index, paint_index). Pricing will be handled separately if needed in future tasks
- **One-to-one relationship**: Each Item can have at most one CSFloat mapping
- **Lazy loading**: Relationship uses lazy loading (fetch='LAZY') for performance
- **Immutable timestamps**: DateTimeImmutable prevents accidental modification
- **NULL indexes acceptable**: Items not on CSFloat marketplace (cases, base knives, etc.) will have NULL indexes
- **Future enhancement**: Could add `lastCheckedAt` field to track when API was last queried
- **CSFloat URL pattern**: `https://csfloat.com/search?def_index={defIndex}&paint_index={paintIndex}&sort_by=lowest_price`

## Related Tasks

- Task 45: CSFloat API service (can be parallel, both are foundation)
- Task 46: CSFloat sync cronjob (depends on Tasks 44 & 45)
- Task 47: Admin settings UI (depends on Task 44)
- Task 48: Frontend CSFloat links (depends on Task 44)
