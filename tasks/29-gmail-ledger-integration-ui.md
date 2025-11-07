# Task 29: Gmail Ledger Integration UI & Manual Sync

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-07

## Overview

Create the user interface and integration logic to sync Steam Wallet funding emails to the ledger. Users click a "Sync Steam Emails" button, preview found transactions, select which to import, and confirm to create ledger entries. This ties together the OAuth system (Task 27), email processing (Task 28), and ledger system (Tasks 25-26).

## Problem Statement

After users connect Gmail (Task 27) and the system can search/parse emails (Task 28), we need a way for users to:
- Manually trigger a sync of Steam wallet emails
- Preview found transactions before importing
- Select which transactions to import (checkbox selection)
- Import selected transactions as ledger entries
- Avoid importing duplicates (even across multiple sync sessions)
- See which emails have already been processed

## Requirements

### Functional Requirements
- "Sync Steam Emails" button on ledger page (only visible if Gmail connected)
- Sync process:
  1. Search Gmail for Steam wallet emails
  2. Show preview of found transactions with selection checkboxes
  3. User selects which to import
  4. Confirm → Create ledger entries for selected transactions
  5. Mark messages as processed in database
- Transaction preview shows: date, amount, currency, email snippet
- Already-processed transactions shown separately (not selectable)
- Created ledger entries have:
  - Transaction type: "investment"
  - Category: "Steam Wallet"
  - Description: Auto-generated (e.g., "Steam Wallet funding from email on 2025-11-01")
  - Amount and currency from email
  - Transaction date: email date
- Flash messages for success/errors
- Handle case where Gmail not connected (show connect button instead)

### Non-Functional Requirements
- Two-step process (preview → confirm) to prevent accidental imports
- Clear indication of which emails are new vs already processed
- Responsive design (works on mobile)
- Transaction-safe imports (rollback on error)
- Logging of sync attempts and results
- Performance: handle previewing 100+ emails

## Technical Approach

### Service Layer

**New Service: GmailLedgerSyncService**

Located at: `src/Service/GmailLedgerSyncService.php`

Dependencies:
- GmailApiService (to search and parse emails)
- EntityManager
- Logger

Methods:
- `previewSync(User $user): array` - Get new and processed transactions for preview
- `importTransactions(User $user, array $messageIds): int` - Import selected transactions
- `createLedgerEntry(User $user, array $transactionData): LedgerEntry` - Create single entry

```php
public function previewSync(User $user): array
{
    // Search emails via GmailApiService
    $transactions = $this->gmailApiService->searchSteamWalletEmails($user);

    // Separate into new vs already processed
    $new = [];
    $processed = [];

    foreach ($transactions as $transaction) {
        if ($this->gmailApiService->isMessageProcessed($user, $transaction['messageId'])) {
            $processed[] = $transaction;
        } else {
            $new[] = $transaction;
        }
    }

    return [
        'new' => $new,
        'processed' => $processed,
        'totalFound' => count($transactions),
        'newCount' => count($new),
        'processedCount' => count($processed),
    ];
}

public function importTransactions(User $user, array $messageIds): int
{
    $imported = 0;

    foreach ($messageIds as $messageId) {
        // Get transaction data from GmailApiService (or from DB if already processed)
        $transaction = $this->getTransactionByMessageId($user, $messageId);

        if (!$transaction) {
            continue; // Skip if not found
        }

        // Check if already processed
        if ($this->gmailApiService->isMessageProcessed($user, $messageId)) {
            continue; // Skip duplicates
        }

        // Create ledger entry
        $ledgerEntry = $this->createLedgerEntry($user, $transaction);

        // Mark message as processed
        $this->gmailApiService->markMessageProcessed($user, $messageId, [
            'currency' => $transaction['currency'],
            'amount' => $transaction['amount'],
            'emailDate' => $transaction['date'],
            'ledgerEntryId' => $ledgerEntry->getId(),
        ]);

        $imported++;
    }

    $this->entityManager->flush();

    $this->logger->info('Gmail sync completed', [
        'userId' => $user->getId(),
        'imported' => $imported,
        'requested' => count($messageIds),
    ]);

    return $imported;
}

private function createLedgerEntry(User $user, array $transaction): LedgerEntry
{
    $entry = new LedgerEntry();
    $entry->setUser($user);
    $entry->setTransactionType('investment');
    $entry->setAmount($transaction['amount']);
    $entry->setCurrency($transaction['currency']);
    $entry->setTransactionDate($transaction['date']);
    $entry->setCategory('Steam Wallet');
    $entry->setDescription(sprintf(
        'Steam Wallet funding from email on %s',
        $transaction['date']->format('Y-m-d')
    ));
    $entry->setCreatedAt(new \DateTimeImmutable());

    $this->entityManager->persist($entry);

    return $entry;
}
```

