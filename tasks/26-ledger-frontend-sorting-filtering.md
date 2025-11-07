# Task 26: Investment Ledger - Frontend with Sorting and Filtering

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small-Medium
**Created**: 2025-11-07

## Overview

Enhance the ledger list page with sorting, filtering, and improved UI. This task builds on the basic CRUD functionality from Task 25 to provide a more powerful and user-friendly interface for managing ledger entries.

## Problem Statement

While Task 25 provides basic ledger CRUD operations, users need better tools to navigate and organize their entries:
- Sort entries by date, amount, type, or category
- Filter entries by transaction type, currency, or category
- Better visual presentation with color coding and clear typography
- Responsive design that works well on all screen sizes

## Requirements

### Functional Requirements
- Sort entries by: date (newest/oldest), amount (high/low), type, category
- Filter entries by: transaction type (all/investment/withdrawal), currency (all/USD/CAD), category
- Maintain sort and filter selections across page reloads using query parameters
- Clear visual distinction between investment (money in) and withdrawal (money out)
- Show entry count and filter status
- Responsive table/card layout for mobile devices

### Non-Functional Requirements
- Fast query performance (use database queries, not in-memory filtering)
- Maintain existing security (users only see their own entries)
- Follow existing Tailwind CSS patterns and design system
- Use query parameters for filters/sort (enables bookmarking, back button support)
- No JavaScript required for core functionality (progressive enhancement)

## Technical Approach

### Controller Changes

**Update LedgerController::index()**
- Accept query parameters: `sort`, `order`, `type`, `currency`, `category`
- Pass parameters to repository method
- Pass current filter/sort state to template
- Generate reset URL for clearing filters

```php
public function index(Request $request): Response
{
    $user = $this->getUser();

    // Get filter/sort parameters from query string
    $filters = [
        'type' => $request->query->get('type'),        // investment, withdrawal, or null
        'currency' => $request->query->get('currency'), // USD, CAD, or null
        'category' => $request->query->get('category'), // specific category or null
    ];

    $sort = $request->query->get('sort', 'date');    // date, amount, type, category
    $order = $request->query->get('order', 'desc');  // asc or desc

    // Fetch entries with filters and sorting
    $entries = $this->ledgerEntryRepository->findByUserWithFilters(
        $user,
        $filters,
        [$sort => $order]
    );

    // Get unique categories for filter dropdown
    $categories = $this->ledgerEntryRepository->findCategoriesForUser($user);

    return $this->render('ledger/index.html.twig', [
        'entries' => $entries,
        'categories' => $categories,
        'currentFilters' => $filters,
        'currentSort' => $sort,
        'currentOrder' => $order,
        'userCurrency' => $user->getConfig()?->getPreferredCurrency() ?? 'USD',
        'exchangeRate' => $user->getConfig()?->getCadExchangeRate() ?? 1.0,
    ]);
}
```

### Repository Changes

**Update LedgerEntryRepository**

Add method: `findByUserWithFilters(User $user, array $filters, array $orderBy): array`

```php
public function findByUserWithFilters(User $user, array $filters, array $orderBy): array
{
    $qb = $this->createQueryBuilder('le')
        ->where('le.user = :user')
        ->setParameter('user', $user);

    // Apply filters
    if (!empty($filters['type'])) {
        $qb->andWhere('le.transactionType = :type')
           ->setParameter('type', $filters['type']);
    }

    if (!empty($filters['currency'])) {
        $qb->andWhere('le.currency = :currency')
           ->setParameter('currency', $filters['currency']);
    }

    if (!empty($filters['category'])) {
        $qb->andWhere('le.category = :category')
           ->setParameter('category', $filters['category']);
    }

    // Apply sorting
    foreach ($orderBy as $field => $direction) {
        $qb->orderBy('le.' . $field, $direction);
    }

    return $qb->getQuery()->getResult();
}

public function findCategoriesForUser(User $user): array
{
    return $this->createQueryBuilder('le')
        ->select('DISTINCT le.category')
        ->where('le.user = :user')
        ->andWhere('le.category IS NOT NULL')
        ->setParameter('user', $user)
        ->orderBy('le.category', 'ASC')
        ->getQuery()
        ->getSingleColumnResult();
}
```

### Frontend Changes

**Update templates/ledger/index.html.twig**

Structure:
1. Page header with "New Entry" button
2. Filter/Sort controls (form with dropdowns and submit button)
3. Active filters display with "Clear filters" link
4. Entry count display
5. Responsive table (desktop) / card layout (mobile)
6. Empty state message if no entries match filters

Key features:
- Color-coded transaction types (green for investment, red for withdrawal)
- Clear sort indicators (arrows)
- Responsive design using Tailwind breakpoints
- Use existing `format_price` Twig filter for currency display
- Filter form uses GET method to maintain URL parameters

