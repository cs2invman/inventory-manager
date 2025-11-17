# Admin Panel with Discord Management

**Status**: Completed
**Priority**: Medium
**Estimated Effort**: Medium
**Created**: 2025-11-17
**Updated from Task #42**: Expanded scope to include general admin panel homepage and restructured Discord admin sections to use database-driven configuration.

## Overview

Create a general admin panel homepage with navigation links to all admin pages, plus a Discord management page for configuring Discord integration. The admin panel provides a centralized place for administrators to access system settings, while the Discord page allows management of webhooks, user links, configuration, and notification history.

## Problem Statement

Administrators need:
- A centralized admin panel to access all administrative features
- Easy navigation to admin pages from the site header
- A way to manage Discord configuration (DiscordConfig table) without editing files
- A way to manage Discord webhooks (DiscordWebhook table) for different notification types
- A way to view and manage Discord user links (verify/unverify/delete)
- A way to see recent Discord notification history (success/failure status)

## Requirements

### Functional Requirements

**General Admin Panel:**
- Admin panel homepage at `/admin`
- List of available admin pages (initially: Discord Settings)
- Header navigation link to admin panel (visible to ROLE_ADMIN only)

**Discord Admin Page:**
- Discord admin page at `/admin/discord`
- **Section 1: General Configuration** - View/edit all DiscordConfig rows (configKey, configValue, isEnabled)
- **Section 2: Webhook Management** - CRUD for DiscordWebhook (identifier, displayName, webhookUrl, description, isEnabled)
- **Section 3: Discord Users** - View/verify/unverify/delete Discord user links
- **Section 4: Recent Notifications** - Show last 10 Discord notifications (type, date, success/failure status)

### Non-Functional Requirements
- Secure: only ROLE_ADMIN can access
- Validate webhook URLs before saving
- Hide sensitive data (webhook tokens - show only last 6 chars)
- Responsive UI (Tailwind CSS)
- Form validation with error messages
- Success/error flash messages
- CSRF protection on all forms

## Technical Approach

### Controllers

#### New Controller: `AdminController`
Location: `src/Controller/AdminController.php`

**Route Prefix:** `/admin`

**Routes:**
1. `GET /admin` - Admin panel homepage (lists available admin pages)

**Security:**
- Requires `ROLE_ADMIN`
- CSRF protection

**Dependencies:**
- None (static page for now)

**Implementation:**
- Render list of admin pages:
  - Discord Settings (`/admin/discord`)
  - Future pages...
- Use card/grid layout with icons and descriptions

---

#### New Controller: `DiscordAdminController`
Location: `src/Controller/DiscordAdminController.php`

**Route Prefix:** `/admin/discord`

**Routes:**
1. `GET /admin/discord` - Main Discord settings page
2. `POST /admin/discord/config` - Save general Discord config (DiscordConfig table)
3. `POST /admin/discord/webhook` - Create/update webhook (DiscordWebhook table)
4. `DELETE /admin/discord/webhook/{id}` - Delete webhook
5. `POST /admin/discord/webhook/{id}/toggle` - Toggle webhook enabled status
6. `POST /admin/discord/users/{id}/verify` - Verify Discord user
7. `POST /admin/discord/users/{id}/unverify` - Unverify Discord user
8. `DELETE /admin/discord/users/{id}` - Delete Discord user link

**Security:**
- All routes require `ROLE_ADMIN`
- CSRF protection on all forms

**Dependencies:**
- `DiscordConfigRepository`
- `DiscordWebhookRepository`
- `DiscordUserRepository`
- `DiscordNotificationRepository`
- `FormFactoryInterface`
- `EntityManagerInterface`

### Forms

#### New Form: `DiscordConfigFormType`
Location: `src/Form/DiscordConfigFormType.php`

**Purpose:** Edit all DiscordConfig rows

**Fields:**
- Dynamic fields based on existing DiscordConfig rows
- Each row: `configValue` (TextType), `isEnabled` (CheckboxType)
- Display `configKey` as label (read-only)

