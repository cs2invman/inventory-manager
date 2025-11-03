# User Configuration System

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-02

## Overview

Implement a flexible user configuration system to store user-specific settings and preferences. The initial implementation will focus on storing the user's Steam ID to enable dynamic Steam inventory links in the import interface, but the architecture will be designed for easy expansion to support additional configuration fields in the future (e.g., Discord webhooks, notification preferences, display settings).

## Problem Statement

Currently, the inventory import interface displays hardcoded placeholder URLs that require users to manually replace "STEAMID" with their actual Steam ID. This creates friction in the user experience and increases the chance of errors during the import process.

Additionally, as the platform grows, there will be a need to store various user-specific configuration settings (notification preferences, API keys, display preferences, etc.). Rather than adding these fields directly to the User entity, we need a flexible configuration system that can be easily extended without requiring database migrations for every new setting.

## Requirements

### Functional Requirements
- Store user's Steam ID (17-digit SteamID64 format) in a dedicated configuration entity
- Validate Steam ID format (basic validation: must be 17 digits, numeric only)
- Provide a user settings page where users can view and edit their Steam ID
- Require Steam ID configuration before allowing access to inventory import page
- Redirect users to settings page if Steam ID is not configured when attempting to access import
- Update inventory import interface to display clickable Steam inventory URLs using stored Steam ID
- Design the configuration system to be easily extensible for future fields without major refactoring

### Non-Functional Requirements
- **Data Integrity**: One-to-one relationship between User and UserConfig entities
- **Performance**: Minimal database queries (eager loading of config with user when needed)
- **Security**: Validate and sanitize Steam ID input to prevent injection attacks
- **Maintainability**: Clean separation of concerns with dedicated service for config management
- **Extensibility**: Architecture that allows adding new config fields with minimal code changes

## Technical Approach

### Database Changes

Create a new `UserConfig` entity with a one-to-one relationship to the `User` entity:

**UserConfig Entity Fields:**
- `id` (int, primary key, auto-increment)
- `user` (User, one-to-one relationship, cascade persist/remove)
- `steamId` (string, 17 chars, nullable) - The user's SteamID64
- `createdAt` (DateTimeImmutable) - When config was created
- `updatedAt` (DateTimeImmutable, nullable) - Last update timestamp

**Migration Steps:**
1. Create migration for `user_config` table
2. Add one-to-one relationship column on `user` table (or inverse side in UserConfig)
3. Ensure cascade operations are properly configured

### Service Layer

Create a dedicated `UserConfigService` to handle all config-related operations:

