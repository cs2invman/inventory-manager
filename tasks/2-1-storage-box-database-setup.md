# Storage Box Database Setup

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-02
**Part of**: Task 2 - Storage Box Management System (Phase 1)

## Overview

Create the database foundation for storage box management by adding the StorageBox entity and updating ItemUser to reference it.

## Goals

1. Create StorageBox entity with all required fields
2. Update ItemUser entity to have a ManyToOne relationship to StorageBox
3. Create and run database migration
4. Create StorageBoxRepository with basic queries

## Implementation Steps

### 1. Create StorageBox Entity

Generate and configure the entity:

```bash
docker compose exec php php bin/console make:entity StorageBox
```

Add these fields:
- `id` (auto-generated)
- `user` (ManyToOne to User, CASCADE delete)
- `assetId` (string, length 100, unique)
- `name` (string, length 255)
- `itemCount` (integer, default 0)
- `modificationDate` (DateTimeImmutable, nullable)
- `createdAt` (DateTimeImmutable)
- `updatedAt` (DateTimeImmutable)

Add lifecycle callbacks for timestamps:

```php
#[ORM\HasLifecycleCallbacks]
class StorageBox
{
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

### 2. Update ItemUser Entity

**File**: `src/Entity/ItemUser.php`

Remove the old `storageBoxName` field and add the relationship:

```php
#[ORM\ManyToOne(targetEntity: StorageBox::class)]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
private ?StorageBox $storageBox = null;

public function getStorageBox(): ?StorageBox
{
    return $this->storageBox;
}

public function setStorageBox(?StorageBox $storageBox): static
{
    $this->storageBox = $storageBox;
    return $this;
}
```

Remove the old `storageBoxName` getter/setter methods.

### 3. Update User Entity

**File**: `src/Entity/User.php`

Add the inverse side of the relationship:

```php
#[ORM\OneToMany(mappedBy: 'user', targetEntity: StorageBox::class, cascade: ['remove'])]
private Collection $storageBoxes;

public function __construct()
{
    // ... existing code ...
    $this->storageBoxes = new ArrayCollection();
}

public function getStorageBoxes(): Collection
{
    return $this->storageBoxes;
}

public function addStorageBox(StorageBox $storageBox): static
{
    if (!$this->storageBoxes->contains($storageBox)) {
        $this->storageBoxes->add($storageBox);
        $storageBox->setUser($this);
    }
    return $this;
}

public function removeStorageBox(StorageBox $storageBox): static
{
    if ($this->storageBoxes->removeElement($storageBox)) {
        if ($storageBox->getUser() === $this) {
            $storageBox->setUser(null);
        }
    }
    return $this;
}
```

### 4. Create Migration

Generate the migration:

```bash
docker compose exec php php bin/console make:migration
```

Review the generated migration to ensure:
- `storage_box` table is created with all fields
- `item_user.storage_box_name` column is removed
- `item_user.storage_box_id` foreign key is added
- Index on `storage_box_id` is created

Run the migration:

```bash
docker compose exec php php bin/console doctrine:migrations:migrate
```

### 5. Create StorageBoxRepository

**File**: `src/Repository/StorageBoxRepository.php`

This should be auto-generated, but add these custom methods:

```php
/**
 * Find all storage boxes for a user
 */
public function findByUser(User $user): array
{
    return $this->createQueryBuilder('sb')
        ->where('sb.user = :user')
        ->setParameter('user', $user)
        ->orderBy('sb.name', 'ASC')
        ->getQuery()
        ->getResult();
}

/**
 * Find a storage box by assetId for a specific user
 */
public function findByAssetId(User $user, string $assetId): ?StorageBox
{
    return $this->createQueryBuilder('sb')
        ->where('sb.user = :user')
        ->andWhere('sb.assetId = :assetId')
        ->setParameter('user', $user)
        ->setParameter('assetId', $assetId)
        ->getQuery()
        ->getOneOrNullResult();
}

/**
 * Find all storage boxes with actual item counts from database
 */
public function findWithItemCount(User $user): array
{
    return $this->createQueryBuilder('sb')
        ->select('sb', 'COUNT(iu.id) as actualItemCount')
        ->leftJoin('App\Entity\ItemUser', 'iu', 'WITH', 'iu.storageBox = sb.id')
        ->where('sb.user = :user')
        ->setParameter('user', $user)
        ->groupBy('sb.id')
        ->orderBy('sb.name', 'ASC')
        ->getQuery()
        ->getResult();
}
```

## Testing

### Manual Verification

1. **Database Schema**:
   ```bash
   docker compose exec php php bin/console doctrine:schema:validate
   ```
   Should show no mapping or database errors.

2. **Check Tables**:
   ```bash
   docker compose exec php php bin/console dbal:run-sql "DESCRIBE storage_box"
   docker compose exec php php bin/console dbal:run-sql "DESCRIBE item_user"
   ```
   Verify columns exist as expected.

3. **Test Entity Creation**:
   Create a simple test script or use `php bin/console` to verify:
   ```php
   $storageBox = new StorageBox();
   $storageBox->setName('Test Box');
   $storageBox->setAssetId('123456');
   $storageBox->setItemCount(0);
   // Should work without errors
   ```

## Acceptance Criteria

- [ ] StorageBox entity created with all required fields
- [ ] ItemUser entity has `storageBox` ManyToOne relationship
- [ ] User entity has `storageBoxes` OneToMany relationship
- [ ] Database migration created and runs successfully
- [ ] `storage_box` table exists in database
- [ ] `item_user.storage_box_id` foreign key exists
- [ ] `item_user.storage_box_name` column is removed
- [ ] StorageBoxRepository created with custom query methods
- [ ] `doctrine:schema:validate` passes with no errors
- [ ] No existing application functionality is broken

## Dependencies

None - this is the foundation task.

## Next Task

**Task 2-2**: Storage Box Import Integration - Implement parsing and syncing of storage boxes during inventory import.

## Related Files

- `src/Entity/StorageBox.php` (new)
- `src/Entity/ItemUser.php` (modified)
- `src/Entity/User.php` (modified)
- `src/Repository/StorageBoxRepository.php` (new)
- `migrations/VersionXXXXXXXXXXXXXX.php` (new)