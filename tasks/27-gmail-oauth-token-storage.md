# Task 27: Gmail OAuth & Token Storage (Foundation)

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-07

## Overview

Set up Google OAuth2 authentication to allow users to connect their Gmail accounts with read-only email access. Store OAuth tokens per user in the database to enable automated email syncing. This is the foundation for the Gmail integration feature that will extract Steam Wallet funding information from emails.

## Problem Statement

Users need a way to authorize this application to read their Gmail inbox (with minimal permissions) to extract Steam Wallet funding emails. The system needs to:
- Implement OAuth2 authorization flow with Google
- Request only `gmail.readonly` scope (read-only access)
- Store OAuth refresh tokens securely per user
- Handle token refresh automatically when access tokens expire
- Allow users to disconnect their Gmail account

## Requirements

### Functional Requirements
- Google OAuth2 integration with read-only Gmail access
- Per-user OAuth token storage (each user connects their own Gmail)
- Store: access token, refresh token, expiry time, email address
- Ability to check if user has connected Gmail
- Ability to disconnect Gmail (revoke and delete tokens)
- Automatic token refresh when access token expires
- Settings page section showing Gmail connection status

### Non-Functional Requirements
- Secure token storage (tokens encrypted or protected)
- OAuth2 security best practices (state parameter, PKCE if needed)
- Handle OAuth errors gracefully (denied access, expired tokens)
- Rate limiting on OAuth endpoints to prevent abuse
- Audit trail of when users connect/disconnect Gmail

### Security Requirements
- Request minimal Gmail scope: `https://www.googleapis.com/auth/gmail.readonly`
- Store tokens per user (never share tokens between users)
- CSRF protection on OAuth callback
- Validate OAuth state parameter
- Revoke tokens on disconnect
- Environment variables for OAuth credentials (never in code)

## Technical Approach

### Database Changes

**New Entity: GmailToken**

```php
namespace App\Entity;

use App\Repository\GmailTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GmailTokenRepository::class)]
#[ORM\Table(name: 'gmail_token')]
class GmailToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
    private ?User $user = null;

    #[ORM\Column(type: 'text')]
    private ?string $accessToken = null;

    #[ORM\Column(type: 'text')]
    private ?string $refreshToken = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $gmailEmail = null; // Store which Gmail account is connected

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $scope = null; // Store granted scope for verification

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $connectedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    // Getters and setters...
}
```

**Migration**: Create table `gmail_token` with one-to-one relationship to User.

**Update User Entity**: Add relationship to GmailToken (one-to-one)

### Configuration

**Environment Variables (.env)**:
```
GOOGLE_OAUTH_CLIENT_ID=your-client-id-here.apps.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=your-client-secret-here
GOOGLE_OAUTH_REDIRECT_URI=${APP_URL}/settings/gmail/callback
```

**Symfony Configuration (config/packages/knpu_oauth2_client.yaml)**:
```yaml
knpu_oauth2_client:
    clients:
        google:
            type: google
            client_id: '%env(GOOGLE_OAUTH_CLIENT_ID)%'
            client_secret: '%env(GOOGLE_OAUTH_CLIENT_SECRET)%'
            redirect_route: app_gmail_oauth_callback
            redirect_params: {}
```

### Service Layer

**New Service: GmailAuthService**

Located at: `src/Service/GmailAuthService.php`

Methods:
- `getAuthorizationUrl(User $user): string` - Generate OAuth URL with state
- `handleCallback(string $code, User $user): void` - Exchange code for tokens, store in DB
- `getValidAccessToken(User $user): ?string` - Get access token, refresh if expired
- `refreshAccessToken(GmailToken $token): void` - Refresh expired access token
- `disconnect(User $user): void` - Revoke tokens and delete from DB
- `isConnected(User $user): bool` - Check if user has valid Gmail connection
- `getConnectedEmail(User $user): ?string` - Get Gmail address if connected

Security:
- Generate secure random state parameter, store in session
- Validate state on callback to prevent CSRF
- Store tokens immediately after receiving them
- Update lastUsedAt whenever tokens are used

### Repository Layer

**GmailTokenRepository**
- `findByUser(User $user): ?GmailToken` - Get token for user
- Standard Doctrine repository methods

