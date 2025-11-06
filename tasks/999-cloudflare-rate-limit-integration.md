# Cloudflare Rate Limiting Integration

**Status**: Backlog
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-05

## Overview

Integrate Cloudflare security features with the application's login rate limiting system to provide an additional layer of protection against brute force attacks. When the application's rate limiter triggers, signal Cloudflare to present security challenges (CAPTCHA/Turnstile) to users.

## Problem Statement

Currently, the application has rate limiting (3 failed attempts per 5 minutes per IP), but:
- Attackers can still make multiple requests that hit the server before being blocked
- No visual security challenge (CAPTCHA) is presented to suspicious users
- Cloudflare's security features aren't leveraged to reduce server load
- Distributed attacks across multiple IPs aren't caught by per-IP rate limiting

Integrating with Cloudflare will:
- Reduce server load by blocking attacks at the edge
- Present security challenges to suspicious users
- Catch distributed brute force attacks
- Provide better user experience with Cloudflare Turnstile

## Requirements

### Functional Requirements
- Add custom response headers when rate limit is triggered
- Configure Cloudflare WAF rules to trigger on these headers
- Implement Cloudflare Turnstile (modern CAPTCHA) on login form after N failed attempts
- Set up complementary Cloudflare Rate Limiting Rules for `/login` endpoint
- Ensure legitimate users can still access the site after solving challenges

### Non-Functional Requirements
- Minimal changes to existing rate limiting implementation
- Cloudflare integration should be optional (app works without it)
- Clear documentation for configuring Cloudflare dashboard
- No performance degradation from additional checks

## Technical Approach

### Layered Security Strategy

Implement multiple layers of protection:

1. **Cloudflare Rate Limiting** (First layer - at edge)
   - Blocks obvious attacks before reaching server
   - 5 requests per 5 minutes per IP on `/login` POST

2. **Application Rate Limiting** (Second layer - existing)
   - 3 failed attempts per 5 minutes per IP
   - More granular control

3. **Cloudflare Challenge Response** (Third layer - adaptive)
   - Triggered by custom headers from application
   - Shows CAPTCHA/Turnstile when app rate limits

### Implementation Options

#### Option 1: Custom Response Headers (Recommended - Easy)

**Backend Changes**:
Modify `src/EventSubscriber/LoginRateLimitSubscriber.php`:

```php
public function onCheckPassport(CheckPassportEvent $event): void
{
    // ... existing code ...

    if (!$limiter->consume(0)->isAccepted()) {
        $limit = $limiter->consume(0);
        $retryAfter = $limit->getRetryAfter();
        $minutes = $retryAfter ? ceil($retryAfter->getTimestamp() - time()) / 60 : 5;

        $exception = new TooManyRequestsHttpException(
            $retryAfter?->getTimestamp() - time(),
            sprintf('Too many failed login attempts. Please try again in %d minute(s).', (int) ceil($minutes))
        );

        // Add custom headers for Cloudflare integration
        $exception->getHeaders()['X-Rate-Limit-Exceeded'] = 'login';
        $exception->getHeaders()['X-Suspicious-Activity'] = 'brute-force-attempt';
        $exception->getHeaders()['Retry-After'] = (string) ($retryAfter?->getTimestamp() - time() ?? 300);

        throw $exception;
    }
}
```

**Cloudflare Dashboard Configuration**:
1. Navigate to **Security → WAF → Custom Rules**
2. Create rule:
   - Name: "Login Rate Limit Challenge"
   - Condition: `http.response.code eq 429 and http.response.headers["x-rate-limit-exceeded"][0] eq "login"`
   - Action: **Managed Challenge** (shows CAPTCHA)
   - Duration: 5 minutes

#### Option 2: Cloudflare Turnstile Widget (Better UX)

**Backend Changes**:
Add softer rate limit for triggering Turnstile in `config/packages/framework.yaml`:

```yaml
rate_limiter:
    login:
        policy: 'sliding_window'
        limit: 3
        interval: '5 minutes'

    login_turnstile:
        policy: 'sliding_window'
        limit: 2
        interval: '5 minutes'
```

**Frontend Changes**:
Update `templates/security/login.html.twig`:

```html
<!-- Add Cloudflare Turnstile after 2 failed attempts -->
{% if show_turnstile %}
<div class="cf-turnstile"
     data-sitekey="{{ cloudflare_turnstile_sitekey }}"
     data-callback="onTurnstileSuccess"
     data-theme="light"></div>

<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
{% endif %}
```

**Service Changes**:
Create `src/Service/CloudflareTurnstileValidator.php` to verify Turnstile tokens.

