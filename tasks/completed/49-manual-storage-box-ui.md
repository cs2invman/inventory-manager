# Manual Storage Box UI - Add Creation Button and Visual Indicators

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-10

## Overview

Add user interface for creating manual storage boxes directly from the inventory page. Manual boxes allow users to track items lent to friends by creating "fake" storage boxes that work identically to Steam-imported boxes but aren't linked to Steam inventory. The backend functionality already exists - this task focuses on connecting it to the UI.

## Problem Statement

Users can lend CS2 items to friends, but these items disappear from their tracked inventory since they're no longer in the user's Steam account. Manual storage boxes provide a way to track these items' values and locations even though they physically reside in someone else's inventory.

Currently, the backend supports manual boxes (`StorageBoxService::createManualBox()` exists), but there's no UI to create them. Users need:
- A simple way to create manual boxes from the inventory page
- Visual distinction between manual boxes and Steam-imported boxes
- Access to the same deposit/withdraw functionality that Steam boxes have

## Requirements

### Functional Requirements
- Add "Create Manual Box" button near the storage box count on inventory page
- Button opens a simple form/modal to input box name only
- Form validates name (required, max 255 characters)
- Successful creation redirects back to inventory page with success message
- Manual boxes appear in the storage box list alongside Steam boxes
- Manual boxes show a visual indicator (badge/icon) to distinguish from Steam boxes
- Manual boxes support all existing functionality (view, deposit, withdraw)
- No limit on number of manual boxes a user can create

### Non-Functional Requirements
- UI should be consistent with existing design (Tailwind CSS, CS2 theme)
- Form submission should be quick (<500ms excluding network latency)
- Error messages should be clear and actionable
- Manual box creation should be logged for debugging

## Technical Approach

### No Database Changes Required
All necessary database functionality already exists:
- `StorageBox` entity has nullable `assetId` field (null = manual box)
- `isManualBox()` and `isSteamBox()` helper methods exist
- `StorageBoxService::createManualBox()` method exists
- `StorageBoxRepository::findManualBoxes()` exists

### Controller Changes - StorageBoxController.php

Add two new routes to handle manual box creation:

1. **Create Manual Box Form Route** (`storage_box_create_manual`)
   - Method: GET
   - Path: `/storage/create-manual`
   - Renders simple form with name input field
   - Template: `storage_box/create_manual.html.twig`

2. **Create Manual Box Submit Route** (`storage_box_create_manual_submit`)
   - Method: POST
   - Path: `/storage/create-manual`
   - Validates input (name required, max 255 chars)
   - Calls `StorageBoxService::createManualBox($user, $name)`
   - Flash message: "Manual storage box '[name]' created successfully!"
   - Redirects to `inventory_index` with `filter=all`
   - On error: re-render form with error messages

**Implementation Details:**
```php
#[Route('/create-manual', name: 'storage_box_create_manual', methods: ['GET'])]
public function createManualForm(): Response
{
    return $this->render('storage_box/create_manual.html.twig');
}

#[Route('/create-manual', name: 'storage_box_create_manual_submit', methods: ['POST'])]
public function createManualSubmit(Request $request, StorageBoxService $storageBoxService): Response
{
    $name = trim($request->request->get('name', ''));

    // Validation
    if (empty($name)) {
        $this->addFlash('error', 'Storage box name is required.');
        return $this->render('storage_box/create_manual.html.twig', ['name' => $name]);
    }

    if (strlen($name) > 255) {
        $this->addFlash('error', 'Storage box name must be 255 characters or less.');
        return $this->render('storage_box/create_manual.html.twig', ['name' => $name]);
    }

    try {
        $user = $this->getUser();
        $box = $storageBoxService->createManualBox($user, $name);

        $this->addFlash('success', sprintf("Manual storage box '%s' created successfully!", $name));
        return $this->redirectToRoute('inventory_index', ['filter' => 'all']);
    } catch (\Exception $e) {
        $this->addFlash('error', 'Failed to create storage box: ' . $e->getMessage());
        return $this->render('storage_box/create_manual.html.twig', ['name' => $name]);
    }
}
```

### Frontend Changes

#### 1. Create Form Template - `templates/storage_box/create_manual.html.twig`

New template extending `base.html.twig`:
- Page title: "Create Manual Storage Box"
- Simple form with:
  - Name input field (required, maxlength=255)
  - Help text: "Create a storage box to track items you've lent to friends. This box won't be affected by Steam inventory imports."
  - Submit button: "Create Storage Box"
  - Cancel link back to inventory
- Use existing Tailwind styles consistent with other forms
- Display flash messages for validation errors