**Validation:**
- Required: configValue (if isEnabled = true)

---

#### New Form: `DiscordWebhookFormType`
Location: `src/Form/DiscordWebhookFormType.php`

**Purpose:** Create/edit DiscordWebhook

**Fields:**
- `identifier` (TextType) - Unique key (e.g., "system_events")
- `displayName` (TextType) - Human-readable name
- `webhookUrl` (TextType) - Discord webhook URL
- `description` (TextareaType, optional) - Description of webhook purpose
- `isEnabled` (CheckboxType) - Enable/disable webhook

**Validation:**
- identifier: Required, unique, alphanumeric + underscores
- displayName: Required
- webhookUrl: Required, valid Discord webhook URL format
- Use custom validator: `DiscordWebhookUrlValidator`

### Templates

#### New Template: `templates/admin/index.html.twig`
Location: `templates/admin/index.html.twig`

**Purpose:** Admin panel homepage

**Content:**
- Page title: "Admin Panel"
- Grid/list of admin pages:
  - **Discord Settings** - Manage Discord webhooks, users, and notifications
  - (Placeholder for future pages)
- Each card: Icon, title, description, link button

---

#### New Template: `templates/discord_admin/index.html.twig`
Location: `templates/discord_admin/index.html.twig`

**Purpose:** Discord admin page

**Sections:**

1. **General Configuration (DiscordConfig)**
   - Table or form showing all DiscordConfig rows
   - Columns: Config Key, Value, Enabled (checkbox), Actions (Save button)
   - Editable: configValue, isEnabled
   - Save button per row or global save

2. **Webhook Management (DiscordWebhook)**
   - Table showing: Identifier, Display Name, Webhook URL (masked), Enabled, Actions
   - Actions: Edit button, Delete button, Toggle enabled button
   - Add new webhook button → Opens form modal or inline form
   - Webhook URL: Show only last 6 chars (e.g., `https://discord.com/api/webhooks/...Abc123`)

3. **Linked Discord Users**
   - Table showing: Discord Username, Linked User (email), Verified Status, Linked Date, Actions
   - Actions: Verify/Unverify button, Delete link button
   - Filter: Show all / Show unverified
   - Status badge: Green (verified), Red (unverified)

4. **Recent Notifications**
   - Table showing: Type, Status, Sent At, Message Preview
   - Last 10 notifications
   - Status color-coded: Success (green), Failed (red), Queued (yellow)

---

#### Update Template: `templates/base.html.twig`
Location: `templates/base.html.twig`

**Change:** Add "Admin" link to header navigation

**Implementation:**
```twig
{% if is_granted('ROLE_ADMIN') %}
    <a href="{{ path('admin_index') }}" class="...">Admin</a>
{% endif %}
```

### Validators

#### New Validator: `DiscordWebhookUrl`
Location: `src/Validator/DiscordWebhookUrl.php`

**Purpose:** Custom constraint for validating Discord webhook URLs

**Format:** `https://discord.com/api/webhooks/{id}/{token}`
- ID: Numeric (snowflake ID)
- Token: Alphanumeric + special chars

---

#### New Validator: `DiscordWebhookUrlValidator`
Location: `src/Validator/DiscordWebhookUrlValidator.php`

**Implementation:**
- Validate URL format
- Check regex: `^https://discord\.com/api/webhooks/\d+/[\w-]+$`
- Return validation error if invalid

## Implementation Steps

1. **Create AdminController**
   - Create `src/Controller/AdminController.php`
   - Add route: `GET /admin` (name: `admin_index`)
   - Security: `#[IsGranted('ROLE_ADMIN')]`
   - Render admin panel homepage with list of pages

2. **Create Admin Panel Template**
   - Create `templates/admin/index.html.twig`
   - Extend base layout
   - Add page title: "Admin Panel"
   - Add grid/list of admin page cards:
     - Discord Settings (`/admin/discord`)
   - Use Tailwind CSS for styling