### Controller Layer

**Update LedgerController**

Add new actions:

1. `syncPreview()` [GET /ledger/sync-gmail/preview]
   - Check if Gmail connected, redirect to settings if not
   - Call GmailLedgerSyncService::previewSync()
   - Render preview template with found transactions
   - Show checkboxes for new transactions
   - Show already-processed transactions (read-only)

2. `syncConfirm()` [POST /ledger/sync-gmail/confirm]
   - Receive selected message IDs from form
   - Call GmailLedgerSyncService::importTransactions()
   - Flash success message with count
   - Redirect to ledger index

```php
#[Route('/ledger/sync-gmail/preview', name: 'app_ledger_sync_gmail_preview')]
public function syncPreview(): Response
{
    $user = $this->getUser();

    // Check if Gmail connected
    if (!$this->gmailAuthService->isConnected($user)) {
        $this->addFlash('error', 'Please connect your Gmail account first.');
        return $this->redirectToRoute('app_settings');
    }

    try {
        $preview = $this->gmailLedgerSyncService->previewSync($user);
    } catch (\Exception $e) {
        $this->addFlash('error', 'Error searching Gmail: ' . $e->getMessage());
        return $this->redirectToRoute('app_ledger_index');
    }

    return $this->render('ledger/sync_preview.html.twig', [
        'preview' => $preview,
    ]);
}

#[Route('/ledger/sync-gmail/confirm', name: 'app_ledger_sync_gmail_confirm', methods: ['POST'])]
public function syncConfirm(Request $request): Response
{
    $user = $this->getUser();

    // Get selected message IDs from form
    $selectedIds = $request->request->all('message_ids') ?? [];

    if (empty($selectedIds)) {
        $this->addFlash('warning', 'No transactions selected.');
        return $this->redirectToRoute('app_ledger_sync_gmail_preview');
    }

    try {
        $imported = $this->gmailLedgerSyncService->importTransactions($user, $selectedIds);
        $this->addFlash('success', sprintf('%d transaction(s) imported successfully.', $imported));
    } catch (\Exception $e) {
        $this->addFlash('error', 'Error importing transactions: ' . $e->getMessage());
    }

    return $this->redirectToRoute('app_ledger_index');
}
```

### Frontend Changes

**Update templates/ledger/index.html.twig**

