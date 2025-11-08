# Discord Admin Settings UI

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Medium
**Created**: 2025-11-08

## Overview

Create a basic admin settings page in the web application for managing Discord integration configuration. Provides UI for managing webhook URLs, viewing/verifying linked Discord users, enabling/disabling notification types, and testing Discord connectivity.

## Problem Statement

While Discord configuration can be managed via database or .env files, administrators need a user-friendly web interface to:
- Configure webhook URLs without editing config files
- View and verify Discord user account links
- Enable/disable notification types
- Test Discord integration without console commands
- Monitor Discord notification status

## Requirements

### Functional Requirements
- Admin-only settings page (restrict to ROLE_ADMIN or superuser)
- Configure Discord webhook URLs (system events, future: price alerts, etc.)
- View list of Discord user account links
- Verify/unverify user account links (toggle isVerified)
- Enable/disable notification types
- Test webhook connectivity (send test message)
- Display recent Discord notifications (last 20)
- Clear bot token configuration (not display for security)

### Non-Functional Requirements
- Secure: only admins can access
- Validate webhook URLs before saving
- Hide sensitive data (bot token, webhook tokens in URLs)
- Responsive UI (Tailwind CSS)
- Form validation with error messages
- Success/error flash messages

## Technical Approach

### Controller

#### New Controller: `DiscordAdminController`
Location: `src/Controller/DiscordAdminController.php`

**Route Prefix:** `/admin/discord`

**Routes:**
1. `GET /admin/discord` - Main settings page
2. `POST /admin/discord/webhook` - Save webhook configuration
3. `POST /admin/discord/test-webhook` - Send test webhook message
4. `POST /admin/discord/toggle-notification/{type}` - Enable/disable notification type
5. `GET /admin/discord/users` - List linked Discord users
6. `POST /admin/discord/users/{id}/verify` - Verify user account link
7. `POST /admin/discord/users/{id}/unverify` - Unverify user account link
8. `DELETE /admin/discord/users/{id}` - Delete Discord user link

**Security:**
- All routes require `ROLE_ADMIN`
- CSRF protection on all forms

**Dependencies:**
- `DiscordConfigRepository`
- `DiscordUserRepository`
- `DiscordNotificationRepository`
- `DiscordWebhookService` (from Task 39)
- `FormFactoryInterface`
- `EntityManagerInterface`

### Forms

#### New Form: `DiscordWebhookConfigForm`
Location: `src/Form/DiscordWebhookConfigFormType.php`

**Fields:**
- `webhookSystemEvents` (TextType) - URL for system event notifications
- `notifySystemEvents` (CheckboxType) - Enable/disable system event notifications

**Validation:**
- webhook URLs must be valid Discord webhook format: `https://discord.com/api/webhooks/{id}/{token}`
- Use Symfony URL validator
- Custom validator to check Discord webhook pattern

### Templates

#### New Template: `templates/discord_admin/index.html.twig`
Location: `templates/discord_admin/index.html.twig`

**Sections:**
1. **Webhook Configuration**
   - Form to configure webhook URLs
   - Checkboxes to enable/disable notification types
   - Test button for each webhook

2. **Linked Discord Users**
   - Table showing: Discord username, linked user (email), verified status, linked date
   - Actions: Verify/Unverify button, Delete link button
   - Filter: Show all / Show unverified

3. **Recent Notifications**
   - Table showing: Type, Status, Sent At, Message preview
   - Last 20 notifications
   - Color-coded by status (success=green, failed=red, queued=yellow)

4. **Bot Status** (optional)
   - Display if bot is running (check recent heartbeat or command execution)
   - Show bot username, guilds count (if available)

### Service Updates

#### Update: `DiscordWebhookService` (from Task 39)
Add method for testing webhooks:

```php
public function testWebhook(string $webhookUrl): array
{
    // Send test message to webhook
    // Return ['success' => bool, 'message' => string, 'error' => ?string]
}
```

### Styling

Use existing Tailwind CSS classes from project for consistent UI:
- Forms: Input fields, checkboxes, buttons
- Tables: Striped rows, hover effects
- Status badges: Color-coded (green, red, yellow)
- Flash messages: Success (green), error (red), warning (yellow)

## Implementation Steps

1. **Create DiscordAdminController**
   - Create `src/Controller/DiscordAdminController.php`
   - Add route annotations with `/admin/discord` prefix
   - Add security annotation: `#[IsGranted('ROLE_ADMIN')]`
   - Inject required services via constructor