3. **Add Admin Link to Header**
   - Edit `templates/base.html.twig`
   - Add "Admin" navigation link (visible to ROLE_ADMIN)
   - Link to: `{{ path('admin_index') }}`

4. **Create DiscordAdminController**
   - Create `src/Controller/DiscordAdminController.php`
   - Add route annotations with `/admin/discord` prefix
   - Security: `#[IsGranted('ROLE_ADMIN')]`
   - Inject dependencies: DiscordConfigRepository, DiscordWebhookRepository, DiscordUserRepository, DiscordNotificationRepository, EntityManagerInterface

5. **Implement Main Discord Settings Page (GET /admin/discord)**
   - Fetch all DiscordConfig rows
   - Fetch all DiscordWebhook rows
   - Fetch linked Discord users (with JOIN to User)
   - Fetch last 10 DiscordNotifications (ordered by sentAt DESC)
   - Render `discord_admin/index.html.twig`

6. **Create DiscordConfigFormType**
   - Create `src/Form/DiscordConfigFormType.php`
   - Build dynamic form based on DiscordConfig rows
   - Fields: configValue (TextType), isEnabled (CheckboxType)
   - Add validation: required if isEnabled = true

7. **Implement Save General Config (POST /admin/discord/config)**
   - Handle DiscordConfigFormType submission
   - Update DiscordConfig rows in database
   - Add flash message: "Discord configuration saved successfully"
   - Redirect back to Discord settings page

8. **Create DiscordWebhookFormType**
   - Create `src/Form/DiscordWebhookFormType.php`
   - Fields: identifier, displayName, webhookUrl, description, isEnabled
   - Add custom validator: DiscordWebhookUrl

9. **Create Webhook URL Validator**
   - Create `src/Validator/DiscordWebhookUrl.php` (constraint)
   - Create `src/Validator/DiscordWebhookUrlValidator.php`
   - Validate format: `https://discord.com/api/webhooks/{id}/{token}`
   - Regex: `^https://discord\.com/api/webhooks/\d+/[\w-]+$`

10. **Implement Create/Update Webhook (POST /admin/discord/webhook)**
    - Handle DiscordWebhookFormType submission
    - Validate webhook URL
    - Create or update DiscordWebhook entity
    - Add flash message: "Webhook saved successfully"
    - Redirect back to Discord settings page

11. **Implement Delete Webhook (DELETE /admin/discord/webhook/{id})**
    - Find DiscordWebhook by ID
    - Delete entity
    - Add flash message: "Webhook deleted successfully"
    - Redirect back to Discord settings page

12. **Implement Toggle Webhook (POST /admin/discord/webhook/{id}/toggle)**
    - Find DiscordWebhook by ID
    - Toggle `isEnabled` flag
    - Persist
    - Add flash message: "Webhook toggled successfully"
    - Redirect back to Discord settings page

13. **Implement Discord User Management**
    - **Verify**: POST `/admin/discord/users/{id}/verify`
      - Set `isVerified = true`, `verifiedAt = NOW()`
      - Flash: "Discord user verified successfully"
    - **Unverify**: POST `/admin/discord/users/{id}/unverify`
      - Set `isVerified = false`, `verifiedAt = null`
      - Flash: "Discord user unverified"
    - **Delete**: DELETE `/admin/discord/users/{id}`
      - Delete DiscordUser entity
      - Flash: "Discord link removed"

14. **Create Discord Admin Template**
    - Create `templates/discord_admin/index.html.twig`
    - Extend base layout
    - Add 4 sections:
      1. General Configuration form (DiscordConfig)
      2. Webhook Management table (DiscordWebhook CRUD)
      3. Linked Discord Users table (verify/unverify/delete)
      4. Recent Notifications table (last 10)
    - Use Tailwind CSS for styling
    - Mask webhook URLs (show only last 6 chars)