### Controller Layer

**New Controller: GmailOAuthController**

Route prefix: `/settings/gmail`

Actions:
1. `connect()` [GET /settings/gmail/connect] - Redirect to Google OAuth
2. `callback()` [GET /settings/gmail/callback] - Handle OAuth callback
3. `disconnect()` [POST /settings/gmail/disconnect] - Revoke and delete tokens

**Update SettingsController**:
- Show Gmail connection status
- Show connected Gmail address if connected
- Provide "Connect Gmail" or "Disconnect Gmail" button

### Frontend Changes

**Update templates/settings/index.html.twig**:

Add new section: "Gmail Integration"
- If not connected:
  - Show message: "Connect your Gmail account to import Steam Wallet funding emails"
  - Button: "Connect Gmail Account" → links to `/settings/gmail/connect`
  - Privacy note: "We only request read-only access to your emails"
- If connected:
  - Show: "Gmail connected: user@gmail.com"
  - Show: "Connected on: [date]"
  - Show: "Last used: [date]" (or "Never used" if null)
  - Button: "Disconnect Gmail" → POST to `/settings/gmail/disconnect` with CSRF
  - Warning text: "Disconnecting will prevent automatic Steam email imports"

## Implementation Steps

1. **Install OAuth Client Bundle**
   - Run: `docker compose run --rm php composer require knpuniversity/oauth2-client-bundle league/oauth2-google`
   - Configure bundle in `config/packages/knpu_oauth2_client.yaml`

2. **Create Environment Variables**
   - Add `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`, `GOOGLE_OAUTH_REDIRECT_URI` to `.env`
   - Add same variables to `.env.example` with placeholder values
   - Document how to get credentials from Google Cloud Console

3. **Create GmailToken Entity**
   - Create `src/Entity/GmailToken.php` with all fields
   - Add getters/setters, constructor

4. **Update User Entity**
   - Add one-to-one relationship to GmailToken
   - Add methods: `getGmailToken()`, `setGmailToken()`

5. **Create Database Migration**
   - Run: `docker compose exec php php bin/console make:migration`
   - Review migration file
   - Run: `docker compose exec php php bin/console doctrine:migrations:migrate`

6. **Create GmailTokenRepository**
   - Create `src/Repository/GmailTokenRepository.php`
   - Implement `findByUser()` method

7. **Create GmailAuthService**
   - Create `src/Service/GmailAuthService.php`
   - Inject OAuth2 client and entity manager
   - Implement all methods listed above
   - Add proper error handling and logging
   - Request scope: `https://www.googleapis.com/auth/gmail.readonly`

8. **Create GmailOAuthController**
   - Create `src/Controller/GmailOAuthController.php`
   - Implement `connect()` action (redirect to Google)
   - Implement `callback()` action (handle OAuth response)
   - Implement `disconnect()` action (revoke and delete)
   - Add security checks and flash messages

9. **Update Settings Template**
   - Add Gmail integration section to `templates/settings/index.html.twig`
   - Show connection status and actions
   - Style with existing Tailwind patterns
   - Add informational text about read-only access

10. **Create Google Cloud Project**
    - Document steps to create OAuth2 credentials in Google Cloud Console
    - Add to PRODUCTION.md or separate GMAIL_SETUP.md guide
    - Include required scopes and redirect URI configuration

11. **Test OAuth Flow**
    - Test connect flow (authorization URL generation)
    - Test callback handling (token exchange and storage)
    - Test token refresh (manually expire token and trigger refresh)
    - Test disconnect flow (token revocation)
    - Test error cases (denied access, invalid state, expired callback)

## Edge Cases & Error Handling

- **User denies OAuth permission**: Show error message, redirect to settings
- **Invalid state parameter**: Reject callback, show error, log security event
- **OAuth callback error**: Display user-friendly message, log technical details
- **Token refresh fails**: Mark token as invalid, prompt user to reconnect
- **Google API rate limit**: Handle gracefully, show error to user
- **User already connected**: Show message "Already connected to Gmail"
- **Concurrent connection attempts**: Use database unique constraint to prevent duplicate tokens
- **Token revocation fails**: Delete token from DB anyway (best effort)
- **Missing environment variables**: Show clear error message in dev, fail gracefully in prod
- **Expired refresh token**: Prompt user to reconnect (refresh tokens can expire after 6 months of non-use)
- **Different Gmail account**: Allow reconnecting with different account (replace existing token)
- **Google API service outage**: Show appropriate error message, retry later