2. **Implement Main Settings Page (GET /admin/discord)**
   - Fetch current webhook configurations from DiscordConfig
   - Fetch notification enable/disable settings
   - Fetch linked Discord users with pagination (or limit to 50)
   - Fetch recent Discord notifications (last 20)
   - Render `discord_admin/index.html.twig`

3. **Create Webhook Configuration Form**
   - Create `src/Form/DiscordWebhookConfigFormType.php`
   - Add webhook URL fields (one per notification type)
   - Add enable/disable checkboxes
   - Add custom validator for Discord webhook URL format

4. **Implement Webhook Configuration Save (POST /admin/discord/webhook)**
   - Handle form submission
   - Validate webhook URLs
   - Save to DiscordConfig table (upsert by configKey)
   - Add flash message: "Webhook configuration saved successfully"
   - Redirect back to settings page

5. **Implement Test Webhook (POST /admin/discord/test-webhook)**
   - Accept webhook URL as parameter (or use configured URL)
   - Call `DiscordWebhookService::testWebhook()`
   - Send test embed message with timestamp
   - Return JSON response: `{success: bool, message: string}`
   - Display result via JavaScript alert or inline message

6. **Implement Toggle Notification Type (POST /admin/discord/toggle-notification/{type})**
   - Find DiscordConfig by key (e.g., "notify_system_events")
   - Toggle `isEnabled` flag
   - Persist to database
   - Add flash message
   - Redirect back to settings page

7. **Implement Discord Users List (GET /admin/discord/users)**
   - Query DiscordUser with JOIN to User
   - Order by: unverified first, then by linked date DESC
   - Render table in template
   - Add filter form: Show all / Show unverified

8. **Implement Verify User (POST /admin/discord/users/{id}/verify)**
   - Find DiscordUser by ID
   - Set `isVerified = true`, `verifiedAt = NOW()`
   - Persist
   - Add flash message: "Discord user verified successfully"
   - Redirect back to settings page

9. **Implement Unverify User (POST /admin/discord/users/{id}/unverify)**
   - Find DiscordUser by ID
   - Set `isVerified = false`, `verifiedAt = null`
   - Persist
   - Add flash message: "Discord user unverified"
   - Redirect back to settings page

10. **Implement Delete Discord User Link (DELETE /admin/discord/users/{id})**
    - Find DiscordUser by ID
    - Delete entity
    - Add flash message: "Discord link removed"
    - Redirect back to settings page

11. **Create Main Template (index.html.twig)**
    - Extend base layout
    - Add webhook configuration form section
    - Add linked users table section
    - Add recent notifications table section
    - Use Tailwind CSS for styling
    - Add JavaScript for test webhook button (AJAX)

12. **Create Webhook Test JavaScript**
    - Add inline JavaScript or separate asset file
    - On "Test Webhook" button click, send AJAX POST
    - Display result (success/error) inline or via alert
    - Show loading spinner during test

13. **Add Navigation Link**
    - Update main navigation (e.g., `templates/base.html.twig` or admin menu)
    - Add link: "Discord Settings" (visible to admins only)
    - Icon: Discord logo (optional)

14. **Add Webhook URL Validator**
    - Create `src/Validator/DiscordWebhookUrl.php` (custom constraint)
    - Create `src/Validator/DiscordWebhookUrlValidator.php`
    - Validate format: `https://discord.com/api/webhooks/{id}/{token}`
    - Both ID and token must be present (numeric and alphanumeric respectively)

## Edge Cases & Error Handling

- **Invalid webhook URL format**: Show form validation error, don't save
- **Webhook URL returns 404 (deleted webhook)**: Test button shows "Invalid webhook (404)"
- **User tries to verify non-existent Discord user**: Return 404 error
- **Deleting Discord user link with command history**: Allow deletion (commands logged in DiscordNotification)
- **No linked Discord users**: Show message "No Discord users linked yet"
- **No recent notifications**: Show message "No notifications sent yet"
- **Empty webhook URL**: Allow (disables that notification type)
- **Very long webhook URLs**: Database column should support 255 chars (standard Discord webhook URL < 200 chars)
- **Concurrent edits**: Last save wins (no locking needed for admin-only page)

## Dependencies

### Blocking Dependencies
- Task 38: Discord database foundation (DiscordConfig, DiscordUser, DiscordNotification entities)

### Related Tasks (Discord Integration Feature)
- Task 39: Discord webhook service (used for testing webhooks)
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

