# Admin Settings - Unified Panel

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Large
**Created**: 2025-11-08

## Overview

Create a unified admin settings panel that consolidates CSFloat and Discord configuration in one interface. Provides tabs/sections for managing API keys, webhook URLs, user account links, and sync status for both integrations.

## Problem Statement

The application has multiple admin-configurable integrations (CSFloat, Discord) that need user-friendly web interfaces. Rather than creating separate admin pages for each integration, a unified panel provides:
- Single entry point for all admin settings
- Consistent UI/UX across integrations
- Easier navigation for administrators
- Future-proof architecture for adding new integrations

## Requirements

### Functional Requirements

**CSFloat Section:**
- Configure CSFloat API key (text input, masked)
- Test API key connectivity (button + AJAX)
- View sync status (last run, items mapped, errors)
- Manual sync trigger (button to run `app:csfloat:sync-items`)
- Display unmapped items count
- Show recent CSFloat API errors (last 10)

**Discord Section:**
- Configure webhook URLs (system events, future: price alerts)
- Enable/disable notification types (checkboxes)
- Test webhook connectivity (button + AJAX)
- View linked Discord users (table: username, linked user, verified status)
- Verify/unverify Discord accounts (buttons)
- Delete Discord account links (button)
- Display recent Discord notifications (last 20)

**General:**
- Admin-only access (ROLE_ADMIN required)
- Tabbed interface (CSFloat tab, Discord tab)
- Responsive design (Tailwind CSS)
- Flash messages for all actions (success/error)
- CSRF protection on all forms

### Non-Functional Requirements
- Secure: only admins can access
- Hide sensitive data (API keys partially masked, webhook tokens hidden)
- Fast UI: AJAX for test buttons (no page reload)
- Accessible: keyboard navigation, screen reader friendly
- Mobile responsive (Tailwind CSS)
- Form validation with error messages

## Technical Approach

### Controller Structure

**AdminSettingsController** (unified controller)
Location: `src/Controller/AdminSettingsController.php`

**Route Prefix:** `/admin/settings`

**Routes:**

**Main Page:**
- `GET /admin/settings` - Main settings page with tabs

**CSFloat Routes:**
- `POST /admin/settings/csfloat/api-key` - Save CSFloat API key
- `POST /admin/settings/csfloat/test` - Test CSFloat API connectivity
- `POST /admin/settings/csfloat/sync-now` - Trigger manual sync
- `GET /admin/settings/csfloat/status` - Get sync status (AJAX)

**Discord Routes:**
- `POST /admin/settings/discord/webhook` - Save Discord webhook config
- `POST /admin/settings/discord/test-webhook` - Test webhook
- `POST /admin/settings/discord/toggle-notification/{type}` - Enable/disable notification
- `GET /admin/settings/discord/users` - Get linked users (AJAX, for table refresh)
- `POST /admin/settings/discord/users/{id}/verify` - Verify user
- `POST /admin/settings/discord/users/{id}/unverify` - Unverify user
- `DELETE /admin/settings/discord/users/{id}` - Delete user link

**Security:**
- All routes require `#[IsGranted('ROLE_ADMIN')]`
- CSRF protection on all forms

**Dependencies:**
- `AdminConfigRepository` (get/set config values)
- `CsfloatSearchService` (test API key)
- `ItemRepository` (get unmapped items count)
- `ItemCsfloatRepository` (get sync stats)
- `DiscordWebhookService` (from Task 39, test webhooks)
- `DiscordUserRepository` (from Task 38, manage user links)
- `DiscordNotificationRepository` (from Task 38, get recent notifications)
- `ProcessBuilder` (run sync command in background)
- `FormFactoryInterface` (build forms)
- `EntityManagerInterface` (persist changes)

### Database Schema (Admin Config)