Table columns:
- Date
- Description
- Category
- Type (with color badge)
- Amount (in user's preferred currency, with original currency indicator)
- Actions (Edit/Delete)

**Filter/Sort UI Components:**
- Transaction type dropdown: All / Investment / Withdrawal
- Currency dropdown: All / USD / CAD
- Category dropdown: All / [user's categories]
- Sort by dropdown: Date / Amount / Type / Category
- Sort order toggle: Ascending / Descending
- Submit button: "Apply Filters"
- Clear filters link (only shown when filters active)

## Implementation Steps

1. **Update LedgerEntryRepository**
   - Implement `findByUserWithFilters()` method with QueryBuilder
   - Implement `findCategoriesForUser()` method
   - Test queries with various filter combinations

2. **Update LedgerController::index()**
   - Read query parameters for filters and sorting
   - Pass to repository method
   - Fetch unique categories for filter dropdown
   - Pass all data to template

3. **Create Filter/Sort Form Partial**
   - Create `templates/ledger/_filters.html.twig`
   - Build filter form with dropdowns
   - Add sort controls
   - Style with Tailwind (similar to storage box filters if they exist)

4. **Update templates/ledger/index.html.twig**
   - Add filter/sort controls at top
   - Add active filters display
   - Enhance table with sort indicators
   - Add color coding for transaction types
   - Make responsive with Tailwind breakpoints
   - Show entry count
   - Improve empty state messaging

5. **Add Visual Enhancements**
   - Color badges for transaction types (green for investment, red for withdrawal)
   - Sort direction indicators (arrows)
   - Highlight applied filters
   - Better spacing and typography
   - Hover states for table rows
   - Improved button styling

6. **Test Filtering and Sorting**
   - Create test entries with various types, currencies, categories
   - Test each filter individually
   - Test filter combinations
   - Test all sort options with both orders
   - Test with no entries, with filters that return no results
   - Test as different users

7. **Mobile Responsiveness**
   - Test on mobile viewports
   - Ensure filters work on mobile
   - Consider card layout instead of table on small screens
   - Ensure touch targets are adequate size

## Edge Cases & Error Handling

- **Invalid sort parameter**: Default to 'date' if invalid value provided
- **Invalid order parameter**: Default to 'desc' if invalid value provided
- **Invalid filter values**: Ignore invalid values (don't apply filter)
- **SQL injection**: Use parameterized queries (Doctrine handles this)
- **No entries**: Show helpful message with link to create first entry
- **No entries matching filters**: Show message with "Clear filters" link
- **Empty category filter**: Handle NULL categories properly in query
- **Multiple categories with same name**: Works fine, filters by exact match
- **Very long category names**: Truncate in UI with ellipsis and tooltip
- **Large number of entries**: Consider adding pagination in future (not in this task)

## Dependencies

### Blocking Dependencies
- **Task 25**: Ledger Backend Foundation (MUST be completed first)

### Related Tasks
- **Task 25**: Ledger Backend Foundation (this task enhances it)

### External Dependencies
- Existing CurrencyExtension (format_price filter)
- Tailwind CSS framework

## Acceptance Criteria

- [ ] Repository method `findByUserWithFilters()` implemented and working
- [ ] Repository method `findCategoriesForUser()` implemented and working
- [ ] Controller reads and processes filter/sort query parameters
- [ ] Filter form displays with all options (type, currency, category)
- [ ] Sort controls display with all options (date, amount, type, category)
- [ ] Filters work correctly (each filter individually)
- [ ] Multiple filters work together (combined filters)
- [ ] Sorting works for all fields in both directions
- [ ] URL parameters update when filters/sort change
- [ ] Filters/sort persist across page refresh (via URL)
- [ ] Clear filters link appears when filters are active
- [ ] Clear filters link removes all filters and resets to default view
- [ ] Active filters displayed prominently above results
- [ ] Entry count shown ("Showing X entries" or "X entries matching filters")
- [ ] Transaction types color-coded (green for investment, red for withdrawal)
- [ ] Sort indicators show current sort field and direction
- [ ] Table is responsive on mobile devices
- [ ] Empty state message shows when no entries exist
- [ ] Filtered empty state message shows when no entries match filters
- [ ] Amount displays in user's preferred currency
- [ ] Original currency indicated when different from display currency
- [ ] Edit and delete actions still work from the enhanced table
- [ ] Manual verification: Test all filter and sort combinations

## Notes & Considerations

- **No JavaScript required**: All filtering/sorting done server-side with full page reloads. Could enhance with AJAX later if desired.
- **Pagination**: Not included in this task. If users have hundreds of entries, a future task could add pagination.
- **Export functionality**: Not included. Could add CSV export in a future task.
- **Advanced filters**: Not included (date ranges, amount ranges). Keep simple for now.
- **Bulk operations**: Not included in this task. Could add in future if needed.
- **Performance**: Repository uses Doctrine QueryBuilder with proper WHERE clauses and ORDER BY. Should be fast even with thousands of entries.
- **Category autocomplete**: Not included. Categories are free-form text, dropdown shows existing categories.
- **Multi-select filters**: Not included. Each filter is single-select for simplicity.
- **Save filter presets**: Not included. Users can bookmark filtered URLs if desired.
- **URL parameters**: Using GET parameters makes filters bookmarkable and supports browser back/forward buttons.

## Related Tasks

- **Task 25**: Ledger Backend Foundation (blocking - must be done first)