#### Option 3: Cloudflare Rate Limiting Rules (Complementary)

**Cloudflare Dashboard Configuration**:
1. Navigate to **Security → WAF → Rate Limiting Rules**
2. Create rule:
   - Name: "Login Endpoint Protection"
   - Match: `(http.request.uri.path eq "/login") and (http.request.method eq "POST")`
   - Requests: 5 requests per 5 minutes
   - Action: **Managed Challenge**
   - Characteristics: By IP address

This provides a second layer that catches attacks before they hit the application.

#### Option 4: Bot Score Integration (Advanced - Requires Paid Plan)

**Backend Changes**:
Use Cloudflare's bot score to adjust rate limits dynamically:

```php
public function onCheckPassport(CheckPassportEvent $event): void
{
    $request = $this->requestStack->getCurrentRequest();

    // Cloudflare Bot Score: 0-99 (0 = definitely bot, 99 = definitely human)
    $botScore = (int) $request->headers->get('cf-bot-score', 100);

    // More aggressive rate limiting for suspicious IPs
    $limit = match(true) {
        $botScore < 30 => 1,   // Only 1 attempt if likely bot
        $botScore < 60 => 2,   // 2 attempts if suspicious
        default => 3           // 3 attempts for normal users
    };

    // Use dynamic limit...
}
```

**Note**: Requires Cloudflare Bot Management (Enterprise or Bot Management add-on).

## Implementation Steps

### Phase 1: Custom Headers (Quick Win)

1. **Update LoginRateLimitSubscriber**
   - Add custom headers to TooManyRequestsHttpException
   - Headers: `X-Rate-Limit-Exceeded`, `X-Suspicious-Activity`, `Retry-After`

2. **Configure Cloudflare WAF Custom Rule**
   - Create rule to detect rate limit headers
   - Action: Managed Challenge
   - Duration: 5 minutes

3. **Test Integration**
   - Trigger rate limit with 3 failed login attempts
   - Verify Cloudflare shows challenge page
   - Verify challenge can be solved

4. **Document Configuration**
   - Add Cloudflare setup instructions to deployment docs
   - Document how to disable/adjust settings

### Phase 2: Cloudflare Rate Limiting Rules (Complementary)

1. **Create Cloudflare Rate Limiting Rule**
   - Target: `/login` POST requests
   - Limit: 5 requests per 5 minutes
   - Action: Managed Challenge

2. **Test Layered Protection**
   - Verify Cloudflare rule triggers independently
   - Verify app-level rate limiting still works
   - Test interaction between both layers

### Phase 3: Turnstile Integration (Enhanced UX)

1. **Sign Up for Cloudflare Turnstile**
   - Get site key and secret key
   - Add to environment variables

2. **Add Turnstile Widget to Login Form**
   - Update login template
   - Add JavaScript for Turnstile
   - Style widget to match design

3. **Create Turnstile Validator Service**
   - Verify Turnstile token on server
   - Integrate with authentication flow

4. **Add Softer Rate Limit**
   - Show Turnstile after 2 failed attempts
   - Keep hard block at 3 failed attempts

5. **Test Turnstile Flow**
   - Verify widget shows after 2 failures
   - Verify token validation works
   - Test user experience

## Edge Cases & Error Handling

- **Cloudflare Not Configured**: App should work normally without Cloudflare
- **Cloudflare Down**: App rate limiting still protects the application
- **False Positives**: Legitimate users can solve challenge and continue
- **Bot Score Not Available**: Fall back to standard rate limiting
- **Turnstile Token Invalid**: Show error message, don't lock out user permanently
- **Multiple Failed Turnstile Attempts**: Increase challenge difficulty or temporary IP block

## Dependencies

- Task 9: Login Rate Limiting (completed - prerequisite)
- Cloudflare account with site configured
- Cloudflare Turnstile account (free)
- Optional: Cloudflare Bot Management (paid, for bot score integration)

## Acceptance Criteria

### Phase 1 (Custom Headers)
- [ ] LoginRateLimitSubscriber adds custom headers when rate limit triggered
- [ ] Cloudflare WAF rule configured to detect headers
- [ ] Cloudflare shows Managed Challenge when rate limit triggered
- [ ] Challenge can be solved and login attempted again
- [ ] Documentation includes Cloudflare setup instructions

### Phase 2 (Rate Limiting Rules)
- [ ] Cloudflare Rate Limiting Rule created for `/login` endpoint
- [ ] Rule triggers independently from app-level rate limiting
- [ ] Both layers work together without conflicts
- [ ] Testing shows reduced server load from blocked requests

