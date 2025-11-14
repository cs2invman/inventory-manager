# Display Relative Timestamp for Price Updates

**Status**: Completed
**Priority**: Low
**Estimated Effort**: Small
**Created**: November 14, 2025
**Completed**: November 14, 2025

## Overview

Update the inventory index page to show when prices were last updated using a relative timestamp format (e.g., "5m ago", "2h ago") instead of the current "Total Inventory Value" text. The full timestamp should appear on hover as a tooltip. This provides users with quick visibility into price data freshness without cluttering the UI.

## Problem Statement

Currently, the inventory page displays "Total Inventory Value" as a static label (line 31 in `templates/inventory/index.html.twig`). Users have no easy way to know when the displayed prices were last updated. This information is important for understanding if inventory valuations are current or stale.

## Requirements

### Functional Requirements
- Replace "Total Inventory Value" text with relative timestamp (e.g., "5m ago", "2h ago", "3d ago")
- Show full timestamp on hover with browser's local timezone
- Calculate timestamp from the largest (most recent) `price_date` in the `item_price` table
- Refreshes on page load (no real-time updates needed)
- Gracefully handle cases where no price data exists

### Non-Functional Requirements
- Query should be efficient (single MAX query on indexed column)
- Format should use short abbreviations: "m" for minutes, "h" for hours, "d" for days
- Tooltip should display full date/time in user's browser timezone (e.g., "January 14, 2025 3:45 PM")
- No additional JavaScript libraries needed for relative time formatting

## Technical Approach

### Database Query
Add method to `ItemPriceRepository` to find the most recent price update:
```php
public function findMostRecentPriceDate(): ?\DateTimeInterface
{
    $result = $this->createQueryBuilder('ip')
        ->select('MAX(ip.priceDate) as maxDate')
        ->getQuery()
        ->getSingleScalarResult();

    return $result ? new \DateTimeImmutable($result) : null;
}
```

### Controller Changes
Update `InventoryController::index()`:
- Call repository method to get latest price date
- Pass `lastPriceUpdate` variable to template
- Handle null case (no prices yet)

### Frontend Changes

#### Template Update (templates/inventory/index.html.twig)
Replace line 31:
```twig
{# OLD: #}
<p class="text-sm text-gray-400">Total Inventory Value</p>

{# NEW: #}
{% if lastPriceUpdate %}
    <p class="text-sm text-gray-400" title="{{ lastPriceUpdate|date('F j, Y g:i A', app.request.locale) }}"
       data-timestamp="{{ lastPriceUpdate|date('c') }}">
        <span class="prices-updated-label">Calculating...</span>
    </p>
{% else %}
    <p class="text-sm text-gray-400">No price data</p>
{% endif %}
```

#### JavaScript Implementation
Create new file: `public/js/relative-time.js`
- Calculate time difference between `data-timestamp` and current time
- Format as "Xm ago", "Xh ago", "Xd ago" based on difference
- Update all elements with class `.prices-updated-label` on DOM ready
- Use browser's local time for accurate calculations

Time formatting logic:
- < 60 minutes: "Xm ago"
- < 24 hours: "Xh ago"
- >= 24 hours: "Xd ago"

#### Asset Loading
Update `templates/inventory/index.html.twig` javascripts block:
```twig
{% block javascripts %}
    {{ parent() }}
    <script src="{{ asset('js/inventory-filter.js') }}" defer></script>
    <script src="{{ asset('js/relative-time.js') }}" defer></script>
{% endblock %}
```

### Configuration
No environment variables or configuration needed.

## Implementation Steps

1. **Add Repository Method**
   - Open `src/Repository/ItemPriceRepository.php`
   - Add `findMostRecentPriceDate()` method at end of class
   - Method returns `?\DateTimeInterface`

