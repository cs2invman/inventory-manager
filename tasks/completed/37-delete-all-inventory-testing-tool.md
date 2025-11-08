# Delete All Inventory Data - Testing Tool

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-08

## Overview

Add a "Delete All Inventory Data" button to the `/inventory/import` page that allows users to completely wipe their inventory items and storage boxes. This feature will be helpful for testing the import functionality without having to manually delete items through the database. The feature requires double confirmation (modal + text input "Delete") to prevent accidental data loss.

## Problem Statement

During testing and development of the inventory import functionality, developers and testers need a quick way to reset all inventory data for a user without manually deleting records from the database. Currently, there is no user-facing way to completely clear a user's inventory and storage boxes, making iterative testing of the import workflow cumbersome.

## Requirements

### Functional Requirements
- Add a "Delete All Inventory Data" button at the top of the `/inventory/import` page
- Button should be visually distinct and clearly labeled as a destructive action (red styling)
- Implement a two-step confirmation process:
  1. First confirmation: Modal asking "Are you sure?"
  2. Second confirmation: Text input requiring user to type "Delete" exactly
- Only proceed with deletion if user types "Delete" (case-sensitive)
- Delete all ItemUser records for the current user
- Delete all StorageBox records for the current user
- Show flash message with counts: "Deleted X items and Y storage boxes"
- Redirect to `/inventory` page after successful deletion
- Feature should be available to all authenticated users (not just development mode)
- Display in a separate "Testing Tools" or "Danger Zone" section at the top of the page

### Non-Functional Requirements
- Deletion should be a database transaction (all-or-nothing)
- Should log the deletion event with timestamp and user info
- Button should be clearly separated from the main import workflow to avoid accidental clicks
- UI should use Tailwind CSS consistent with existing styling
- Modal should use Alpine.js for interactivity (already used in the project)

## Technical Approach

### Controller Changes
**File**: `src/Controller/InventoryImportController.php`

