# Update reportedCount During Deposit/Withdraw Operations

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-07

## Overview

During deposit and withdraw operations, the `reportedCount` field on StorageBox entities is not being updated, causing a mismatch between what Steam reports and what the UI displays. This task updates the logic to extract and update `reportedCount` (and `modificationDate`) from the JSON data provided during these operations.

## Problem Statement

Currently, when users perform deposit or withdraw operations:
1. They provide Steam inventory JSON (tradeable and/or trade-locked)
2. Items are moved between main inventory and storage boxes
3. The JSON contains the current item count for each storage box
4. However, the `reportedCount` field is NOT updated from this JSON data
5. This causes the UI to show mismatches: "actual count vs reported count" warnings

The JSON data provided during deposit/withdraw contains accurate information about storage box counts directly from Steam, so we should use it to keep `reportedCount` in sync.

## Requirements

### Functional Requirements
- Extract storage box data from JSON during deposit/withdraw preview operations
- Update `reportedCount` field from the JSON's item count value
- Update `modificationDate` field from the JSON's modification date value
- Only update Steam boxes (those with `assetId` set) - never touch manual boxes
- Update occurs during the `executeDeposit` and `executeWithdraw` methods (after items are moved)
- Do NOT update `itemCount` field (that remains unchanged, only `reportedCount` updates)

### Non-Functional Requirements
- Must work with both tradeable and trade-locked JSON inputs
- Must handle cases where storage box appears in only one JSON file
- Must not break existing deposit/withdraw functionality
- Logging should capture when `reportedCount` is updated

## Technical Approach

### Service Layer Changes

**File**: `src/Service/StorageBoxTransactionService.php`

**Changes to existing methods**:

1. **`prepareDepositPreview` and `prepareWithdrawPreview`**:
   - Extract storage box data using `StorageBoxService::extractStorageBoxesFromJson()`
   - Store extracted storage box data in the session along with the transaction data
   - This allows us to use the data later during execution

2. **`executeDeposit` and `executeWithdraw`**:
   - Retrieve storage box data from session
   - After moving items (inside the transaction), update the storage box's `reportedCount` and `modificationDate`
   - Only update if the box is a Steam box (has assetId)
   - Log the update for debugging purposes

**Dependencies**:
- `StorageBoxService` already has `extractStorageBoxesFromJson()` method - reuse it
- No new database fields needed
- No new services needed

### Integration Points

- Reuses existing `StorageBoxService::extractStorageBoxesFromJson()` method
- Fits within existing transaction boundaries
- Uses existing session storage mechanism

## Implementation Steps

1. **Update session data structure**:
   - Modify `prepareDepositPreview` to extract storage box data from both JSON inputs
   - Merge storage box data from tradeable and trade-locked JSONs
   - Store storage box data in session under key `storage_boxes_data`
   - Do the same for `prepareWithdrawPreview`

2. **Update executeDeposit method**:
   - After moving items (before commit), retrieve storage boxes data from session
   - For the current storage box being deposited into:
     - Find matching data by assetId in the extracted storage boxes data
     - If found and box is a Steam box (has assetId):
       - Update `reportedCount` from `item_count` in JSON data
       - Update `modificationDate` from `modification_date` in JSON data (if present)
       - Persist the storage box entity
       - Log the update
   - Commit transaction as usual

3. **Update executeWithdraw method**:
   - Apply the same logic as executeDeposit (see step 2)
   - The only difference is that items are being withdrawn instead of deposited
   - The storage box count update logic is identical

4. **Add helper method** (optional but recommended):
   - Create private method `updateStorageBoxFromJson(StorageBox $box, array $storageBoxesData): void`
   - This method handles finding matching data and updating reportedCount/modificationDate
   - Reduces code duplication between deposit and withdraw

## Edge Cases & Error Handling

### Edge Cases

1. **Storage box not found in JSON data**:
   - Log a warning but don't fail the transaction
   - This could happen if the JSON is incomplete or malformed
   - The deposit/withdraw should still succeed (items were moved correctly)

2. **Manual box (no assetId)**:
   - Do NOT update `reportedCount` or `modificationDate`
   - Manual boxes should never be updated from JSON data
   - Only Steam boxes (with assetId) should be updated

3. **Multiple storage boxes in JSON**:
   - Extract all storage boxes from JSON
   - Only update the specific box involved in the deposit/withdraw operation
   - Match by assetId

4. **Modification date parsing failure**:
   - Log the error but continue with the update
   - Update `reportedCount` even if `modificationDate` parsing fails
   - Don't let date parsing issues block the count update

5. **Empty JSON or no storage boxes found**:
   - Don't fail the transaction
   - Just skip the reportedCount update
   - Log a debug message for troubleshooting

### Error Handling

- Use try-catch around JSON parsing (already exists)
- Log warnings for any parsing failures
- Never fail the entire deposit/withdraw transaction due to count update issues
- The primary operation (moving items) takes priority over metadata updates

## Dependencies

### Blocking Dependencies
None - this task is self-contained

### Related Tasks (same feature)
None - this is a standalone bug fix

### Can Be Done in Parallel With
- Any other task not modifying `StorageBoxTransactionService`

### External Dependencies
- Uses existing `StorageBoxService::extractStorageBoxesFromJson()` method
- No external APIs or services required

## Acceptance Criteria

- [ ] `prepareDepositPreview` extracts storage box data from both JSON files
- [ ] `prepareWithdrawPreview` extracts storage box data from both JSON files
- [ ] Storage box data is stored in session with transaction data
- [ ] `executeDeposit` updates `reportedCount` from JSON data after moving items
- [ ] `executeWithdraw` updates `reportedCount` from JSON data after moving items
- [ ] `modificationDate` is updated if present in JSON data
- [ ] Only Steam boxes (with assetId) are updated, manual boxes are skipped
- [ ] `itemCount` field is NOT modified (only `reportedCount` changes)
- [ ] Updates occur within the existing database transaction
- [ ] Logging captures when `reportedCount` is updated
- [ ] Edge cases handled gracefully (missing data, parse errors, etc.)
- [ ] Manual verification: Perform deposit/withdraw and check that UI no longer shows count mismatches

## Notes & Considerations

### Why Only Update reportedCount?

- `itemCount` is meant to track the expected count at time of last import/sync
- `reportedCount` is the "source of truth" from Steam's latest data
- During deposit/withdraw, users are providing fresh data from Steam
- We use this to keep `reportedCount` accurate without disturbing `itemCount`
- This preserves the ability to detect sync issues over time

### Why Update During Execute, Not Preview?

- Preview phase should be read-only (no database writes)
- Execute phase already has a transaction boundary
- Updating during execute ensures consistency (items moved + counts updated atomically)
- If execute fails, both item moves and count updates roll back together

### Future Improvements

- Consider adding a "Sync Storage Boxes" button that just updates counts without moving items
- Could add a timestamp field to track when `reportedCount` was last updated
- Could show in UI: "Last synced: X minutes ago"

### Performance Considerations

- Extracting storage box data from JSON is lightweight (already parsed for items)
- Database update is a single UPDATE query per storage box
- Happens within existing transaction, no additional round trips

### Security Considerations

- No new security concerns introduced
- Session data already validated during retrieve
- Storage box ownership verified before any updates
- Transaction ensures atomicity

## Related Tasks

None - this is a standalone bug fix for the deposit/withdraw workflow.
