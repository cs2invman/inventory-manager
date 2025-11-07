# Task 25: Investment Ledger - Backend Foundation

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-07

## Overview

Create the database schema, entity, repository, forms, and basic CRUD controller for tracking CS2 market investment transactions. This is the foundation for the ledger feature that will allow users to record deposits/withdrawals to track their investment in the CS2 market.

## Problem Statement

Users need a way to track money invested into and withdrawn from the CS2 market. Currently, there's no system to record financial transactions separately from inventory items. Users want to maintain a simple financial ledger with entries that include:
- Transaction date
- Description/notes
- Amount with currency (USD or CAD)
- Transaction type (Investment or Withdrawal)
- Optional category/tags for organization

All displays should respect the user's preferred currency setting and use their configured exchange rate for conversions.

## Requirements

### Functional Requirements
- Create LedgerEntry entity to store transaction records
- Each entry belongs to a specific User
- Support transaction types: "investment" (money in) and "withdrawal" (money out)
- Store original currency (USD or CAD) for each transaction
- Store date/time of transaction
- Support text description and optional category/tags
- Basic CRUD operations: create, read, update, delete
- Entries are user-specific (users only see their own entries)

### Non-Functional Requirements
- Database migration must be reversible
- Form validation for required fields
- Security: ensure users can only access their own ledger entries
- Use Doctrine ORM best practices (proper relationships, cascade operations)
- Follow existing codebase patterns (entity structure, repository, controller)

## Technical Approach

### Database Changes

**New Entity: LedgerEntry**

```php
namespace App\Entity;

use App\Repository\LedgerEntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LedgerEntryRepository::class)]
#[ORM\Table(name: 'ledger_entry')]
class LedgerEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $transactionDate = null;

    #[ORM\Column(type: 'string', length: 20)]
    private ?string $transactionType = null; // 'investment' or 'withdrawal'

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $amount = null;

    #[ORM\Column(type: 'string', length: 3)]
    private ?string $currency = null; // 'USD' or 'CAD'

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // Getters and setters...
}
```

**Migration**: Create table `ledger_entry` with columns as defined above.

**Update User Entity**: Add relationship to LedgerEntry (one-to-many)

### Repository Layer

**LedgerEntryRepository**
- `findByUser(User $user, array $orderBy = []): array` - Get all entries for a user
- `findByUserWithFilters(User $user, array $filters, array $orderBy = []): array` - Support filtering (used in Task 26)
- Standard Doctrine repository methods for CRUD

### Form Layer

**LedgerEntryType Form**
- Transaction date (DateTimeType)
- Transaction type (ChoiceType: investment/withdrawal)
- Amount (MoneyType or NumberType with 2 decimals)
- Currency (ChoiceType: USD/CAD)
- Description (TextareaType, optional)
- Category (TextType, optional)

Form validation:
- transactionDate: required
- transactionType: required, must be 'investment' or 'withdrawal'
- amount: required, positive number, max 2 decimal places
- currency: required, must be 'USD' or 'CAD'
- description: optional, max 1000 characters
- category: optional, max 100 characters

### Controller Layer

**New Controller: LedgerController**
Route prefix: `/ledger`

Actions:
1. `index()` [GET /ledger] - List all entries for current user, passes entries to template
2. `new()` [GET/POST /ledger/new] - Create new entry, shows form and handles submission
3. `edit(LedgerEntry $entry)` [GET/POST /ledger/{id}/edit] - Edit existing entry
4. `delete(LedgerEntry $entry)` [POST /ledger/{id}/delete] - Delete entry (with CSRF protection)

Security:
- All routes require `ROLE_USER`
- Use security voter or manual checks to ensure users can only access their own entries

### Configuration

No new environment variables needed. Will use existing UserConfig fields:
- `preferredCurrency` - User's display currency
- `cadExchangeRate` - Fixed conversion rate

## Implementation Steps

1. **Create LedgerEntry Entity**
   - Create `src/Entity/LedgerEntry.php` with all fields and relationships
   - Add getters/setters, constructor to set createdAt

2. **Update User Entity**
   - Add one-to-many relationship to LedgerEntry
   - Add methods: `getLedgerEntries()`, `addLedgerEntry()`, `removeLedgerEntry()`

3. **Create Database Migration**
   - Run: `docker compose exec php php bin/console make:migration`
   - Review migration file
   - Run: `docker compose exec php php bin/console doctrine:migrations:migrate`