- [ ] DiscordAdminController created with all required routes
- [ ] All routes restricted to ROLE_ADMIN
- [ ] Webhook configuration form created and validates URLs
- [ ] Webhook URLs can be saved to DiscordConfig table
- [ ] Test webhook button sends test message and shows result
- [ ] Linked Discord users displayed in table
- [ ] Verify/unverify buttons work and update isVerified flag
- [ ] Delete Discord link button removes DiscordUser record
- [ ] Recent notifications displayed (last 20) with status color-coding
- [ ] Template uses Tailwind CSS and matches project styling
- [ ] Flash messages appear for all actions (success/error)
- [ ] Navigation link to "Discord Settings" added (admin only)
- [ ] Webhook URL validator prevents invalid URLs
- [ ] CSRF protection enabled on all forms
- [ ] Page is responsive (works on mobile/tablet)

## Manual Verification Steps

### Access Settings Page
```bash
# 1. Ensure you have admin user
docker compose exec php php bin/console app:create-user
# Create user with email, password, grant ROLE_ADMIN if needed

# 2. Login to web application as admin
# Navigate to: http://localhost/admin/discord
# Should see Discord Settings page
```

### Test Webhook Configuration
1. **Configure Webhook URL**
   - Create webhook in Discord server (Server Settings → Integrations → Webhooks → New Webhook)
   - Copy webhook URL
   - Paste into "System Events Webhook URL" field
   - Check "Enable System Event Notifications"
   - Click "Save Configuration"
   - Should see success flash message

2. **Test Webhook**
   - Click "Test Webhook" button next to System Events webhook
   - Should see success message or error
   - Check Discord channel for test message

### Test User Management
1. **Link Discord Account**
   ```bash
   # Manually create link for testing
   docker compose exec php php bin/console dbal:run-sql "INSERT INTO discord_user (user_id, discord_id, discord_username, is_verified, linked_at) VALUES (1, '123456789012345678', 'TestUser#0000', 0, NOW());"
   ```

2. **View Linked Users**
   - Refresh settings page
   - Should see TestUser in "Linked Discord Users" table
   - Status should show "Unverified" (red badge)

3. **Verify User**
   - Click "Verify" button next to TestUser
   - Should see success flash message
   - Status should change to "Verified" (green badge)

4. **Unverify User**
   - Click "Unverify" button
   - Should see flash message
   - Status should change back to "Unverified"

5. **Delete Link**
   - Click "Delete" button (confirm if prompted)
   - Should see success flash message
   - User should disappear from table

### Test Notifications Display
1. **Send Test Notification**
   ```bash
   docker compose exec php php bin/console app:discord:test-webhook webhook_system_events "Test notification for UI"
   ```

2. **View in UI**
   - Refresh settings page
   - Should see notification in "Recent Notifications" table
   - Status should be "sent" (green badge)

### Test Validation
1. **Invalid Webhook URL**
   - Enter invalid URL: `https://example.com/not-a-webhook`
   - Click "Save Configuration"
   - Should see validation error: "Invalid Discord webhook URL format"

2. **Empty Webhook URL**
   - Clear webhook URL field
   - Click "Save Configuration"
   - Should save successfully (disables notification type)

### Test Access Control
```bash
# 1. Create non-admin user
docker compose exec php php bin/console app:create-user
# Use email without admin role

# 2. Login as non-admin user
# Try to access: http://localhost/admin/discord
# Should get "Access Denied" or redirect to login
```

## Notes & Considerations

- **Security**: Never display bot token in UI (too sensitive). Only show "Configured" or "Not configured" status.
- **Webhook Token Hiding**: Consider masking webhook token in UI (show only last 6 chars: `...Abc123`)
- **Pagination**: If many Discord users, add pagination (use Paginator or KnpPaginatorBundle)
- **Real-time Updates**: Consider adding auto-refresh for notification table (every 30 seconds via JavaScript)
- **Bulk Actions**: Future enhancement - verify/delete multiple users at once
- **Audit Log**: Consider logging admin actions (who verified which user, when)
- **Bot Status**: If bot is down, show warning banner in settings page
- **Notification Filtering**: Add filters for notification table (by type, status, date range)
- **Export**: Add export button for notifications (CSV or JSON) for analysis

## Related Tasks

- Task 38: Discord database foundation - MUST be completed first (blocking)
- Task 39: Discord webhook service - used by Test Webhook feature (can be parallel)
- Task 40: Discord bot foundation - users verified here can use bot (can be parallel)
- Task 41: Discord bot !price command - requires verified users from this UI
- Task 43: DISCORD.md documentation - document admin UI in setup guide