**admin_config table** (create if doesn't exist from Task 38/42):

| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment |
| config_key | VARCHAR(100) UNIQUE | Config identifier |
| config_value | TEXT | Config value (JSON or string) |
| is_enabled | BOOLEAN | Enable/disable flag |
| created_at | DATETIME | When created |
| updated_at | DATETIME | Last updated |

**Config Keys:**
- `csfloat_api_key`: CSFloat API key
- `discord_webhook_system_events`: Discord webhook URL
- `discord_notify_system_events`: Enable/disable (boolean)

### Forms

**CsfloatApiKeyForm**
Location: `src/Form/CsfloatApiKeyFormType.php`

Fields:
- `apiKey` (TextType) - CSFloat API key
- `submit` (SubmitType) - "Save API Key"

Validation:
- NotBlank: API key required
- Length: min=20, max=255 (CSFloat keys are ~32 chars)

**DiscordWebhookConfigForm** (from Task 42)
Location: `src/Form/DiscordWebhookConfigFormType.php`

Fields:
- `webhookSystemEvents` (TextType) - Webhook URL
- `notifySystemEvents` (CheckboxType) - Enable/disable

Validation:
- URL validator
- Custom validator: Discord webhook pattern (`https://discord.com/api/webhooks/{id}/{token}`)

### Templates

**Main Template:** `templates/admin_settings/index.html.twig`
Location: `templates/admin_settings/index.html.twig`

**Layout:**
```
┌─────────────────────────────────────────┐
│ Admin Settings                          │
├─────────────────────────────────────────┤
│ [ CSFloat ] [ Discord ] ← Tabs          │
├─────────────────────────────────────────┤
│                                         │
│ Tab Content (CSFloat or Discord)        │
│                                         │
└─────────────────────────────────────────┘
```

**CSFloat Tab Content:**
1. **API Configuration**
   - API Key input (masked: `••••••••••••abc123`)
   - Test API button (AJAX, shows result inline)
   - Save button

2. **Sync Status**
   - Last sync time (e.g., "2 hours ago")
   - Items mapped: 1,234 / 1,500
   - Unmapped items: 266
   - Manual sync button ("Sync Now")
   - Progress bar (if sync running)

3. **Recent Errors** (collapsible)
   - Table: Timestamp, Item, Error Message
   - Last 10 errors
   - "Clear Errors" button

**Discord Tab Content** (from Task 42):
1. **Webhook Configuration**
   - Webhook URL input
   - Enable/disable checkbox
   - Test webhook button
   - Save button

2. **Linked Discord Users**
   - Table: Discord Username, Linked User, Verified, Actions
   - Actions: Verify/Unverify, Delete

3. **Recent Notifications**
   - Table: Type, Status, Sent At, Message
   - Last 20 notifications
   - Color-coded by status

### Tab Implementation

**Using Tailwind CSS + Alpine.js:**

```html
<div x-data="{ tab: 'csfloat' }">
  <!-- Tab Buttons -->
  <div class="flex border-b border-gray-200">
    <button
      @click="tab = 'csfloat'"
      :class="{ 'border-blue-500 text-blue-600': tab === 'csfloat' }"
      class="px-4 py-2 border-b-2 border-transparent hover:border-gray-300"
    >
      CSFloat
    </button>
    <button
      @click="tab = 'discord'"
      :class="{ 'border-blue-500 text-blue-600': tab === 'discord' }"
      class="px-4 py-2 border-b-2 border-transparent hover:border-gray-300"
    >
      Discord
    </button>
  </div>

  <!-- Tab Content -->
  <div x-show="tab === 'csfloat'" x-cloak>
    <!-- CSFloat settings -->
  </div>

  <div x-show="tab === 'discord'" x-cloak>
    <!-- Discord settings -->
  </div>
</div>
```

### JavaScript for AJAX Actions

**Test CSFloat API Key:**
```javascript
async function testCsfloatApi() {
  const apiKey = document.getElementById('csfloat_api_key').value;
  const resultDiv = document.getElementById('test-result');

  resultDiv.innerHTML = '<span class="text-gray-500">Testing...</span>';

  const response = await fetch('/admin/settings/csfloat/test', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ apiKey })
  });

  const result = await response.json();

  if (result.success) {
    resultDiv.innerHTML = '<span class="text-green-600">✓ API key valid</span>';
  } else {
    resultDiv.innerHTML = `<span class="text-red-600">✗ ${result.message}</span>`;
  }
}
```

**Manual Sync Trigger:**
```javascript
async function triggerCsfloatSync() {
  const button = document.getElementById('sync-now-btn');
  button.disabled = true;
  button.innerHTML = 'Syncing...';

  const response = await fetch('/admin/settings/csfloat/sync-now', {
    method: 'POST'
  });

  const result = await response.json();

  if (result.success) {
    // Show progress, poll for updates
    pollSyncStatus();
  } else {
    button.disabled = false;
    button.innerHTML = 'Sync Now';
    alert('Sync failed: ' + result.message);
  }
}

function pollSyncStatus() {
  // Poll /admin/settings/csfloat/status every 2 seconds
  // Update progress bar, mapped count, etc.
}
```

## Implementation Steps

1. **Create AdminSettingsController**
   - Create `src/Controller/AdminSettingsController.php`
   - Add route annotations with `/admin/settings` prefix
   - Add security annotation: `#[IsGranted('ROLE_ADMIN')]`
   - Inject all required services

2. **Create admin_config Table (if not exists)**
   - Check if table exists from Tasks 38/42
   - If not, create migration:
     ```bash
     docker compose exec php php bin/console make:migration
     ```
   - Add columns: id, config_key (unique), config_value, is_enabled, timestamps

3. **Create AdminConfigRepository**
   - Create `src/Repository/AdminConfigRepository.php`
   - Add methods:
     - `getValue(string $key): ?string`
     - `setValue(string $key, string $value): void`
     - `isEnabled(string $key): bool`
     - `setEnabled(string $key, bool $enabled): void`

4. **Implement Main Settings Page (GET /admin/settings)**
   - Fetch CSFloat API key (masked)
   - Fetch CSFloat sync stats (last run, items mapped, unmapped count)
   - Fetch Discord webhook config
   - Fetch Discord linked users
   - Fetch Discord recent notifications
   - Render `admin_settings/index.html.twig`

5. **Implement CSFloat API Key Save (POST /admin/settings/csfloat/api-key)**
   - Handle form submission
   - Validate API key (NotBlank, Length)
   - Save to admin_config table (key: `csfloat_api_key`)
   - Flash message: "API key saved successfully"
   - Redirect to settings page

6. **Implement CSFloat API Test (POST /admin/settings/csfloat/test)**
   - Accept JSON: `{ apiKey: "..." }`
   - Use CsfloatSearchService with provided API key
   - Search for known item (e.g., "AK-47 | Redline (Field-Tested)")
   - Return JSON: `{ success: bool, message: string }`

7. **Implement CSFloat Manual Sync (POST /admin/settings/csfloat/sync-now)**
   - Run `app:csfloat:sync-items` command in background using ProcessBuilder
   - Return JSON: `{ success: bool, message: string }`
   - Poll sync status via AJAX

8. **Implement CSFloat Status Endpoint (GET /admin/settings/csfloat/status)**
   - Return JSON with sync stats:
     ```json
     {
       "lastRun": "2025-11-08T15:30:00Z",
       "itemsMapped": 1234,
       "totalItems": 1500,
       "unmappedItems": 266,
       "isRunning": false,
       "recentErrors": [...]
     }
     ```

9. **Implement Discord Webhook Routes** (from Task 42)
   - POST /admin/settings/discord/webhook (save config)
   - POST /admin/settings/discord/test-webhook (test webhook)
   - POST /admin/settings/discord/toggle-notification/{type}
   - GET /admin/settings/discord/users (get linked users)
   - POST /admin/settings/discord/users/{id}/verify
   - POST /admin/settings/discord/users/{id}/unverify
   - DELETE /admin/settings/discord/users/{id}

10. **Create Forms**
    - Create `src/Form/CsfloatApiKeyFormType.php`
    - Create `src/Form/DiscordWebhookConfigFormType.php` (if not from Task 42)

11. **Create Main Template (index.html.twig)**
    - Extend base layout
    - Add tabbed interface (Alpine.js)
    - Add CSFloat tab content (forms, status, errors)
    - Add Discord tab content (webhooks, users, notifications)
    - Use Tailwind CSS for styling

12. **Add JavaScript for AJAX**
    - Test CSFloat API button
    - Manual sync button with progress polling
    - Test Discord webhook button
    - Auto-refresh for sync status

13. **Add Navigation Link**
    - Update main navigation (e.g., `templates/base.html.twig`)
    - Add link: "Admin Settings" (visible to admins only)
    - Icon: gear/cog icon

14. **Add Error Tracking**
    - Create `csfloat_sync_error` table (optional)
    - Columns: id, item_id, error_message, occurred_at
    - Log errors during sync, display in admin panel
    - "Clear Errors" button

15. **Add Background Sync Status**
    - Store sync status in cache or database
    - Track: is_running, started_at, progress (items processed)
    - Update from sync command
    - Poll from admin UI

## Edge Cases & Error Handling

- **Missing API key**: Show placeholder text "Not configured", disable test button
- **Invalid API key**: Test returns error, show message in red
- **Sync already running**: Disable "Sync Now" button, show "Sync in progress..."
- **No unmapped items**: Show "All items mapped ✓"
- **Sync command fails**: Show error message, enable retry button
- **Invalid webhook URL**: Form validation error, don't save
- **Discord webhook deleted (404)**: Test shows "Invalid webhook (404)"
- **No linked Discord users**: Show message "No Discord users linked yet"
- **No recent notifications**: Show message "No notifications sent yet"
- **User tries to delete Discord link in use**: Allow (commands logged in DiscordNotification)
- **Concurrent admin edits**: Last save wins (no locking for admin-only page)

## Dependencies

### Blocking Dependencies
- Task 44: CSFloat database foundation (ItemCsfloat entity)
- Task 45: CSFloat API service (CsfloatSearchService)
- Task 46: CSFloat sync command (manual trigger)

### Related Tasks (Admin Features)
- Task 38: Discord database foundation (DiscordUser, DiscordNotification entities)
- Task 39: Discord webhook service (test webhook feature)
- Task 42: Discord admin settings UI (merged into this unified panel)
- Task 48: Frontend CSFloat links (uses data from sync triggered here)

### Can Be Done in Parallel With
None (depends on all foundation tasks)

### External Dependencies
- Symfony Form component (already in project)
- Symfony Process component (for background sync)
- Tailwind CSS (already in project)
- Alpine.js (lightweight JS framework, may need adding)

## Acceptance Criteria

- [ ] AdminSettingsController created with all routes
- [ ] All routes restricted to ROLE_ADMIN
- [ ] admin_config table created (if not exists)
- [ ] AdminConfigRepository created with helper methods
- [ ] CSFloat API key form created and validates input
- [ ] CSFloat API key can be saved to database
- [ ] Test CSFloat API button works (AJAX)
- [ ] Manual sync button triggers `app:csfloat:sync-items` command
- [ ] Sync status displayed (last run, mapped count, unmapped count)
- [ ] Discord webhook config form created and validates URLs
- [ ] Discord webhook URLs can be saved to database
- [ ] Test Discord webhook button works (AJAX)
- [ ] Linked Discord users displayed in table
- [ ] Verify/unverify Discord user buttons work
- [ ] Delete Discord link button works
- [ ] Recent Discord notifications displayed
- [ ] Tabbed interface works (CSFloat/Discord tabs)
- [ ] Template uses Tailwind CSS and matches project styling
- [ ] Flash messages appear for all actions
- [ ] Navigation link to "Admin Settings" added (admin only)
- [ ] CSRF protection enabled on all forms
- [ ] Page is responsive (mobile/tablet)
- [ ] API keys partially masked in UI (e.g., `••••••abc123`)

## Manual Verification Steps

### 1. Access Settings Page

```bash
# Login as admin user
# Navigate to: http://localhost/admin/settings
# Should see tabbed interface with CSFloat and Discord tabs
```

### 2. Test CSFloat Configuration

**Save API Key:**
1. Click CSFloat tab
2. Enter valid CSFloat API key
3. Click "Test API" button
4. Should see "✓ API key valid" in green
5. Click "Save API Key"
6. Should see flash message: "API key saved successfully"

**Test Invalid API Key:**
1. Enter invalid API key
2. Click "Test API"
3. Should see error: "✗ Unauthorized" or similar

**Manual Sync:**
1. Click "Sync Now" button
2. Button should change to "Syncing..."
3. Progress should update (poll sync status)
4. After completion, see updated mapped count

### 3. Test Discord Configuration

**Save Webhook URL:**
1. Click Discord tab
2. Create webhook in Discord server
3. Paste webhook URL
4. Check "Enable System Event Notifications"
5. Click "Save Configuration"
6. Should see success flash message

**Test Webhook:**
1. Click "Test Webhook" button
2. Should see success message
3. Check Discord channel for test message

**Manage Users:**
1. View linked Discord users table
2. Click "Verify" on unverified user
3. Should see flash message, status changes to "Verified ✓"
4. Click "Unverify"
5. Status changes back to "Unverified"
6. Click "Delete" (confirm if prompted)
7. User disappears from table

### 4. Test Sync Status Display

```bash
# Run sync command manually
docker compose exec php php bin/console app:csfloat:sync-items --limit=10

# Refresh admin settings page
# Should see:
# - Last run time updated (e.g., "Just now")
# - Items mapped count increased by 10
# - Unmapped items count decreased by 10
```

### 5. Test Error Handling

**No API Key:**
1. Delete CSFloat API key from database
2. Reload admin settings
3. Should see "Not configured" placeholder
4. Test button should be disabled

**Sync Failure:**
1. Set invalid API key
2. Click "Sync Now"
3. Should see error message
4. Recent errors section should show error details

### 6. Test Access Control

```bash
# Create non-admin user
docker compose exec php php bin/console app:create-user
# Email: test@example.com, no admin role

# Login as non-admin user
# Try to access: http://localhost/admin/settings
# Should get "Access Denied" or redirect to login
```

### 7. Test Responsive Design

1. Open admin settings in browser
2. Resize browser to mobile width (375px)
3. Tabs should stack or scroll horizontally
4. Forms should be readable and usable
5. Tables should scroll horizontally if needed

### 8. Test Navigation Link

1. Login as admin
2. Check main navigation menu
3. Should see "Admin Settings" link with gear icon
4. Click link → should navigate to `/admin/settings`

## Notes & Considerations

- **Unified Panel Benefits**: Single entry point, consistent UX, easier to add new integrations
- **Tab State**: Use URL hash (`#csfloat`, `#discord`) to preserve tab selection on page reload
- **API Key Masking**: Show only last 6 characters (e.g., `••••••••••••abc123`)
- **Background Sync**: Use Symfony Messenger or ProcessBuilder for async command execution
- **Progress Polling**: Poll sync status every 2 seconds while sync is running
- **Error Display**: Show friendly error messages, not stack traces
- **Future Integrations**: Add new tabs for additional services (e.g., Steam API, price alerts)
- **Permissions**: Consider adding granular permissions (e.g., ROLE_ADMIN_DISCORD, ROLE_ADMIN_CSFLOAT)
- **Audit Log**: Consider logging admin actions (who changed what setting, when)
- **Export Config**: Add "Export Settings" button (download JSON of all config)
- **Import Config**: Add "Import Settings" button (upload JSON to restore config)

## Related Tasks

- Task 44: CSFloat database foundation (blocking)
- Task 45: CSFloat API service (blocking)
- Task 46: CSFloat sync command (blocking)
- Task 38: Discord database foundation (blocking, if not complete)
- Task 39: Discord webhook service (blocking, if not complete)
- Task 42: Discord admin settings UI (merged into this task)
- Task 48: Frontend CSFloat links (uses data from sync triggered here)