4. **Create LedgerEntryRepository**
   - Create `src/Repository/LedgerEntryRepository.php`
   - Implement `findByUser()` method
   - Implement `findByUserWithFilters()` method (for Task 26)

5. **Create LedgerEntryType Form**
   - Create `src/Form/LedgerEntryType.php`
   - Add all fields with proper types and validation
   - Configure form options (labels, placeholders, help text)

6. **Create LedgerController**
   - Create `src/Controller/LedgerController.php`
   - Implement `index()` action (basic list, no filtering yet)
   - Implement `new()` action (form display + submission)
   - Implement `edit()` action (load entry, check ownership, show form)
   - Implement `delete()` action (check ownership, delete, redirect)

7. **Create Basic Templates**
   - Create `templates/ledger/index.html.twig` (simple table, no sorting/filtering)
   - Create `templates/ledger/_form.html.twig` (reusable form partial)
   - Create `templates/ledger/new.html.twig` (new entry form)
   - Create `templates/ledger/edit.html.twig` (edit entry form)
   - Use existing Tailwind CSS patterns from the codebase

8. **Add Navigation Link**
   - Update `templates/base.html.twig` or navigation to add "Ledger" link

## Edge Cases & Error Handling

- **Non-existent entry**: Return 404 if entry ID doesn't exist
- **Unauthorized access**: Return 403 if user tries to access another user's entry
- **Invalid form data**: Display validation errors inline
- **Negative amounts**: Form validation prevents negative numbers
- **Future dates**: Allow future dates (user might pre-record planned transactions)
- **Very old dates**: No restriction (user might import historical data)
- **Empty database**: Show "no entries" message with link to create first entry
- **Deletion confirmation**: Add JavaScript confirmation or dedicated confirmation page
- **Currency mismatch**: Store original currency, convert for display using user's settings
- **Missing UserConfig**: Fall back to USD with 1.0 rate if user config doesn't exist

## Dependencies

### Blocking Dependencies
None - this task can start immediately.

### Related Tasks
- **Task 26**: Ledger Frontend with Sorting/Filtering (depends on Task 25)

### External Dependencies
- Existing currency conversion system (UserConfig, CurrencyExtension)
- Doctrine ORM and Symfony Form component

## Acceptance Criteria

- [ ] LedgerEntry entity created with all required fields
- [ ] Database migration created and successfully applied
- [ ] User entity has relationship to LedgerEntry
- [ ] LedgerEntryRepository created with findByUser() method
- [ ] LedgerEntryType form created with all fields and validation
- [ ] LedgerController created with all CRUD actions
- [ ] Security checks ensure users only access their own entries
- [ ] Basic templates created for list, new, and edit views
- [ ] Navigation link added to access ledger
- [ ] User can create a new ledger entry with all fields
- [ ] User can view list of their ledger entries
- [ ] User can edit an existing ledger entry
- [ ] User can delete a ledger entry
- [ ] Amounts display in user's preferred currency (respecting UserConfig settings)
- [ ] Original currency is preserved in database
- [ ] Form validation works for all fields
- [ ] CSRF protection enabled on forms
- [ ] Error messages display properly for validation failures
- [ ] Attempting to access another user's entry returns 403
- [ ] Manual verification: Create, edit, delete entries as different users

## Notes & Considerations

- **No calculated totals**: Per user requirements, Task 25 doesn't include balance calculations or summaries (just the entry list)
- **Display currency**: Always display amounts in user's preferred currency using the existing CurrencyExtension filter
- **Original currency**: Store the original currency in the database so users can track which currency they actually used
- **Transaction types**: Using "investment" and "withdrawal" rather than "deposit" and "withdrawal" to match the user's language of "money invested"
- **Categories**: Categories are free-form text for now (not a separate entity). Can be enhanced later if needed.
- **Sorting and filtering**: Basic display only in Task 25. Task 26 will add sorting, filtering, and enhanced UI.
- **No edit history**: Entries can be edited, but we don't track edit history. If needed later, could add an audit log.
- **Timezone handling**: Use DateTimeImmutable (stores UTC) and let PHP/Twig handle timezone display
- **Performance**: For now, load all entries for a user. If users have thousands of entries, Task 26 will add pagination.

## Related Tasks

- **Task 26**: Ledger Frontend with Sorting/Filtering (depends on Task 25) - Adds sorting, filtering, pagination, and enhanced UI