2. **Update Controller**
   - Open `src/Controller/InventoryController.php`
   - In `index()` method, after line 23, inject `ItemPriceRepository`
   - Before the render call (around line 257), add:
     ```php
     $lastPriceUpdate = $this->itemPriceRepository->findMostRecentPriceDate();
     ```
   - Add `'lastPriceUpdate' => $lastPriceUpdate,` to render array (line 268)

3. **Update Twig Template**
   - Open `templates/inventory/index.html.twig`
   - Replace line 31 with new markup (see "Frontend Changes" above)
   - Keep the structure intact (same `<p>` with classes)
   - Add ISO 8601 timestamp in `data-timestamp` attribute for JS parsing

4. **Create JavaScript File**
   - Create `public/js/relative-time.js`
   - Implement relative time calculation and formatting
   - Use `querySelector` to find all `.prices-updated-label` elements
   - Parse ISO timestamp from parent element's `data-timestamp`
   - Calculate difference and format appropriately

5. **Update Asset Loading**
   - Add script tag for `relative-time.js` in `{% block javascripts %}`
   - Use `defer` attribute for non-blocking load

6. **Rebuild Assets**
   - Run: `docker compose run --rm node npm run build`
   - Verify no Tailwind class changes needed

7. **Manual Testing**
   - Navigate to `/inventory`
   - Verify relative timestamp displays (e.g., "15m ago")
   - Hover over timestamp to see full date/time in local timezone
   - Check with no price data (should show "No price data")
   - Refresh page to verify it recalculates correctly

## Edge Cases & Error Handling

- **No price data exists**: Display "No price data" instead of relative timestamp
- **Very old prices (>30 days)**: Still display as "Xd ago" (e.g., "45d ago")
- **Future timestamps** (clock skew): Treat as "0m ago" to avoid negative values
- **Invalid timestamp format**: Fallback to "Unknown" if parsing fails
- **JavaScript disabled**: Tooltip still works, shows "Calculating..." text until JS loads

## Dependencies

### Blocking Dependencies
None - this is a standalone feature

### Related Tasks
None - this is an independent UI enhancement

### Can Be Done in Parallel With
Any other task

### External Dependencies
- Doctrine ORM (already available)
- Twig template engine (already available)
- Browser native Date API (no external JS library needed)

## Acceptance Criteria

- [x] `ItemPriceRepository::findMostRecentPriceDate()` method implemented
- [x] Controller passes `mostRecentPriceUpdate` to template
- [x] Line 31 in inventory template replaced with new timestamp markup
- [x] `public/js/relative-time.js` created with relative time formatting
- [x] JavaScript calculates and displays: "Xm ago", "Xh ago", or "Xd ago" appropriately
- [x] Hover tooltip shows full timestamp in browser's local timezone
- [x] "No price data" displays when no prices exist
- [x] Assets rebuilt after changes
- [ ] Manual verification: timestamp displays correctly and updates on page refresh
- [ ] Manual verification: tooltip shows accurate local time

## Notes & Considerations

- **Performance**: Single MAX query on indexed `price_date` column is very fast
- **Timezone handling**: Server provides UTC timestamp in ISO 8601 format; browser converts to local time for tooltip display
- **Refresh interval**: Does not auto-update in real-time (page refresh required). This is acceptable since price updates happen infrequently (via cron job)
- **Short format rationale**: Using "m", "h", "d" abbreviations keeps the UI compact and doesn't disrupt the visual hierarchy
- **Alternative locations considered**: Could also be placed near the actual value display, but replacing the "Total Inventory Value" text makes better use of space

---

## Task Completion Instructions

When this task is fully complete:

1. **Update this file**:
   - Change `**Status**: Not Started` to `**Status**: Completed`
   - Add completion date: `**Completed**: [Date]`

2. **Move to completed folder**:
   - Move this file from `tasks/` to `tasks/completed/`
   - Keep the same filename: `56-price-update-relative-timestamp.md`

3. **Verify all acceptance criteria** are checked off before marking as complete