Add a new route action to handle the deletion:
- Route: `/inventory/import/delete-all`
- Method: POST
- Name: `inventory_import_delete_all`
- Requires CSRF token validation (Symfony's built-in CSRF protection)

### Repository Methods
Use existing repositories to perform deletion:
- `ItemUserRepository::findBy(['user' => $user])` to get all user items
- `StorageBoxRepository::findBy(['user' => $user])` to get all user boxes
- Use EntityManager to remove entities in a transaction

### Frontend Changes
**File**: `templates/inventory/import.html.twig`

Add at the top of the page (after flash messages, before page header):
1. A "Testing Tools" section with warning styling
2. "Delete All Inventory Data" button that triggers a modal
3. Alpine.js modal component with:
   - First screen: Confirmation message with "Cancel" and "Continue" buttons
   - Second screen: Text input for typing "Delete" with "Cancel" and "Confirm" buttons
   - Form submission only when text matches "Delete" exactly

### Logging
Use Symfony's logger to record deletion events:
- Log level: WARNING (since it's a destructive action)
- Include: User ID, username, timestamp, item count, storage box count

## Implementation Steps

1. **Add Controller Action**
   - Open `src/Controller/InventoryImportController.php`
   - Add new `deleteAll()` action with `#[Route('/delete-all', name: 'inventory_import_delete_all', methods: ['POST'])]`
   - Inject `LoggerInterface` into constructor
   - Inject `ItemUserRepository` and `StorageBoxRepository` into constructor (may already be injected)
   - Implement deletion logic:
     ```php
     a. Get current user
     b. Count items and boxes before deletion
     c. Start transaction (EntityManager::beginTransaction())
     d. Find all ItemUser records for user
     e. Find all StorageBox records for user
     f. Remove all entities
     g. Flush changes
     h. Commit transaction
     i. Log the deletion event
     j. Add flash message with counts
     k. Redirect to inventory_index
     ```
   - Add try-catch for error handling with rollback

2. **Create Frontend UI Components**
   - Open `templates/inventory/import.html.twig`
   - Add "Testing Tools" section after line 15 (after flash messages)
   - Use Alpine.js `x-data` for modal state management
   - Create modal structure with two states:
     - State 1: Initial confirmation
     - State 2: Text input confirmation
   - Add form that posts to `inventory_import_delete_all` route
   - Include CSRF token in form
   - Style with Tailwind CSS:
     - Red/danger theme for button and modal
     - Warning colors (yellow/orange) for the section container
     - Use existing card styles for consistency

3. **Add Alpine.js Modal Logic**
   - Create Alpine component with data properties:
     - `showModal: false`
     - `confirmationStep: 1` (step 1 or 2)
     - `deleteText: ''`
     - `isDeleteTextCorrect()` computed property
   - Implement modal show/hide transitions
   - Implement step navigation
   - Add form submission only when deleteText === 'Delete'

4. **Test the Feature**
   - Manual verification steps (no automated tests in this project)
   - Test with a user that has inventory items and storage boxes
   - Test with a user that has no data (should still work, counts should be 0)
   - Test canceling at step 1
   - Test canceling at step 2
   - Test typing incorrect text (should not submit)
   - Test typing correct text ("Delete") - should delete all data
   - Verify flash message shows correct counts
   - Verify redirect to inventory page works
   - Verify database records are actually deleted
   - Verify logs contain deletion event

## Edge Cases & Error Handling

### Edge Cases
1. **User has no inventory items or storage boxes**
   - Should still work, flash message should show "Deleted 0 items and 0 storage boxes"
   - No errors should occur

2. **User has items in storage boxes**
   - Should delete both the items AND the storage boxes
   - No orphaned records should remain

3. **Concurrent deletion attempts**
   - Transaction isolation should prevent race conditions
   - If somehow triggered twice, second attempt should just show 0 deletions

4. **CSRF token validation**
   - Invalid/missing CSRF token should be rejected by Symfony
   - User should see appropriate error message

### Error Handling
1. **Database transaction failure**
   - Wrap deletion in try-catch
   - Rollback transaction on any error
   - Show error flash message: "Failed to delete inventory data. Please try again."
   - Log the error with exception details

2. **Text input validation**
   - Frontend: Disable submit button unless deleteText === 'Delete'
   - Backend: Not strictly necessary since deletion is idempotent, but could add verification
   - Case-sensitive check to prevent accidental deletions

3. **Missing repositories/services**
   - Should be caught during compilation/container build
   - If runtime error occurs, catch and show generic error message

## Dependencies

### Blocking Dependencies
- None (this is a standalone feature)

### External Dependencies
- Symfony framework (already installed)
- Doctrine ORM (already installed)
- Alpine.js (already in use in templates)
- Tailwind CSS (already in use)

### Related Files
- Entities: `App\Entity\ItemUser`, `App\Entity\StorageBox`
- Repositories: `App\Repository\ItemUserRepository`, `App\Repository\StorageBoxRepository`
- Services: None required (could optionally create a service, but logic is simple enough for controller)

## Acceptance Criteria

- [ ] Button appears at the top of `/inventory/import` page in a clearly marked "Testing Tools" or "Danger Zone" section
- [ ] Button is styled with red/danger colors to indicate destructive action
- [ ] Clicking button opens a modal with first confirmation step
- [ ] First confirmation has "Cancel" and "Continue" buttons
- [ ] Clicking "Continue" shows second confirmation step
- [ ] Second confirmation requires typing "Delete" (exact, case-sensitive)
- [ ] Submit button is disabled unless text matches "Delete" exactly
- [ ] Clicking "Confirm" with correct text deletes all ItemUser records for user
- [ ] Clicking "Confirm" with correct text deletes all StorageBox records for user
- [ ] Flash message shows: "Deleted X items and Y storage boxes"
- [ ] After deletion, user is redirected to `/inventory` page
- [ ] Deletion event is logged with user info, timestamp, and counts
- [ ] Modal can be canceled at any step without performing deletion
- [ ] Works correctly when user has no data (shows 0 counts)
- [ ] Deletion is atomic (uses database transaction)
- [ ] Error handling works correctly (shows error message, doesn't delete partial data)

## Notes & Considerations

### Why Available in Production?
While this feature is primarily for testing, it's available to all users (not just dev mode) because:
- Users might want to reset their inventory to re-import fresh data
- Simple role check (ROLE_USER) is sufficient - users can only delete their own data
- Double confirmation prevents accidental deletion
- Could be useful for users who want to stop using the app and clear their data

### Alternative Approaches Considered
1. **Create a separate admin/testing page**: Decided against this to keep the feature close to where it's needed (import page)
2. **Single confirmation instead of double**: Too risky for destructive action
3. **Development mode only**: Could be useful for regular users who want to reset
4. **Add a service layer**: Not necessary for this simple operation, controller is sufficient

### Future Improvements
- Add ability to delete only items OR only storage boxes (separate buttons)
- Add "undo" functionality (soft delete with grace period)
- Add confirmation email before deletion
- Track deletion in user activity log/audit trail
- Add ability to export data before deletion

### Security Considerations
- CSRF protection is enabled by default in Symfony forms
- Users can only delete their own data (enforced by filtering by current user)
- Double confirmation prevents accidental clicks
- Deletion is logged for audit purposes
- No SQL injection risk (using Doctrine ORM with parameterized queries)

### Performance Considerations
- For users with large inventories (1000+ items), deletion might take a few seconds
- Transaction ensures data consistency
- Could add batch deletion if performance becomes an issue
- Consider adding a progress indicator for large deletions (future enhancement)

### UI/UX Considerations
- Red/danger styling makes it clear this is destructive
- Placed at top of page for visibility but separated from main workflow
- Two-step confirmation reduces accidental deletions while not being too annoying
- Flash message provides feedback about what was deleted
- Redirect to inventory page lets user immediately see the empty state

### Testing Strategy
Since this project doesn't use automated tests, manual verification should cover:
1. Happy path: User with data successfully deletes everything
2. Empty state: User with no data sees 0 counts
3. Partial data: User with only items (no boxes) or only boxes (no items)
4. Cancel flows: Canceling at step 1 and step 2
5. Invalid text: Typing wrong text doesn't allow submission
6. Error scenarios: Database errors are handled gracefully
7. Logging: Verify log entries are created
8. CSRF: Verify form submission requires valid token

## Related Tasks

None - this is a standalone feature for testing purposes.