Add "Sync Steam Emails" button near "New Entry" button:
- Only show if Gmail is connected
- Link to `/ledger/sync-gmail/preview`
- Style: secondary button (not primary, since it's less common action)

```twig
{% if gmail_connected %}
    <a href="{{ path('app_ledger_sync_gmail_preview') }}" class="btn btn-secondary">
        <svg><!-- Email icon --></svg>
        Sync Steam Emails
    </a>
{% endif %}
```

**Create templates/ledger/sync_preview.html.twig**

Structure:
1. Page header: "Import Steam Wallet Transactions"
2. Summary section:
   - "Found X new transactions"
   - "X already imported (shown below)"
3. New transactions section:
   - Form with checkboxes (POST to `/ledger/sync-gmail/confirm`)
   - "Select All" / "Deselect All" buttons (JavaScript optional)
   - Table/cards with: checkbox, date, amount, currency, email snippet
   - Submit button: "Import Selected" (bottom of form)
   - Cancel link: back to ledger
4. Already processed section:
   - Read-only list (no checkboxes)
   - Show same info: date, amount, currency
   - Indication: "Already imported on [date]"

Visual design:
- Use existing Tailwind patterns
- Green/positive styling for new transactions
- Gray/muted styling for already processed
- Responsive cards on mobile, table on desktop
- Clear visual separation between new and processed

**Example structure:**
```twig
<form method="post" action="{{ path('app_ledger_sync_gmail_confirm') }}">
    <input type="hidden" name="_token" value="{{ csrf_token('sync_gmail') }}">

    {% if preview.newCount > 0 %}
        <h3>New Transactions ({{ preview.newCount }})</h3>
        <div class="transaction-list">
            {% for transaction in preview.new %}
                <div class="transaction-item">
                    <input type="checkbox" name="message_ids[]" value="{{ transaction.messageId }}" id="msg_{{ transaction.messageId }}" checked>
                    <label for="msg_{{ transaction.messageId }}">
                        <strong>{{ transaction.date|date('Y-m-d') }}</strong>
                        {{ transaction.currency }} {{ transaction.amount|number_format(2) }}
                        <small>{{ transaction.emailSnippet }}</small>
                    </label>
                </div>
            {% endfor %}
        </div>
        <button type="submit" class="btn btn-primary">Import Selected</button>
    {% else %}
        <p>No new transactions found.</p>
    {% endif %}

    {% if preview.processedCount > 0 %}
        <h3>Already Imported ({{ preview.processedCount }})</h3>
        <div class="processed-list">
            {% for transaction in preview.processed %}
                <div class="transaction-item processed">
                    {{ transaction.date|date('Y-m-d') }}
                    {{ transaction.currency }} {{ transaction.amount|number_format(2) }}
                </div>
            {% endfor %}
        </div>
    {% endif %}
</form>
```

### Update ProcessedGmailMessage Entity

Add `ledgerEntryId` field (if not already added in Task 28):
```php
#[ORM\Column(type: 'integer', nullable: true)]
private ?int $ledgerEntryId = null;
```

This allows tracking which ledger entry was created from which email.

## Implementation Steps

1. **Create GmailLedgerSyncService**
   - Create `src/Service/GmailLedgerSyncService.php`
   - Inject dependencies (GmailApiService, EntityManager, Logger)
   - Implement `previewSync()` method
   - Implement `importTransactions()` method
   - Implement `createLedgerEntry()` helper method
   - Add error handling and logging

2. **Update LedgerController**
   - Add `syncPreview()` action
   - Add `syncConfirm()` action
   - Inject GmailAuthService and GmailLedgerSyncService
   - Add CSRF protection on confirm action
   - Add flash messages for all outcomes

3. **Update templates/ledger/index.html.twig**
   - Pass `gmail_connected` variable from controller
   - Add "Sync Steam Emails" button (conditional)
   - Style button appropriately

4. **Create templates/ledger/sync_preview.html.twig**
   - Create form with checkboxes for new transactions
   - Display new transactions with all details
   - Display already-processed transactions (read-only)
   - Add submit and cancel buttons
   - Style with Tailwind (responsive design)

5. **Add JavaScript Enhancement (Optional)**
   - "Select All" / "Deselect All" functionality
   - Progressive enhancement (works without JS)

6. **Update GmailApiService**
   - Add method to get transaction by message ID
   - Update `markMessageProcessed()` to accept ledgerEntryId

7. **Add Migration for ledgerEntryId Field**
   - If not already added in Task 28
   - Run migration

8. **Test Complete Flow**
   - User with Gmail connected clicks "Sync Steam Emails"
   - Preview shows new and processed transactions
   - User selects some transactions
   - Import creates ledger entries with correct data
   - Messages marked as processed
   - Running sync again shows those as "already imported"
   - Ledger entries visible in main ledger list
   - Entries have correct type, category, description

9. **Test Edge Cases**
   - No new transactions (all already processed)
   - Gmail not connected (redirects to settings)
   - No transactions found at all
   - Error during Gmail API call
   - Error during import (transaction rollback)
   - Very large number of transactions

## Edge Cases & Error Handling

- **Gmail not connected**: Redirect to settings with message
- **No matching emails found**: Show message "No Steam wallet emails found"
- **All emails already processed**: Show message "All found emails have already been imported"
- **User deselects all checkboxes**: Show warning message
- **Gmail API error during preview**: Show error message, link to try again
- **Import fails mid-batch**: Use transaction to rollback all imports for this request
- **Duplicate import attempt**: Skip already-processed messages (checked before creating entry)
- **Network timeout during sync**: Handle gracefully, show error
- **Invalid message ID submitted**: Ignore invalid IDs, import valid ones
- **CSRF token invalid**: Show error, require re-preview
- **Concurrent import attempts**: Database unique constraint prevents duplicate ProcessedGmailMessage entries
- **Email parsing fails during import**: Skip that email, continue with others
- **User disconnects Gmail after preview but before confirm**: Import will fail, show error
- **Very old emails**: Date extraction should work for any email age
- **Currency not USD or CAD**: Still create entry with extracted currency (ledger supports any 3-letter code)

## Dependencies

### Blocking Dependencies
- **Task 27**: Gmail OAuth & Token Storage (MUST be completed first)
- **Task 28**: Gmail API Service & Email Processing (MUST be completed first)
- **Task 25**: Ledger Backend Foundation (MUST be completed first)

### Related Tasks
- **Task 26**: Ledger Frontend with Sorting/Filtering (parallel - enhances ledger list view)

### External Dependencies
- All services and entities from Tasks 25, 27, 28
- Twig templating engine
- Symfony Form component (for CSRF)

## Acceptance Criteria

- [ ] GmailLedgerSyncService created with all methods implemented
- [ ] LedgerController has syncPreview() action
- [ ] LedgerController has syncConfirm() action
- [ ] "Sync Steam Emails" button added to ledger index (conditional on Gmail connected)
- [ ] Sync preview template created with checkboxes for new transactions
- [ ] Preview shows new transactions with date, amount, currency, snippet
- [ ] Preview shows already-processed transactions (read-only)
- [ ] Preview shows counts: "X new, Y already imported"
- [ ] Form submits selected message IDs to confirm action
- [ ] Confirm action creates ledger entries for selected transactions
- [ ] Created entries have transaction type "investment"
- [ ] Created entries have category "Steam Wallet"
- [ ] Created entries have auto-generated description
- [ ] Created entries have correct amount, currency, and date from email
- [ ] Messages marked as processed after import
- [ ] ProcessedGmailMessage entries link to created LedgerEntry (ledgerEntryId)
- [ ] Running sync twice shows first batch as "already imported"
- [ ] CSRF protection enabled on confirm action
- [ ] Flash messages show for success and errors
- [ ] Gmail not connected shows error and redirects to settings
- [ ] No transactions found shows appropriate message
- [ ] Import errors handled gracefully (transaction rollback)
- [ ] Responsive design works on mobile
- [ ] Manual verification: Connect Gmail, sync emails, import transactions
- [ ] Manual verification: Sync again, verify already-imported shown correctly
- [ ] Manual verification: Verify ledger entries created with correct data
- [ ] Manual verification: Verify category "Steam Wallet" and type "investment"

## Notes & Considerations

- **Two-step process**: Preview → Confirm prevents accidental imports and lets users review data
- **Idempotent**: Can run sync multiple times safely. Already-processed messages won't be imported again.
- **Checkbox selection**: Gives users control over what to import (might not want all)
- **Default: all checked**: Pre-check all checkboxes so users can import all with one click
- **Category automation**: All imports get "Steam Wallet" category for easy filtering
- **Transaction type**: "investment" because adding funds to Steam is investing in CS2 market
- **Description format**: Standardized description makes entries recognizable
- **No automatic sync**: This task is manual trigger only. Future enhancement could add cron-based automation.
- **Email retention**: Processed messages stay in database. Could add cleanup job later if needed.
- **Ledger entry editing**: Users can edit imported entries if needed (standard ledger CRUD)
- **Historical accuracy**: Import preserves original email date as transaction date
- **Currency conversion**: Ledger will display in user's preferred currency (via existing CurrencyExtension)
- **Future enhancements**: Could add email snippets to ledger description, add tags, etc.
- **Performance**: Previewing 100 emails should be fast (<5 seconds). Import is fast too.
- **User education**: Could add help text explaining what this feature does
- **Gmail API quota**: This feature uses very little quota. Won't be an issue.

## Related Tasks

- **Task 27**: Gmail OAuth & Token Storage (blocking - foundation)
- **Task 28**: Gmail API Service & Email Processing (blocking - email parsing)
- **Task 25**: Ledger Backend Foundation (blocking - provides LedgerEntry)
- **Task 26**: Ledger Frontend with Sorting/Filtering (parallel - enhances display)

## Future Enhancements (Not in This Task)

- Automatic sync on schedule (cron job)
- Email preview in UI (show full email content)
- Bulk edit of imported entries (change category, etc.)
- Export synced transactions to CSV
- Sync status dashboard
- Email notification when new Steam emails detected
- Support for other types of emails (refunds, marketplace sales, etc.)
