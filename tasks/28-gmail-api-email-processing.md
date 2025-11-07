# Task 28: Gmail API Service & Email Processing

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-07

## Overview

Build the service layer that connects to Gmail API, searches for Steam Wallet funding emails, extracts amount and currency information, and tracks processed message IDs. This service will be used by the UI and can be called multiple times without creating duplicate entries.

## Problem Statement

After users connect their Gmail account (Task 27), the system needs to:
- Search Gmail for emails from Steam Support about wallet funding
- Parse email content to extract amount and currency (e.g., "CDN$ 100.00")
- Track which emails have been processed to prevent duplicates
- Handle various email formats and edge cases
- Return structured data about found transactions

## Requirements

### Functional Requirements
- Search Gmail for emails matching criteria:
  - From: `noreply@steampowered.com`
  - Subject contains: "successfully added funds to your Steam Wallet"
- Extract transaction data from email body:
  - Amount (numeric value)
  - Currency code (USD, CAD, etc.)
- Track processed Gmail message IDs to prevent duplicate imports
- Support multiple email formats and currency symbols
- Return list of found transactions with metadata (date, amount, currency, message ID)
- Handle pagination if user has many matching emails
- Idempotent operation (can be called multiple times safely)

### Non-Functional Requirements
- Efficient Gmail API usage (batch requests, pagination)
- Robust parsing (handle various email formats)
- Error handling for API failures
- Logging of processing attempts
- Performance: process emails in reasonable time
- Memory efficient (don't load all emails at once)

## Technical Approach

### Database Changes

**New Entity: ProcessedGmailMessage**

```php
namespace App\Entity;

use App\Repository\ProcessedGmailMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProcessedGmailMessageRepository::class)]
#[ORM\Table(name: 'processed_gmail_message')]
#[ORM\Index(columns: ['user_id', 'message_id'], name: 'idx_user_message')]
#[ORM\UniqueConstraint(name: 'unique_user_message', columns: ['user_id', 'message_id'])]
class ProcessedGmailMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $messageId = null; // Gmail message ID

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(type: 'string', length: 3)]
    private ?string $currency = null; // USD, CAD, etc.

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $amount = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $emailDate = null; // When email was sent

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ledgerEntryId = null; // Reference to created LedgerEntry (optional, added in Task 29)

    // Getters and setters...
}
```

**Migration**: Create table `processed_gmail_message` with unique constraint on (user_id, message_id).

### Service Layer

**New Service: GmailApiService**

Located at: `src/Service/GmailApiService.php`

Dependencies:
- GmailAuthService (to get valid access token)
- EntityManager
- Logger

Methods:
- `searchSteamWalletEmails(User $user, int $maxResults = 100): array` - Search and parse emails
- `isMessageProcessed(User $user, string $messageId): bool` - Check if already processed
- `markMessageProcessed(User $user, string $messageId, array $data): void` - Save processed message
- `getProcessedMessages(User $user): array` - Get list of processed messages for user

**Gmail API Integration**:
```php
// Use Google API Client library
private function getGmailClient(User $user): \Google_Service_Gmail
{
    $accessToken = $this->gmailAuthService->getValidAccessToken($user);

    if (!$accessToken) {
        throw new \Exception('Gmail not connected');
    }

    $client = new \Google_Client();
    $client->setAccessToken($accessToken);

    return new \Google_Service_Gmail($client);
}

public function searchSteamWalletEmails(User $user, int $maxResults = 100): array
{
    $gmail = $this->getGmailClient($user);

    // Search query
    $query = 'from:noreply@steampowered.com subject:"successfully added funds to your Steam Wallet"';

    $messages = $gmail->users_messages->listUsersMessages('me', [
        'q' => $query,
        'maxResults' => $maxResults,
    ]);

    $transactions = [];

    foreach ($messages->getMessages() as $message) {
        $messageId = $message->getId();

        // Skip if already processed
        if ($this->isMessageProcessed($user, $messageId)) {
            continue;
        }

        // Get full message details
        $fullMessage = $gmail->users_messages->get('me', $messageId);

        // Parse email
        $parsed = $this->parseEmail($fullMessage);

        if ($parsed) {
            $transactions[] = [
                'messageId' => $messageId,
                'date' => $parsed['date'],
                'amount' => $parsed['amount'],
                'currency' => $parsed['currency'],
                'emailSnippet' => $fullMessage->getSnippet(),
            ];
        }
    }

    return $transactions;
}
```

**Email Parser**:
```php
private function parseEmail(\Google_Service_Gmail_Message $message): ?array
{
    // Get email body
    $body = $this->getEmailBody($message);

    // Get email date
    $date = $this->getEmailDate($message);

    // Parse amount and currency from body
    // Example patterns:
    // "CDN$ 100.00 has been added to your Steam Wallet."
    // "USD $50.00 has been added to your Steam Wallet."
    // "$25.00 has been added to your Steam Wallet." (assume USD)

    $patterns = [
        '/CDN?\$\s*([\d,]+\.?\d*)/i',  // CDN$ or CD$
        '/CAD\$?\s*([\d,]+\.?\d*)/i',  // CAD or CAD$
        '/USD?\$?\s*([\d,]+\.?\d*)/i', // USD, US$, or USD$
        '/\$\s*([\d,]+\.?\d*)/',        // Just $ (assume USD)
    ];

    $currency = null;
    $amount = null;

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $body, $matches)) {
            $amount = str_replace(',', '', $matches[1]);

            // Determine currency from pattern
            if (stripos($pattern, 'CDN') !== false || stripos($pattern, 'CAD') !== false) {
                $currency = 'CAD';
            } else {
                $currency = 'USD';
            }

            break;
        }
    }

    if ($amount === null || $currency === null) {
        $this->logger->warning('Could not parse Steam wallet email', [
            'messageId' => $message->getId(),
            'snippet' => $message->getSnippet(),
        ]);
        return null;
    }

    return [
        'date' => $date,
        'amount' => $amount,
        'currency' => $currency,
    ];
}

private function getEmailBody(\Google_Service_Gmail_Message $message): string
{
    // Gmail API returns body in parts, need to decode
    $payload = $message->getPayload();

    // Try to get body from payload
    if ($payload->getBody()->getSize() > 0) {
        return base64_decode(strtr($payload->getBody()->getData(), '-_', '+/'));
    }

    // Try parts
    foreach ($payload->getParts() as $part) {
        if ($part->getMimeType() === 'text/plain' || $part->getMimeType() === 'text/html') {
            if ($part->getBody()->getSize() > 0) {
                return base64_decode(strtr($part->getBody()->getData(), '-_', '+/'));
            }
        }
    }

    return '';
}

private function getEmailDate(\Google_Service_Gmail_Message $message): \DateTimeImmutable
{
    // Gmail internal date is Unix timestamp in milliseconds
    $internalDate = $message->getInternalDate();
    $timestamp = (int)($internalDate / 1000);

    return \DateTimeImmutable::createFromFormat('U', (string)$timestamp);
}
```

### Repository Layer

**ProcessedGmailMessageRepository**
- `findByUserAndMessageId(User $user, string $messageId): ?ProcessedGmailMessage`
- `findByUser(User $user, array $orderBy = []): array`
- `existsByUserAndMessageId(User $user, string $messageId): bool`

### Configuration

**Install Google API Client**:
```bash
docker compose run --rm php composer require google/apiclient:^2.0
```

**No new environment variables** (OAuth credentials already set in Task 27)

## Implementation Steps

1. **Install Google API Client Library**
   - Run: `docker compose run --rm php composer require google/apiclient:^2.0`
   - Verify installation

2. **Create ProcessedGmailMessage Entity**
   - Create `src/Entity/ProcessedGmailMessage.php` with all fields
   - Add unique constraint on (user_id, message_id)
   - Add getters/setters, constructor

3. **Create Database Migration**
   - Run: `docker compose exec php php bin/console make:migration`
   - Review migration file (verify unique constraint)
   - Run: `docker compose exec php php bin/console doctrine:migrations:migrate`

4. **Create ProcessedGmailMessageRepository**
   - Create `src/Repository/ProcessedGmailMessageRepository.php`
   - Implement query methods

5. **Create GmailApiService**
   - Create `src/Service/GmailApiService.php`
   - Inject dependencies (GmailAuthService, EntityManager, LoggerInterface)
   - Implement `getGmailClient()` method
   - Implement `searchSteamWalletEmails()` method
   - Implement `parseEmail()` method with regex patterns
   - Implement `getEmailBody()` and `getEmailDate()` helper methods
   - Implement `isMessageProcessed()` method
   - Implement `markMessageProcessed()` method
   - Implement `getProcessedMessages()` method
   - Add comprehensive error handling and logging

6. **Test Email Parsing**
   - Create test cases for various email formats
   - Test patterns:
     - "CDN$ 100.00 has been added..."
     - "CAD$ 50.00 has been added..."
     - "USD $25.00 has been added..."
     - "$10.00 has been added..." (default to USD)
     - With thousands separator: "$1,000.00"
     - Different whitespace variations
   - Handle edge cases (malformed emails)

7. **Test Gmail API Integration**
   - Test with real Gmail account that has Steam emails
   - Verify search query returns correct emails
   - Verify email body extraction works
   - Verify date extraction works
   - Verify pagination (if >100 emails)

8. **Test Duplicate Prevention**
   - Process same email twice
   - Verify second attempt skips already-processed message
   - Verify unique constraint prevents duplicate DB entries

9. **Add Console Command for Testing**
   - Create `app:gmail:test-sync` command
   - Takes user ID as argument
   - Searches emails and displays found transactions
   - Useful for debugging and manual testing
   - Does NOT create ledger entries (that's Task 29)

## Edge Cases & Error Handling

- **Gmail not connected**: Throw clear exception, UI will handle in Task 29
- **Access token expired**: Refresh automatically via GmailAuthService
- **Gmail API rate limit**: Handle gracefully, implement exponential backoff
- **Gmail API error**: Log error, throw exception with user-friendly message
- **Unparseable email body**: Log warning, skip email, continue processing others
- **Email body encoding issues**: Handle various encodings (UTF-8, ISO-8859-1)
- **Multiple currency mentions in one email**: Take first match only
- **No matching emails found**: Return empty array (not an error)
- **Very large email body**: Limit body size read (Gmail API handles this)
- **Malformed Gmail message**: Catch exceptions, log, skip message
- **Network timeout**: Implement timeout on Gmail API calls
- **Duplicate message ID**: Database unique constraint prevents duplicates
- **User processes emails then disconnects Gmail**: Processed messages remain in DB
- **Email in non-English language**: Regex patterns might fail, log and skip
- **Future email formats**: Parser might fail, but system continues
- **Partial email content**: If body extraction fails, skip email

## Dependencies

### Blocking Dependencies
- **Task 27**: Gmail OAuth & Token Storage (MUST be completed first)

### Related Tasks
- **Task 29**: Ledger Integration UI & Manual Sync (depends on Task 27 and 28)

### External Dependencies
- Google API Client Library (`google/apiclient`)
- Gmail API (external service)
- Task 27 entities and services (GmailToken, GmailAuthService)

## Acceptance Criteria

- [ ] Google API Client Library installed
- [ ] ProcessedGmailMessage entity created with unique constraint
- [ ] Database migration created and successfully applied
- [ ] ProcessedGmailMessageRepository created with query methods
- [ ] GmailApiService created with all methods implemented
- [ ] Gmail API client initialization works with OAuth tokens
- [ ] Email search query returns correct Steam wallet emails
- [ ] Email body extraction works (handles various Gmail message structures)
- [ ] Email date extraction works
- [ ] Amount and currency parsing works for format: "CDN$ 100.00"
- [ ] Amount and currency parsing works for format: "USD $50.00"
- [ ] Amount and currency parsing works for format: "$25.00" (defaults to USD)
- [ ] Amounts with thousands separators parsed correctly ("$1,000.00")
- [ ] Duplicate messages detected and skipped
- [ ] Database unique constraint prevents duplicate entries
- [ ] Unparseable emails logged and skipped (don't break entire process)
- [ ] Gmail API errors handled gracefully
- [ ] Access token refresh triggered automatically when needed
- [ ] Console command `app:gmail:test-sync` created for testing
- [ ] Console command displays found transactions without creating ledger entries
- [ ] Processed messages stored in database with all metadata
- [ ] Manual verification: Connect Gmail, run test sync, verify transactions found
- [ ] Manual verification: Run sync twice, verify no duplicates created
- [ ] Manual verification: Test with various email formats in test Gmail account

## Notes & Considerations

- **Read-only access**: We only read emails, never modify or delete them
- **Gmail API quotas**: Default quota is 1 billion quota units per day. Reading messages is very cheap. We won't hit limits.
- **Pagination**: Gmail API returns max 100 messages per request. If user has >100 Steam wallet emails, we'll need to paginate. For MVP, 100 is sufficient.
- **Email search accuracy**: Gmail's search query language is powerful. Our query should be very accurate.
- **Currency symbols**: Different formats exist (CDN$, CAD$, C$). Our regex patterns handle variations.
- **Default currency**: If no currency indicator found, default to USD (most common)
- **Historical emails**: This will find ALL matching emails, including very old ones. Users can choose which to import in Task 29.
- **Email retention**: Gmail typically stores emails indefinitely unless user deletes them
- **Parsing robustness**: If Steam changes email format, parser might break. Logging helps us detect this.
- **Performance**: Processing 100 emails should take a few seconds. Acceptable for manual sync.
- **Idempotency**: Service can be called multiple times safely. Already-processed messages are skipped.
- **No automatic sync**: This task provides the service. Task 29 adds the UI trigger. Future task could add cron-based automation.
- **Error logging**: Comprehensive logging helps debug issues with email parsing or API calls
- **Testing with real data**: Need to create test Gmail account with actual Steam wallet emails for thorough testing

## Related Tasks

- **Task 27**: Gmail OAuth & Token Storage (blocking - must be done first)
- **Task 28**: Ledger Integration UI & Manual Sync (depends on this task)
- **Task 25**: Ledger Backend Foundation (parallel - provides LedgerEntry entity)
- **Task 26**: Ledger Frontend with Sorting/Filtering (parallel - not directly related)