**Key Methods:**
- `getUserConfig(User $user): UserConfig` - Get config (create if doesn't exist)
- `setSteamId(User $user, string $steamId): void` - Set Steam ID with validation
- `getSteamId(User $user): ?string` - Get Steam ID or null if not set
- `validateSteamId(string $steamId): bool` - Validate SteamID64 format
- `getInventoryUrls(User $user): array` - Generate Steam inventory URLs if Steam ID is set

**Validation Logic:**
- Steam ID must be exactly 17 digits
- Steam ID must be numeric only (no letters, spaces, special characters)
- Steam ID should start with "7656" (standard SteamID64 prefix)

### Controllers

**New Controller: `UserSettingsController`**
- `GET /settings` - Display user settings page
- `POST /settings/steam-id` - Update Steam ID

**Update Existing Controller: `InventoryImportController`**
- Inject `UserConfigService`
- Pass Steam inventory URLs to the import template (if Steam ID is configured)

### Form/Validation

Create a Symfony Form Type for Steam ID input:
- `SteamIdType` form with:
  - TextField for Steam ID
  - Custom validator to ensure correct format
  - Help text explaining the format

### Frontend Changes

**New Template: `templates/settings/index.html.twig`**
- User settings page with Steam ID form
- Display current Steam ID (if set)
- Instructions on how to find Steam ID
- Link to Steam profile lookup tools

**Update Controller: `InventoryImportController::importForm()`**
- Check if user has configured Steam ID
- If not set: Redirect to settings page with flash message explaining Steam ID is required
- If set: Generate clickable Steam inventory URLs and pass to template

**Update Template: `templates/inventory/import.html.twig`**
- Replace hardcoded placeholder URLs with dynamic clickable links using the user's Steam ID
- Update instructions to show actual Steam ID in the URLs
- Add "Change Steam ID" link to settings page for easy access

**Styling Considerations:**
- Use existing Tailwind CSS classes from the project
- Match the current dark theme (bg-gray-900, text-white, cs2-orange accents)
- Form validation feedback (red borders for errors, green for success)

### Configuration

No environment variables needed for this feature. All configuration is stored in the database.

### Security Considerations

- Only authenticated users can access settings page (ROLE_USER required)
- Users can only edit their own configuration
- Steam ID validation prevents malicious input
- No sensitive data is stored (Steam ID is public information)

## Implementation Steps

1. **Create Database Schema**
   - Create `UserConfig` entity with proper annotations
   - Add one-to-one relationship to `User` entity
   - Generate and review migration
   - Run migration in development environment

2. **Implement UserConfigService**
   - Create service class in `src/Service/UserConfigService.php`
   - Implement Steam ID validation method
   - Implement config getter (with auto-creation if doesn't exist)
   - Implement Steam ID setter with validation
   - Implement URL generation helper method
   - Add comprehensive docblocks

3. **Create Form Type**
   - Create `SteamIdType` form in `src/Form/`
   - Add custom constraint validator for SteamID64 format
   - Add help text and labels

4. **Build UserSettingsController**
   - Create controller in `src/Controller/UserSettingsController.php`
   - Implement GET route to display settings form (accept optional `redirect` query parameter)
   - Implement POST route to handle form submission
   - After successful save: check for `redirect` parameter and redirect to that route, otherwise to dashboard
   - Add flash messages for success/error states
   - Add security annotation (IsGranted)

5. **Update InventoryImportController**
   - Inject UserConfigService
   - Check if Steam ID is configured in importForm() action
   - If not configured: redirect to settings page with flash message and redirect parameter: `?redirect=inventory_import_form`
   - If configured: fetch Steam inventory URLs and pass to template

6. **Create Settings Template**
   - Create `templates/settings/index.html.twig`
   - Build form with Tailwind styling
   - Add instructions and help text
   - Add navigation back to dashboard
   - Show current Steam ID value (if exists)

7. **Update Import Template**
   - Modify `templates/inventory/import.html.twig`
   - Replace hardcoded placeholder URLs with dynamic clickable Steam inventory URLs
   - Display user's actual Steam ID in the instructions
   - Add "Change Steam ID" link that redirects to settings page
   - Make URLs clickable for better UX (open in new tab)

8. **Add Navigation Link**
   - Update main navigation/sidebar to include "Settings" link
   - Update base template if needed

## Testing Strategy

### Unit Tests

**UserConfigServiceTest:**
- Test Steam ID validation (valid formats, invalid formats)
- Test config creation for new users
- Test config retrieval for existing users
- Test Steam ID setter
- Test URL generation

**SteamIdValidatorTest:**
- Test valid SteamID64 formats
- Test invalid formats (too short, too long, non-numeric, wrong prefix)

### Integration Tests

**UserSettingsControllerTest:**
- Test settings page renders correctly
- Test Steam ID update (valid input)
- Test Steam ID update (invalid input)
- Test unauthorized access (redirect to login)

**InventoryImportControllerTest:**
- Test import page with configured Steam ID (shows clickable links with actual Steam ID)
- Test import page without configured Steam ID (redirects to settings page with flash message)

### Manual Testing Checklist

- [ ] Create new user account and verify UserConfig is automatically created
- [ ] Navigate to settings page and verify form renders
- [ ] Submit valid Steam ID and verify success message
- [ ] Submit invalid Steam ID formats and verify error messages
- [ ] Navigate to import page without configured Steam ID and verify redirect to settings page
- [ ] Verify flash message appears explaining Steam ID is required for import
- [ ] Verify URL includes redirect parameter: `/settings?redirect=inventory_import_form`
- [ ] Configure Steam ID in settings (from redirected flow) and verify automatic redirect back to import page
- [ ] Verify success message appears on import page after redirect
- [ ] Configure Steam ID directly from settings (without redirect param), verify redirect to dashboard
- [ ] Verify import page displays clickable Steam inventory URLs with actual Steam ID
- [ ] Click generated Steam inventory URLs and verify they open correct Steam pages (in new tab)
- [ ] Verify "Change Steam ID" link on import page redirects to settings
- [ ] Test with different Steam IDs to ensure URL generation is correct
- [ ] Verify Steam ID persists across sessions
- [ ] Test updating existing Steam ID to new value and verify import page updates

## Edge Cases & Error Handling

### Edge Case: User with existing data but no config
- **Solution**: UserConfigService automatically creates config on first access
- **Implementation**: Check if config exists in getUserConfig(), create if null

### Edge Case: Invalid Steam ID format submitted
- **Solution**: Form validation rejects submission with clear error message
- **Implementation**: Custom Symfony validator with descriptive error messages

### Edge Case: Steam ID field is empty (user clears it)
- **Solution**: Allow nullable Steam ID, treat as "not configured"
- **Implementation**: Set steamId to null in database; next import page access will redirect to settings

### Edge Case: User tries to access import page without Steam ID configured
- **Solution**: Redirect to settings page with informative flash message
- **Implementation**: Check for Steam ID in InventoryImportController::importForm(), redirect if null

### Edge Case: User accesses settings page while not logged in
- **Solution**: Redirect to login page
- **Implementation**: `#[IsGranted('ROLE_USER')]` attribute on controller

### Edge Case: Database constraint violations
- **Solution**: Catch exceptions and show user-friendly error message
- **Implementation**: Try-catch in controller, add flash error message

### Edge Case: Steam changes URL format in the future
- **Solution**: URL generation is centralized in service, easy to update
- **Implementation**: All URL generation happens in UserConfigService

## Dependencies

### Internal Dependencies
- None - this is a standalone feature

### External Dependencies
- No new external dependencies required
- Uses existing Symfony components (Form, Validator, Doctrine)

### Blocking Issues
- None identified

## Acceptance Criteria

- [x] UserConfig entity exists with one-to-one relationship to User
- [x] Migration successfully creates user_config table
- [x] UserConfigService implements Steam ID validation (17 digits, numeric, starts with 7656)
- [x] UserConfigService automatically creates config for new users
- [x] Settings page accessible at /settings route (authenticated users only)
- [x] Settings page displays form to input/update Steam ID
- [x] Form validates Steam ID format and shows clear error messages
- [x] Successful Steam ID update shows success flash message
- [x] Settings page shows current Steam ID value if already configured
- [x] Inventory import controller checks for configured Steam ID before rendering page
- [x] When Steam ID is not configured, user is redirected to settings with explanatory flash message
- [x] Redirect from import page includes return URL parameter (`?redirect=inventory_import_form`)
- [x] After saving Steam ID from redirected flow, user is automatically returned to import page
- [x] When Steam ID is configured, import page displays clickable Steam inventory URLs
- [x] Import page includes "Change Steam ID" link to settings page
- [x] Generated Steam URLs are correct and functional:
  - Tradeable: `https://steamcommunity.com/inventory/{STEAMID}/730/2`
  - Trade-locked: `https://steamcommunity.com/inventory/{STEAMID}/730/16`
- [x] Navigation includes link to Settings page
- [x] Unit tests cover validation logic and service methods
- [x] Integration tests verify controller behavior
- [x] Manual testing confirms all scenarios work correctly
- [x] Code follows Symfony and project conventions
- [x] Database constraints properly handle cascade operations

## Notes & Considerations

### Future Extensibility

This architecture is designed to easily support additional configuration fields. To add new settings in the future:

1. Add new column to `user_config` table (create migration)
2. Add property to `UserConfig` entity with getter/setter
3. Add methods to `UserConfigService` as needed
4. Update settings form and template

**Potential Future Config Fields:**
- `discordWebhookUrl` - Personal Discord webhook for notifications
- `notificationPreferences` - JSON field for granular alert settings
- `itemsPerPage` - Display preference for inventory pagination
- `defaultSortOrder` - User's preferred sorting for inventory items
- `theme` - Light/dark mode preference
- `timezone` - User's timezone for timestamp display
- `tradeUrl` - Steam trade URL for quick access

### Alternative Approaches Considered

**Approach 1: Add steamId directly to User entity**
- ❌ Rejected: Would require User entity modification for every new config field
- ❌ Rejected: Mixes authentication data with user preferences

**Approach 2: JSON blob storage for all config**
- ❌ Rejected: Harder to query specific fields
- ❌ Rejected: No database-level validation
- ✅ Could be used for truly flexible/dynamic settings in the future

**Approach 3: Separate table per config type**
- ❌ Rejected: Over-engineering for current needs
- ❌ Rejected: Creates too many tables for simple settings

**Selected Approach: Dedicated UserConfig entity with structured fields**
- ✅ Clean separation of concerns
- ✅ Easy to add new fields with type safety
- ✅ Queryable and indexable
- ✅ Supports validation and constraints
- ✅ One-to-one relationship is performant and intuitive

### Performance Considerations

- Consider eager loading UserConfig when User is fetched (if config is frequently accessed)
- UserConfigService creates config lazily (only when first accessed)
- No N+1 query concerns since it's one-to-one relationship

### User Experience Considerations

- **Redirect Flow**: When user is redirected from import page to settings (due to missing Steam ID), after successfully saving their Steam ID, redirect them back to the import page automatically
- **Implementation**: Use query parameter `?redirect=inventory_import` to track where user came from, then redirect to that route after successful save
- **Flash Message**: Show success message on import page after redirect: "Steam ID configured successfully! You can now import your inventory."

### Code Style Notes

- Follow existing Symfony conventions in the codebase
- Use typed properties and return types (PHP 8.4)
- Use dependency injection for all services
- Add comprehensive PHPDoc blocks
- Use Symfony attributes (not annotations)

## Related Tasks

- **Task 2.x**: Storage Box Management (separate feature, no dependency)
- **Future**: Discord notification configuration (will extend this system)
- **Future**: API key management (will extend this system)