### Phase 3 (Turnstile)
- [ ] Turnstile widget appears after 2 failed login attempts
- [ ] Turnstile token validated on server before authentication
- [ ] Invalid tokens show appropriate error message
- [ ] User experience is smooth and clear
- [ ] Turnstile site key and secret stored in environment variables

### All Phases
- [ ] Application works correctly without Cloudflare configured
- [ ] Documentation updated with Cloudflare integration guide
- [ ] Manual testing shows all layers working together
- [ ] No performance degradation from additional checks

## Configuration Management

### Environment Variables

Add to `.env`:
```bash
# Cloudflare Turnstile (optional)
CLOUDFLARE_TURNSTILE_SITE_KEY=
CLOUDFLARE_TURNSTILE_SECRET_KEY=
```

### Cloudflare Dashboard Settings

Document required Cloudflare configuration in deployment guide:
- WAF Custom Rules for rate limit headers
- Rate Limiting Rules for `/login` endpoint
- Turnstile site registration

## Notes & Considerations

### Recommended Approach

For best results, implement in this order:
1. **Phase 1** (Custom Headers) - Quick, minimal code changes, immediate benefit
2. **Phase 2** (Rate Limiting Rules) - Reduces server load significantly
3. **Phase 3** (Turnstile) - Best user experience, but more development effort

### Cost Considerations

- **Cloudflare Free Plan**: Supports Custom Rules, Rate Limiting Rules, and Turnstile
- **Bot Score**: Requires Bot Management add-on or Enterprise plan
- **Rate Limiting Rules**: 10 rules on Free plan, 25 on Pro, 100 on Business

### Performance Impact

- Custom Headers: Negligible (just adds headers to existing exception)
- Cloudflare Rules: Reduces server load (blocks at edge)
- Turnstile: Minimal (JavaScript widget, async validation)
- Bot Score: None (header already sent by Cloudflare)

### Alternative: Self-Hosted CAPTCHA

If Cloudflare isn't used, consider:
- Google reCAPTCHA v3 (invisible)
- hCaptcha (privacy-focused)
- FriendlyCaptcha (GDPR-compliant)

But Cloudflare Turnstile is recommended for best integration.

## Testing Strategy

### Manual Testing

1. **Test Rate Limit Trigger**
   - Attempt 3 failed logins
   - Verify 429 response with custom headers
   - Verify Cloudflare challenge appears

2. **Test Challenge Solving**
   - Solve Cloudflare challenge
   - Verify can attempt login again
   - Verify rate limit counter persists

3. **Test Cloudflare Rate Limiting**
   - Attempt 5 POST requests to `/login` rapidly
   - Verify Cloudflare blocks at edge
   - Verify server doesn't receive excess requests

4. **Test Turnstile Widget** (Phase 3)
   - Trigger 2 failed logins
   - Verify Turnstile widget appears
   - Solve Turnstile, verify token validates
   - Complete login successfully

### Automated Testing

- Unit tests for CloudflareTurnstileValidator
- Integration tests for rate limit + Cloudflare headers
- E2E tests for login flow with Turnstile

## Security Considerations

- **Headers Validation**: Custom headers should not be user-controllable
- **Turnstile Token Validation**: Always validate server-side, never trust client
- **Rate Limit Bypass**: Ensure Cloudflare rules can't be bypassed by going direct to origin IP
  - Use Cloudflare's "Authenticated Origin Pulls" feature
- **Bot Score Spoofing**: Bot score is set by Cloudflare, can't be spoofed by client
- **Challenge Difficulty**: Cloudflare automatically adjusts based on threat level

## Related Tasks

- Task 8: Security & Controller Audit (completed)
- Task 9: Login Rate Limiting (completed - prerequisite)
- Task 10: Production Docker Configuration (Cloudflare setup during deployment)

## Future Enhancements

- Email notifications when account targeted by brute force
- Admin dashboard to view rate limit violations
- Automatic IP reputation scoring based on behavior
- Integration with fail2ban or Cloudflare Access for persistent offenders
- Rate limiting on other endpoints (password reset, registration)

## References

- [Cloudflare WAF Custom Rules](https://developers.cloudflare.com/waf/custom-rules/)
- [Cloudflare Rate Limiting](https://developers.cloudflare.com/waf/rate-limiting-rules/)
- [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/)
- [Cloudflare Bot Management](https://developers.cloudflare.com/bots/)
- [Symfony Rate Limiter](https://symfony.com/doc/current/rate_limiter.html)