15. **Add Flash Message Handling**
    - Ensure flash messages display in templates
    - Use existing flash message styles (success, error, warning)

## Edge Cases & Error Handling

- **Invalid webhook URL format**: Show form validation error, don't save
- **Duplicate webhook identifier**: Show validation error (unique constraint)
- **Webhook URL returns 404 (deleted webhook)**: Allow saving (user may be updating), no live validation
- **User tries to verify non-existent Discord user**: Return 404 error
- **Deleting Discord user link with command history**: Allow deletion (commands logged in DiscordNotification)
- **No linked Discord users**: Show message "No Discord users linked yet"
- **No recent notifications**: Show message "No notifications sent yet"
- **Empty webhook URL**: Allow (disables that webhook)
- **Very long webhook URLs**: Database column should support 255 chars (standard Discord webhook URL < 200 chars)
- **Concurrent edits**: Last save wins (no locking needed for admin-only page)
- **Empty DiscordConfig table**: Show message "No Discord configuration found"
- **Editing config with invalid value**: Show validation error based on field type

## Dependencies

### Blocking Dependencies
- Task 38: Discord database foundation (DiscordConfig, DiscordWebhook, DiscordUser, DiscordNotification entities)

### Related Tasks (Discord Integration Feature)
- Task 39: Discord webhook service (not blocking - this UI can be built without service)
- Task 40: Discord bot foundation (users can link accounts for bot commands)
- Task 41: Discord bot !price command (users must be verified via this UI)
- Task 43: DISCORD.md documentation (document admin UI usage)

### Can Be Done in Parallel With
- Task 39: Discord webhook service (both depend on Task 38, this UI can be built while service is developed)
- Task 40: Discord bot foundation (both depend on Task 38)
- Task 41: Discord bot commands (this UI verifies users for commands)

### External Dependencies
- Symfony Form component (already in project)
- Symfony Validator component (already in project)
- Tailwind CSS (already in project)
- Symfony Security (already in project)

## Acceptance Criteria

**General Admin Panel:**
- [ ] AdminController created with `/admin` route
- [ ] Admin panel homepage template created
- [ ] Admin panel lists available admin pages (initially Discord Settings)
- [ ] Header navigation link to "Admin" added (visible to ROLE_ADMIN only)
- [ ] `/admin` route restricted to ROLE_ADMIN
- [ ] Admin panel uses Tailwind CSS and matches project styling

**Discord Admin Page:**
- [ ] DiscordAdminController created with all required routes
- [ ] All routes restricted to ROLE_ADMIN
- [ ] Discord settings page displays 4 sections: Config, Webhooks, Users, Notifications

**Section 1: General Configuration (DiscordConfig):**
- [ ] DiscordConfigFormType created
- [ ] All DiscordConfig rows displayed in table/form
- [ ] Admins can edit configValue and isEnabled
- [ ] Configuration saves successfully to database
- [ ] Flash message appears on save

**Section 2: Webhook Management (DiscordWebhook):**
- [ ] DiscordWebhookFormType created
- [ ] Webhook URL validator created and prevents invalid URLs
- [ ] Webhooks displayed in table (identifier, displayName, masked URL, isEnabled)
- [ ] Webhook URLs masked (show only last 6 chars)
- [ ] Admins can create new webhooks
- [ ] Admins can edit existing webhooks
- [ ] Admins can delete webhooks
- [ ] Admins can toggle webhook enabled status
- [ ] Flash messages appear for all actions

**Section 3: Discord Users:**
- [ ] Linked Discord users displayed in table
- [ ] Verify/unverify buttons work and update isVerified flag
- [ ] Delete button removes DiscordUser record
- [ ] Status badges show verified (green) vs unverified (red)
- [ ] Flash messages appear for all actions

**Section 4: Recent Notifications:**
- [ ] Last 10 DiscordNotifications displayed
- [ ] Columns: Type, Status, Sent At, Message Preview
- [ ] Status color-coded: Success (green), Failed (red), Queued (yellow)