## Dependencies

### Blocking Dependencies
None - this task can start immediately. Tasks 25 and 26 can be in progress or completed, but not blocking.

### Related Tasks
- **Task 28**: Gmail API Service & Email Processing (depends on Task 27)
- **Task 29**: Ledger Integration UI & Manual Sync (depends on Task 27 and 28)

### External Dependencies
- Google OAuth2 API
- KnpU OAuth2 Client Bundle (`knpuniversity/oauth2-client-bundle`)
- League OAuth2 Google Provider (`league/oauth2-google`)
- Google Cloud Console project with OAuth2 credentials

## Acceptance Criteria

- [ ] KnpU OAuth2 Client Bundle installed and configured
- [ ] GmailToken entity created with all required fields
- [ ] Database migration created and successfully applied
- [ ] User entity has one-to-one relationship to GmailToken
- [ ] GmailTokenRepository created with findByUser() method
- [ ] GmailAuthService created with all methods implemented
- [ ] GmailOAuthController created with connect/callback/disconnect actions
- [ ] Settings page shows Gmail connection status section
- [ ] "Connect Gmail" button redirects to Google OAuth
- [ ] OAuth callback handles successful authorization
- [ ] Tokens stored in database after successful authorization
- [ ] Gmail email address saved with tokens
- [ ] Connected timestamp saved
- [ ] "Disconnect Gmail" button revokes and deletes tokens
- [ ] OAuth state parameter validated to prevent CSRF
- [ ] Only `gmail.readonly` scope requested
- [ ] Access token refresh works when token expires
- [ ] Flash messages shown for success/error states
- [ ] Environment variables documented in .env.example
- [ ] Google Cloud Console setup documented
- [ ] User can only have one Gmail connection at a time
- [ ] Attempting to access another user's tokens returns 403
- [ ] Manual verification: Connect Gmail, verify tokens stored
- [ ] Manual verification: Force token refresh, verify new token obtained
- [ ] Manual verification: Disconnect Gmail, verify tokens deleted
- [ ] Manual verification: Test error cases (denied access, invalid state)

## Notes & Considerations

- **OAuth2 vs API Key**: OAuth2 is required for accessing user's Gmail. API keys won't work for this use case.
- **Scope minimization**: Only request `gmail.readonly` scope, which is the minimum needed to read emails.
- **Token storage**: Tokens stored in database. Consider encrypting tokens at rest in future enhancement.
- **Token expiry**: Access tokens expire after 1 hour. Refresh tokens used to get new access tokens automatically.
- **Refresh token expiry**: Google refresh tokens can expire after 6 months of non-use. User must reconnect if this happens.
- **Production setup**: Requires creating OAuth2 credentials in Google Cloud Console (client ID and secret).
- **Redirect URI**: Must be registered in Google Cloud Console. Use `${APP_URL}/settings/gmail/callback`.
- **Testing in development**: Google allows `localhost` redirect URIs for development.
- **Google verification**: If app is not verified by Google, users will see "unverified app" warning. For private use, this is acceptable.
- **Rate limits**: Google Gmail API has rate limits. This won't be an issue for reading a few emails per sync.
- **Revocation**: When user disconnects, we call Google's revocation endpoint as best practice, but always delete from DB.
- **Session state**: OAuth state parameter stored in session for CSRF protection.
- **One token per user**: Database unique constraint ensures only one Gmail connection per user.
- **Reconnecting**: User can disconnect and reconnect with same or different Gmail account.

## Related Tasks

- **Task 25**: Ledger Backend Foundation (parallel - not blocking)
- **Task 26**: Ledger Frontend with Sorting/Filtering (parallel - not blocking)
- **Task 28**: Gmail API Service & Email Processing (depends on Task 27) - Reads emails and extracts data
- **Task 29**: Ledger Integration UI & Manual Sync (depends on Task 27 and 28) - Creates ledger entries and UI
