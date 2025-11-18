# Delete Empty Manual Storage Box

**Status**: Completed
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-18
**Completed**: 2025-11-18

## Overview

Add the ability for users to delete empty manual storage boxes from the inventory view when viewing the contents of an empty box. This helps users clean up accidentally created manual boxes without having to keep them forever.

## Problem Statement

Users can create manual storage boxes (for tracking items lent to friends), but if they accidentally create an extra box or no longer need one, there's currently no way to delete it. This leads to clutter in the storage box list. Users should be able to delete empty manual boxes to keep their storage organized.

## Requirements

### Functional Requirements
- Add a delete button on the empty state page when viewing contents of an empty manual storage box
- Only manual boxes (boxes with `assetId = null`) can be deleted
- Only empty boxes (boxes with 0 items) can be deleted
- Use two-step confirmation modal (existing reusable component)
- Steam-imported boxes cannot be deleted (they're synced from Steam)
- If user attempts to delete a box with items (e.g., via direct URL access), show error and prevent deletion

### Non-Functional Requirements
- Security: Verify box ownership before deletion
- Security: Verify box is manual and empty before deletion
- UX: Clear confirmation modal explaining the action is permanent
- Logging: Log box deletions for audit trail

## Technical Approach

### Service Layer
- **Use existing method**: `StorageBoxService::deleteManualBox()` already exists (line 278-298)
- This method already:
  - Validates box is manual (throws exception if Steam box)
  - Moves any items to main inventory (sets storageBox to null)
  - Removes the box entity
  - Logs the deletion

### Controller Changes
- **Add new route** in `StorageBoxController`:
  - Route: `/storage/delete/{id}`
  - Name: `storage_box_delete`
  - Method: POST only (not GET, for safety)
  - Security checks:
    - Verify user owns the box (`$storageBox->getUser() === $this->getUser()`)
    - Verify box is manual (`$storageBox->isManualBox()`)
    - Verify box is empty (count items = 0)
  - On success: Flash success message, redirect to `inventory_index` with filter=all
  - On error: Flash error message, redirect back to box view

### Frontend Changes
- **Modify**: `templates/inventory/index.html.twig`
- **Location**: Inside the empty state section (around line 203-217)
- **Logic**: Only show delete button when:
  - `currentFilter == 'box'` (viewing a specific box)
  - `currentBoxId > 0` (valid box selected)
  - Box is manual (`selectedBox.isManualBox == true`)
  - Box is empty (`itemsWithPrices is empty`)
- **Component**: Use existing `confirmation_modal.html.twig` component with:
  - `modalId`: `deleteBoxModal`
  - `title`: "Delete Manual Storage Box?"
  - `message`: "This will permanently delete the box '[Box Name]'. This action cannot be undone."
  - `confirmText`: "DELETE"
  - `actionUrl`: `path('storage_box_delete', {id: selectedBox.id})`
  - Red danger button styling

### Configuration
- No environment variables needed
- No Docker configuration changes

## Implementation Steps

1. **Add delete route to StorageBoxController**
   - Add route attribute: `#[Route('/delete/{id}', name: 'storage_box_delete', methods: ['POST'])]`
   - Add `deleteManualBox()` controller method
   - Implement security checks (ownership, manual box, empty box)
   - Call existing `StorageBoxService::deleteManualBox()` method
   - Add appropriate flash messages
   - Add error handling for exceptions

2. **Update inventory index template**
   - Locate the empty state section (line 203-217)
   - Add logic to determine if current view is an empty manual box
   - Wrap delete button in conditional check for manual + empty box
   - Embed `confirmation_modal.html.twig` component with appropriate parameters
   - Style delete button with danger colors (red)
   - Position button below "Import Inventory" button

3. **Test the implementation**
   - Create a manual storage box
   - View its contents (should be empty)
   - Verify delete button appears
   - Click delete, verify two-step confirmation works
   - Confirm deletion, verify box is removed
   - Verify box no longer appears in storage box list
   - Test error cases:
     - Try deleting Steam box (should show error)
     - Try deleting box with items (should show error)
     - Try deleting another user's box (should get access denied)

4. **Rebuild frontend assets**
   - Run: `docker compose run --rm node npm run build`
   - This ensures Tailwind scans for any new CSS classes

## Edge Cases & Error Handling

### Edge Case 1: User adds items after opening delete modal
- **Scenario**: User opens inventory in another tab, deposits items into the box, then confirms delete in first tab
- **Handling**: The service method `deleteManualBox()` will move items back to main inventory before deleting. This is safe behavior.
- **Better solution**: Add item count check in controller before calling service

### Edge Case 2: Box doesn't exist or was already deleted
- **Scenario**: User has stale page, box was deleted elsewhere
- **Handling**: Symfony's `#[MapEntity]` will throw 404 automatically if box doesn't exist
- **Action**: This is already handled, no extra code needed

### Edge Case 3: User tries to delete via direct URL
- **Scenario**: User crafts URL to delete box with items or Steam box
- **Handling**:
  - Controller checks `isManualBox()` - show error flash and redirect
  - Controller checks item count - show error flash and redirect
  - Service method also validates manual box and throws exception
- **Action**: Implement both controller validation and catch service exceptions

### Edge Case 4: User doesn't own the box
- **Scenario**: User tries to delete another user's box
- **Handling**: Controller ownership check throws `AccessDeniedException`
- **Action**: Standard Symfony security handling

## Dependencies

### Blocking Dependencies
- None (all required infrastructure already exists)

### Related Tasks
- None

### External Dependencies
- Existing `StorageBoxService::deleteManualBox()` method (already implemented in src/Service/StorageBoxService.php:278-298)
- Existing `confirmation_modal.html.twig` component (templates/components/confirmation_modal.html.twig)
- Alpine.js (already included in base template for modal functionality)

## Acceptance Criteria

- [ ] Delete button appears on empty state page when viewing empty manual storage box
- [ ] Delete button does NOT appear when viewing active inventory or "all items"
- [ ] Delete button does NOT appear when viewing empty Steam-imported box
- [ ] Delete button does NOT appear when viewing non-empty manual box
- [ ] Two-step confirmation modal works correctly (step 1: Continue, step 2: type "DELETE")
- [ ] Successful deletion shows success flash message
- [ ] After deletion, user is redirected to inventory index with "all items" filter
- [ ] Deleted box no longer appears in storage box list
- [ ] Attempting to delete Steam box shows error message
- [ ] Attempting to delete box with items shows error message
- [ ] Attempting to delete another user's box results in access denied
- [ ] Deletion is logged by existing service method
- [ ] Frontend assets rebuilt successfully

## Notes & Considerations

- **Existing service method**: The `StorageBoxService::deleteManualBox()` method already exists and handles all the business logic. We just need to wire up the controller route and UI.
- **Safety**: Using POST-only route prevents accidental deletion via GET request
- **UX**: Two-step confirmation prevents accidental clicks from causing deletion
- **Steam boxes**: Steam-imported boxes are intentionally excluded because they sync from Steam and will reappear on next import
- **Manual verification steps**:
  1. Create manual box via "Add Manual Box" button
  2. Verify box appears in storage box list
  3. Click "View Contents" - should show empty state with delete button
  4. Test delete flow with confirmation modal
  5. Verify box is removed from list after deletion
  6. Create another manual box and deposit items into it
  7. Click "View Contents" - should NOT show delete button (box not empty)
  8. View contents of Steam box (if any) - should NOT show delete button

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