**General:**
- [ ] Template uses Tailwind CSS and matches project styling
- [ ] Flash messages appear for all actions (success/error)
- [ ] CSRF protection enabled on all forms
- [ ] Page is responsive (works on mobile/tablet)

## Manual Verification Steps

### Access Admin Panel
```bash
# 1. Ensure you have admin user
docker compose exec php php bin/console app:create-user
# Create user with email, password, grant ROLE_ADMIN if needed

# 2. Login to web application as admin
# Navigate to: http://localhost
# Should see "Admin" link in header (only visible to admins)
```

### Test Admin Panel Homepage
1. Click "Admin" link in header
2. Should navigate to: http://localhost/admin
3. Should see "Admin Panel" page
4. Should see card for "Discord Settings"
5. Click "Discord Settings" card
6. Should navigate to: http://localhost/admin/discord

### Test General Configuration (DiscordConfig)
1. **View Configuration**
   - On Discord settings page, scroll to "General Configuration" section
   - Should see table/form with all DiscordConfig rows
   - Each row shows: Config Key, Value, Enabled checkbox

2. **Edit Configuration**
   - Change a configValue (e.g., rate limit, timeout)
   - Toggle isEnabled checkbox
   - Click "Save Configuration"
   - Should see success flash message
   - Refresh page - changes should persist

3. **Validation**
   - Try saving with empty required field (if isEnabled = true)
   - Should see validation error

### Test Webhook Management (DiscordWebhook)
1. **Create Webhook**
   - Scroll to "Webhook Management" section
   - Click "Add Webhook" button
   - Fill form:
     - Identifier: `test_webhook`
     - Display Name: `Test Webhook`
     - Webhook URL: (paste valid Discord webhook URL)
     - Description: `Test webhook for notifications`
     - Enabled: Check
   - Click "Save"
   - Should see success flash message
   - Should see new webhook in table

2. **View Webhook**
   - Webhook table should show:
     - Identifier: `test_webhook`
     - Display Name: `Test Webhook`
     - Webhook URL: `https://discord.com/api/webhooks/...Abc123` (masked)
     - Enabled: Green badge or checkmark
   - Actions: Edit, Delete, Toggle buttons

3. **Edit Webhook**
   - Click "Edit" button
   - Change displayName or webhookUrl
   - Click "Save"
   - Should see success flash message
   - Changes should appear in table

4. **Toggle Webhook**
   - Click "Toggle" button (or toggle switch)
   - Should see flash message: "Webhook toggled successfully"
   - isEnabled status should change (green → red or vice versa)

5. **Delete Webhook**
   - Click "Delete" button (confirm if prompted)
   - Should see success flash message
   - Webhook should disappear from table

6. **Validation**
   - Try creating webhook with invalid URL: `https://example.com/not-a-webhook`
   - Should see validation error: "Invalid Discord webhook URL format"
   - Try creating webhook with duplicate identifier
   - Should see validation error: "Identifier already exists"

### Test Discord User Management
1. **Create Test Link**
   ```bash
   # Manually create link for testing
   docker compose exec php php bin/console dbal:run-sql "INSERT INTO discord_user (user_id, discord_id, discord_username, is_verified, linked_at) VALUES (1, '123456789012345678', 'TestUser#0000', 0, NOW());"
   ```

2. **View Linked Users**
   - Refresh Discord settings page
   - Scroll to "Linked Discord Users" section
   - Should see TestUser in table
   - Columns: Discord Username, Linked User (email), Verified Status, Linked Date, Actions
   - Status should show "Unverified" (red badge)

3. **Verify User**
   - Click "Verify" button next to TestUser
   - Should see success flash message: "Discord user verified successfully"
   - Status should change to "Verified" (green badge)
   - Refresh page - status should persist

4. **Unverify User**
   - Click "Unverify" button
   - Should see flash message: "Discord user unverified"
   - Status should change back to "Unverified" (red badge)