**Key styling:**
- Use `.card` class for form container
- Use `.btn-primary` for submit button
- Use text input styling consistent with deposit/withdraw forms
- Center the form on the page (max-width)

#### 2. Update Inventory Template - `templates/inventory/index.html.twig`

**Add "Create Manual Box" button** (around line 70-72, near storage box count):

Current code:
```twig
<div class="ml-auto text-gray-400">
    <span>Storage Boxes: {{ storageBoxes|length }}</span>
</div>
```

New code:
```twig
<div class="ml-auto flex items-center gap-3">
    <span class="text-gray-400">Storage Boxes: {{ storageBoxes|length }}</span>
    <a href="{{ path('storage_box_create_manual') }}"
       class="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700 transition-colors flex items-center gap-1.5"
       title="Create a manual storage box to track items lent to friends">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
        </svg>
        Add Manual Box
    </a>
</div>
```

**Add visual indicator to manual boxes** (around line 103-106, in storage box card):

Find the storage box card heading area and add conditional badge:

```twig
<div class="flex items-center justify-between mb-2">
    <div class="flex items-center gap-2">
        <span class="text-3xl">📦</span>
        {% if box.isManualBox %}
            <span class="bg-purple-600 text-white px-2 py-0.5 rounded text-xs font-semibold" title="Manual box (not synced with Steam)">
                MANUAL
            </span>
        {% endif %}
    </div>
    <div class="flex items-center gap-1">
        {# existing sync status badges... #}
    </div>
</div>
```

**Optional: Update storage box card styling for manual boxes:**

Consider adding a slight visual distinction to manual box cards (different border color):

```twig
<div class="{% if box.isManualBox %}bg-gradient-to-br from-purple-900/50 to-purple-800/30 border-2 border-purple-500/50 hover:border-purple-400{% else %}bg-gradient-to-br from-blue-900/50 to-blue-800/30 border-2 border-blue-500/50 hover:border-blue-400{% endif %} rounded-lg p-4 hover:shadow-lg transition-all duration-200 storage-box-card">
```

#### 3. Update Storage Box Dropdown Filter (line 56-66)

Add visual indicator in the dropdown as well:

```twig
<option value="{{ box.id }}" {{ currentBoxId == box.id ? 'selected' : '' }}>
    {% if box.isManualBox %}🎁{% else %}📦{% endif %} {{ box.name }} ({{ box.actualCount }})
</option>
```

### Configuration
No environment variables or Docker configuration changes needed.

## Implementation Steps

1. **Add Controller Routes** (StorageBoxController.php)
   - Add `createManualForm()` GET route
   - Add `createManualSubmit()` POST route with validation
   - Inject `StorageBoxService` if not already available
   - Add proper security checks (`#[IsGranted('ROLE_USER')]` already on class)

2. **Create Form Template** (storage_box/create_manual.html.twig)
   - Extend base.html.twig
   - Create form with name input
   - Add help text explaining manual boxes
   - Add submit and cancel buttons
   - Display flash messages

3. **Update Inventory Template** (inventory/index.html.twig)
   - Add "Add Manual Box" button near storage box count
   - Add "MANUAL" badge to manual box cards
   - Optional: Update card styling to use purple theme for manual boxes
   - Update dropdown filter to show icon for manual boxes

4. **Test Manually**
   - Click "Add Manual Box" button → form loads
   - Submit empty name → validation error shown
   - Submit valid name → box created, redirected to inventory
   - Verify manual box appears with "MANUAL" badge
   - Verify manual box appears in dropdown filter
   - Test deposit into manual box → should work normally
   - Test withdraw from manual box → should work normally
   - Test Steam inventory import → manual box should not be affected

5. **Rebuild Frontend Assets**
   ```bash
   docker compose run --rm node npm run build
   ```

## Edge Cases & Error Handling

### Edge Cases
1. **Name already exists**: Allow duplicate names (users might want multiple boxes for different friends)
2. **Very long names**: Enforce 255 character limit (matches database schema)
3. **Empty name**: Show validation error, require non-empty string
4. **Special characters in name**: Allow all characters (users might want emojis, etc.)
5. **Manual box with items during import**: Import process already skips manual boxes - verify this works
6. **Multiple users creating boxes simultaneously**: Each box is user-scoped, no conflicts

### Error Handling
1. **Service exception during creation**: Catch exception, show flash error, re-render form
2. **Database constraint violation**: Show generic error message, log details
3. **Session expired**: Symfony security handles this automatically
4. **Invalid POST data**: Validation catches this before service call

### Logging
- Log manual box creation via `StorageBoxService::createManualBox()` (already implemented)
- No additional logging needed in controller

## Dependencies

### Blocking Dependencies
None - all backend functionality already exists.

