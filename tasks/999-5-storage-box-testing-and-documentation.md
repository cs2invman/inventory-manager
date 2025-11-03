# Storage Box Testing & Documentation

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-02
**Part of**: Task 2 - Storage Box Management System (Phase 5)

## Overview

Comprehensive testing of the storage box system and updating documentation.

## Prerequisites

- Task 2-1 (Storage Box Database Setup) must be completed
- Task 2-2 (Storage Box Import Integration) must be completed
- Task 2-3 (Storage Box Display) must be completed
- Task 2-4 (Deposit/Withdraw Workflow) must be completed

## Goals

1. Perform comprehensive end-to-end testing
2. Test all edge cases
3. Update CLAUDE.md documentation
4. Verify performance with large inventories
5. Ensure no regressions in existing functionality

## Testing Checklist

### End-to-End Workflow Testing

#### Complete User Journey
- [ ] Import inventory containing storage boxes
- [ ] Verify storage boxes appear in database
- [ ] View inventory page - verify all items shown
- [ ] Verify storage box cards display correctly
- [ ] Filter by "Active Inventory Only"
- [ ] Filter by specific storage box
- [ ] Deposit items into storage box
- [ ] Verify items moved to storage
- [ ] Withdraw items from storage box
- [ ] Verify items moved to active inventory
- [ ] Re-import inventory - verify no duplicates

#### Import Testing
- [ ] Import with multiple storage boxes (4+)
- [ ] Import with empty storage box (0 items)
- [ ] Import with full storage box (1000 items)
- [ ] Re-import same inventory - verify updates not duplicates
- [ ] Import preview shows correct storage box count
- [ ] Storage box names parsed correctly
- [ ] Storage box item counts parsed correctly
- [ ] Storage box modification dates parsed correctly

#### Display Testing
- [ ] All items displayed on inventory page (active + storage)
- [ ] Storage indicator badges show correct box names
- [ ] Storage boxes styled distinctly from regular items
- [ ] Item counts on storage boxes are accurate
- [ ] Filtering works correctly for all options
- [ ] Stats (total, active, stored) are accurate
- [ ] Responsive design works on mobile
- [ ] No console errors in browser

#### Deposit Testing
- [ ] Deposit form displays correctly
- [ ] Invalid JSON shows error message
- [ ] Empty JSON shows error message
- [ ] Preview shows correct items to deposit
- [ ] Preview shows correct item counts (before/after)
- [ ] Confirm deposits items atomically
- [ ] ItemUser.storageBox relationship set correctly
- [ ] Storage box item count updated
- [ ] Success message displayed
- [ ] Redirects to correct page
- [ ] Session cleared after success

#### Withdraw Testing
- [ ] Withdraw form displays correctly
- [ ] Invalid JSON shows error message
- [ ] Empty JSON shows error message
- [ ] Preview shows correct items to withdraw
- [ ] Preview shows correct item counts (before/after)
- [ ] Confirm withdraws items atomically
- [ ] ItemUser.storageBox set to null correctly
- [ ] Storage box item count updated
- [ ] Success message displayed
- [ ] Redirects to correct page
- [ ] Session cleared after success

### Edge Cases Testing

#### Empty States
- [ ] Empty storage box (0 items)
- [ ] No storage boxes
- [ ] No items in active inventory
- [ ] Deposit all items (active inventory becomes empty)
- [ ] Withdraw all items (storage box becomes empty)

#### AssetId Changes
- [ ] Manually change assetId in JSON
- [ ] Verify item matched by properties (hash_name + float + pattern)
- [ ] Verify assetId updated in database
- [ ] Check logs for assetId change detection

#### Multiple Storage Boxes
- [ ] User has 4+ storage boxes
- [ ] Each box tracked independently
- [ ] No item duplication across boxes
- [ ] Filtering by each box works correctly

#### Item Count Validation
- [ ] Import inventory
- [ ] Manually verify item counts match JSON
- [ ] Check logs for any count mismatches
- [ ] Deposit items - verify count increases
- [ ] Withdraw items - verify count decreases

#### Error Scenarios
- [ ] Invalid JSON format
- [ ] Missing storage box in database
- [ ] Item count mismatch
- [ ] Session expiration during deposit/withdraw
- [ ] Try accessing another user's storage box (security check)
- [ ] Database transaction failure (simulate)
- [ ] Network interruption (simulate)

### Performance Testing

#### Large Inventory
- [ ] Import inventory with 2000+ items
- [ ] Verify import completes within 5 seconds
- [ ] Verify inventory page loads within 2 seconds
- [ ] Verify filtering is responsive
- [ ] Check for N+1 query issues
- [ ] Check database query performance

#### Snapshot Comparison
- [ ] Deposit/withdraw with 1000+ items
- [ ] Verify comparison completes within 3 seconds
- [ ] Check memory usage during comparison

### Regression Testing