5. **Delete Link**
   - Click "Delete" button (confirm if prompted)
   - Should see success flash message: "Discord link removed"
   - User should disappear from table

### Test Recent Notifications
1. **Create Test Notification**
   ```bash
   # Send test notification (if webhook service is implemented)
   docker compose exec php php bin/console app:discord:test-webhook system_events "Test notification for UI"

   # Or manually insert:
   docker compose exec php php bin/console dbal:run-sql "INSERT INTO discord_notification (webhook_identifier, notification_type, message, status, sent_at) VALUES ('system_events', 'test', 'Test notification for UI', 'sent', NOW());"
   ```

2. **View in UI**
   - Refresh Discord settings page
   - Scroll to "Recent Notifications" section
   - Should see notification in table
   - Columns: Type, Status, Sent At, Message Preview
   - Status should be "sent" (green badge)
   - Should show only last 10 notifications (if more exist)

3. **Status Colors**
   - Create notifications with different statuses: sent, failed, queued
   - Each status should have different color:
     - sent: Green
     - failed: Red
     - queued: Yellow

### Test Access Control
```bash
# 1. Create non-admin user
docker compose exec php php bin/console app:create-user
# Use email without admin role

# 2. Login as non-admin user
# Header should NOT show "Admin" link
# Try to access: http://localhost/admin
# Should get "Access Denied" (403) or redirect to login

# 3. Try to access: http://localhost/admin/discord
# Should get "Access Denied" (403) or redirect to login
```

### Test Responsive Design
1. Open browser developer tools
2. Toggle device toolbar (mobile/tablet view)
3. Navigate through admin panel and Discord settings
4. Verify:
   - Tables are scrollable or responsive
   - Forms are usable on small screens
   - Buttons are touch-friendly
   - Navigation works on mobile

## Notes & Considerations

**Updated from Task #42**: This task expands the original Discord admin settings UI to include:
- General admin panel homepage (provides foundation for future admin pages)
- Header navigation link for easy access
- Restructured Discord page with 4 clear sections
- Database-driven configuration (DiscordConfig and DiscordWebhook CRUD)
- Simplified scope: removed webhook testing, test message JavaScript, complex notification toggles

**Security:**
- Never display bot token in UI (too sensitive)
- Webhook tokens should be masked (show only last 6 chars: `...Abc123`)
- All routes require ROLE_ADMIN
- CSRF protection on all forms

**Future Enhancements** (NOT in this task):
- Pagination for Discord users (if many users)
- Pagination for notifications (if viewing more than 10)
- Real-time updates for notification table (auto-refresh every 30 seconds)
- Bulk actions (verify/delete multiple users at once)
- Audit log (track admin actions)
- Bot status indicator (show if bot is online/offline)
- Notification filtering (by type, status, date range)
- Export notifications (CSV or JSON)
- Test webhook button (send test message to webhook)
- More admin pages (User Management, System Settings, etc.)

**Implementation Notes:**
- Use Tailwind CSS for all styling (consistency with project)
- Follow existing project patterns for controllers, forms, templates
- Use flash messages for all user actions
- Keep forms simple and focused
- Prioritize usability over features

**Database Considerations:**
- DiscordConfig.configValue is TEXT (supports long values)
- DiscordWebhook.webhookUrl is VARCHAR(255) (sufficient for Discord webhooks)
- DiscordUser has foreign key to User (cascade delete if user deleted)
- DiscordNotification has webhook_identifier (string reference, not FK)

## Related Tasks

- Task 38: Discord database foundation - MUST be completed first (blocking)
- Task 39: Discord webhook service - used by Discord notifications (can be parallel)
- Task 40: Discord bot foundation - users verified here can use bot (can be parallel)
- Task 41: Discord bot !price command - requires verified users from this UI
- Task 43: DISCORD.md documentation - document admin UI in setup guide
- Task #42: Original Discord admin UI task (superseded by this task)