### Related Tasks
- Task 46: Ledger system (independent feature)
- Task 47: Ledger filtering (independent feature)
- Task 48: Unknown (independent)

### Can Be Done in Parallel With
Any other UI or feature tasks - this is self-contained.

### External Dependencies
None - uses existing backend services and database schema.

## Acceptance Criteria

- [ ] "Add Manual Box" button appears on inventory page near storage box count
- [ ] Clicking button navigates to manual box creation form
- [ ] Form validates name is required and ≤255 characters
- [ ] Submitting valid name creates manual box and redirects to inventory
- [ ] Created manual box appears in storage box list with "MANUAL" badge
- [ ] Manual boxes visually distinguished from Steam boxes (purple badge/theme)
- [ ] Manual boxes appear in storage box dropdown filter with appropriate icon
- [ ] Deposit functionality works for manual boxes
- [ ] Withdraw functionality works for manual boxes
- [ ] Manual boxes are not affected by Steam inventory imports
- [ ] Error messages are clear and actionable
- [ ] Success message confirms box creation with box name
- [ ] Frontend assets rebuilt after Twig template changes

## Manual Verification Steps

1. **Create Manual Box Flow:**
   - Log into application
   - Navigate to inventory page
   - Verify "Add Manual Box" button appears near storage count
   - Click button → verify form loads with name input
   - Submit empty form → verify validation error
   - Enter name with 300+ characters → verify validation error
   - Enter valid name "Friend - John" → verify success message
   - Verify redirected to inventory page with filter=all

2. **Visual Verification:**
   - Locate newly created manual box in storage box grid
   - Verify "MANUAL" badge appears on the box
   - Verify box has purple-themed styling (if implemented)
   - Verify box shows "0 items" initially
   - Open storage box dropdown filter
   - Verify manual box appears with gift icon (🎁)

3. **Functionality Verification:**
   - Click "Deposit" on manual box → verify form loads
   - Paste Steam JSON and complete deposit → verify items deposited
   - Return to inventory → verify manual box shows correct item count
   - Click "View Contents" → verify items display correctly
   - Click "Withdraw" → verify withdrawal process works
   - Verify items moved back to main inventory

4. **Import Safety Verification:**
   - With items in manual box, perform Steam inventory import
   - After import completes, verify manual box still exists
   - Verify items in manual box unchanged
   - Verify manual box item count unchanged

5. **Multiple Manual Boxes:**
   - Create second manual box with same name → verify allowed
   - Create third manual box with different name → verify allowed
   - Verify all manual boxes appear in list
   - Verify dropdown shows all boxes

## Notes & Considerations

### Design Decisions
- **No duplicate name validation**: Users might legitimately want multiple boxes for the same friend (e.g., "John - Rifles", "John - Knives")
- **Purple color scheme**: Chosen to distinguish from blue (Steam boxes) while maintaining visual harmony
- **"MANUAL" badge**: Clear, simple indicator that doesn't clutter the UI
- **Button placement**: Near storage count is intuitive and doesn't interfere with existing filters

### Future Improvements (Not in Scope)
- Add optional "friend name" or "notes" field to manual boxes
- Add "rename" functionality for manual boxes (backend method exists: `StorageBoxService::renameManualBox()`)
- Add "delete" functionality for manual boxes (backend method exists: `StorageBoxService::deleteManualBox()`)
- Add bulk operations (create multiple boxes at once)
- Add modal-based creation instead of separate page
- Add box templates (pre-defined box types)

### Performance Considerations
- Manual box creation is a simple INSERT operation - no performance concerns
- Rendering manual box badge is template-level check - negligible overhead
- No additional database queries needed (existing queries already fetch all boxes)

### Security Considerations
- Controller routes protected by `#[IsGranted('ROLE_USER')]` at class level
- User ownership verified implicitly (boxes created with `$user` from session)
- Existing deposit/withdraw routes already verify box ownership
- Name input sanitized by Twig escaping in templates
- No CSRF concerns (Symfony auto-generates CSRF tokens for forms)

### Browser Compatibility
- SVG icons used are widely supported
- Tailwind CSS classes are standard and well-supported
- No JavaScript required (pure server-side form submission)

## Related Documentation

- **CLAUDE.md**: Architecture section documents StorageBox entity and manual box behavior
- **Storage Box Service**: `/src/Service/StorageBoxService.php` lines 189-209 (createManualBox method)
- **Storage Box Entity**: `/src/Entity/StorageBox.php` lines 149-162 (isManualBox/isSteamBox methods)
- **Existing Deposit/Withdraw**: `/src/Controller/StorageBoxController.php` (reference for form patterns)