#### Existing Functionality
- [ ] Regular inventory import still works
- [ ] Item display unchanged for non-storage items
- [ ] Dashboard stats still accurate
- [ ] Item details pages still work
- [ ] Search/filter functionality still works
- [ ] No new errors in production logs

## Documentation Updates

### Update CLAUDE.md

**File**: `CLAUDE.md`

Add storage box section:

```markdown
## Storage Box Management

The system supports CS2 storage units, allowing users to track items stored in boxes and move items in/out using JSON snapshots.

### Key Features

- **Storage Box Tracking**: Automatically sync storage boxes during inventory import
- **Visual Indicators**: Items in storage display badge showing which box they're in
- **Deposit/Withdraw**: Use JSON snapshot comparison to detect item movements
- **Filtering**: View all items, active inventory only, or items in specific storage box

### Storage Box Workflow

#### Import Inventory with Storage Boxes
```bash
# Storage boxes are automatically detected and synced during import
# Navigate to: /inventory/import
# Paste JSON → Preview → Confirm
```

#### Deposit Items into Storage Box
1. In-game: Deposit items into a storage box
2. Copy updated inventory JSON (after deposit)
3. Navigate to storage box on inventory page
4. Click "Deposit" button
5. Paste JSON → Preview → Confirm

#### Withdraw Items from Storage Box
1. In-game: Withdraw items from a storage box
2. Copy updated inventory JSON (after withdrawal)
3. Navigate to storage box on inventory page
4. Click "Withdraw" button
5. Paste JSON → Preview → Confirm

### Database Structure

**storage_box** table:
- `id`: Primary key
- `user_id`: Foreign key to users
- `asset_id`: Unique Steam asset ID
- `name`: Display name (from name tag)
- `item_count`: Number of items in box (from Steam)
- `modification_date`: Last modified timestamp
- `created_at`, `updated_at`: Timestamps

**item_user** table update:
- `storage_box_id`: Foreign key to storage_box (nullable)
- When NULL: item is in active inventory
- When set: item is in the specified storage box

### AssetId Change Handling

Steam occasionally changes asset IDs. The system handles this by:
1. First trying exact assetId match
2. If no match, trying property match (market_hash_name + float + pattern)
3. Updating assetId in database when match found
4. Logging all assetId changes for audit

### Console Commands

No new console commands added. All operations are performed through the web interface.
```

### Code Comments

Add PHPDoc comments to all public methods if missing:

- [ ] StorageBoxService methods documented
- [ ] StorageBoxTransactionService methods documented
- [ ] StorageBoxController methods documented
- [ ] StorageBoxRepository methods documented

## Performance Benchmarks

Document performance metrics:

```markdown
### Performance Metrics (Tested with 2000 item inventory)

| Operation | Time | Notes |
|-----------|------|-------|
| Import with storage boxes | < 5s | Includes parsing and DB sync |
| Inventory page load | < 2s | All items + filtering |
| Deposit preview | < 3s | Snapshot comparison |
| Withdraw preview | < 3s | Snapshot comparison |
| Deposit confirm | < 1s | Database transaction |
| Withdraw confirm | < 1s | Database transaction |
```

## Final Acceptance Criteria

- [ ] All end-to-end workflows tested successfully
- [ ] All edge cases tested and handled
- [ ] Performance meets benchmarks
- [ ] No regressions in existing functionality
- [ ] CLAUDE.md updated with storage box documentation
- [ ] All public methods have PHPDoc comments
- [ ] No errors in production logs
- [ ] Code review completed
- [ ] All previous task acceptance criteria met

## Known Issues / Future Improvements

Document any known issues or planned improvements:

```markdown
### Known Limitations

1. No real-time sync - users must manually upload JSON after in-game actions
2. Cannot view storage box contents directly from Steam API (Steam limitation)
3. Requires manual workflow (in-game action → copy JSON → upload)

### Future Improvements

- Bulk operations: Deposit/withdraw from multiple boxes at once
- Search within storage: Find specific items across all boxes
- Storage analytics: Show which boxes contain most valuable items
- Auto-organize: Suggest which items to store based on rarity/value
- Storage box renaming: Allow custom box names in app (independent of Steam)
- Quick deposit: One-click deposit for recently acquired items
```

## Deployment Checklist

Before marking as complete:

- [ ] Run `doctrine:schema:validate` - no errors
- [ ] Run database migrations on staging
- [ ] Test full workflow on staging
- [ ] Clear application cache
- [ ] Verify no breaking changes
- [ ] Update version number (if applicable)

## Dependencies

- Task 2-1: Storage Box Database Setup (required)
- Task 2-2: Storage Box Import Integration (required)
- Task 2-3: Storage Box Display (required)
- Task 2-4: Deposit/Withdraw Workflow (required)

## Completion

Once all checklist items are completed:

1. Move all 5 task files to `tasks/completed/`
2. Update main `2-storage-box-management.md` status to "Completed"
3. Move `2-storage-box-management.md` to `tasks/completed/`

## Related Files

- `CLAUDE.md` (modified)
- All files from tasks 2-1 through 2-4
