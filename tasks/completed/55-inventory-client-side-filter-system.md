# Inventory Client-Side Filter System

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-13

## Overview

Add a powerful client-side filtering system to the inventory index page that allows users to quickly find items using a simple yet flexible query syntax. The filter will support searching by item name, float range, price range, pattern seed, wear category, item category (StatTrak/Souvenir/Normal), collection, rarity, and storage box location.

## Problem Statement

Currently, the inventory page only supports basic server-side filtering by "All Items", "Active Inventory", or specific storage boxes. Users have no way to quickly find specific items based on float values, price ranges, pattern seeds, or other detailed properties without manually scrolling through their entire inventory. This becomes particularly problematic for users with large inventories (100+ items) who need to quickly locate items matching specific criteria.

## Requirements

### Functional Requirements

**Filter Syntax Support:**
- **Item Name Search**: `dlore`, `asiimov`, `ak ch` (case-insensitive partial match)
- **Float Range**: `<0.04`, `>0.3`, `0.15-0.19` (only for items with float values)
- **Price Range**: `<400`, `>34.66` (based on aggregate price for grouped items)
- **Pattern Seed**: `#661`, `#700` (matches paintSeed or patternIndex)
- **Wear Category**: `w:fn`, `W:mw`, `w:ft`, `w:ww`, `w:bs` (case-insensitive)
- **Item Category**: `cat:st`, `CAT:sv`, `Cat:norm` (StatTrak, Souvenir, Normal - case-insensitive)
- **Collection**: `COL:chroma 2`, `col:dust 2` (case-insensitive partial match)
- **Rarity**: `R:covert`, `r:milspec`, `r:contraband` (case-insensitive)
- **Storage Box**: `Box:A1`, `b:B1` (case-insensitive, only shows items in that box)

**Filter Behavior:**
- Multiple filters are combined with AND logic (all conditions must match)
- Filter entire grouped items (don't split groups)
- Filters clear when navigating between tabs (All Items, Active Inventory, Storage Box views)
- Real-time filtering with debounce (300ms delay after typing stops)
- Storage box filter works in all views (All Items, Active, specific boxes)
- Wear filter only matches items with exact wear categories (excludes items without wear)
- Category filter is single-value only (can't combine st + sv in one query)
- Case-insensitive matching for all text-based filters

**UI Elements:**
- Text input field for filter query (below existing filter tabs)
- Real-time filtering as user types (debounced)
- Help button (❓ icon) beside filter input
- Modal overlay with comprehensive syntax examples and rules
- Clear visual feedback when no items match the filter
- Show count of filtered items vs total items

### Non-Functional Requirements

- **Performance**: Filter 500+ items in <100ms
- **Usability**: Intuitive syntax that doesn't require documentation for basic searches
- **Accessibility**: Keyboard navigation support (Enter to apply, Escape to clear)
- **Mobile**: Responsive design for filter input and help modal
- **Maintainability**: Well-documented JavaScript code with clear parsing logic

## Technical Approach

### Data Attributes on Item Cards

Add the following data attributes to each item card (`item_card.html.twig`) for filtering:

```twig
data-item-name="{{ item.name|lower }}"
data-item-type="{{ item.type }}"
data-collection="{{ item.collection|lower }}"
data-rarity="{{ item.rarity|lower }}"
data-float="{{ itemUser.floatValue }}"
data-pattern="{{ itemUser.paintSeed ?? itemUser.patternIndex }}"
data-wear="{{ itemUser.wearCategory|lower }}"
data-price="{{ aggregatePrice|default(priceValue) }}"
data-storage-box="{{ itemUser.storageBox ? itemUser.storageBox.name|lower : 'none' }}"
data-is-stattrak="{{ itemUser.isStattrak ? 'true' : 'false' }}"
data-is-souvenir="{{ itemUser.isSouvenir ? 'true' : 'false' }}"
data-is-normal="{{ (not itemUser.isStattrak and not itemUser.isSouvenir) ? 'true' : 'false' }}"
```

### JavaScript Filter Implementation

**File**: `assets/js/inventory-filter.js`

**Core Components:**
1. **FilterParser**: Parses query string into filter objects
2. **FilterMatcher**: Applies filters to item cards
3. **FilterUI**: Manages UI updates and debouncing
4. **HelpModal**: Displays syntax help

**Filter Parsing Logic:**
```javascript
// Example parsed filter from "Fade w:fn <0.2"
{
  itemName: "fade",
  wear: "fn",
  floatRange: { operator: "<", value: 0.2 }
}
```

**Matching Algorithm:**
- Iterate through all item cards
- For each card, check all filter conditions
- Hide card if ANY condition fails (AND logic)
- Show card only if ALL conditions pass
- Update visible item count

**Debouncing:**
- Use 300ms debounce on input keyup
- Clear previous timeout on each keystroke
- Apply filter only after user stops typing

### Frontend Changes

**Templates:**
1. **`templates/inventory/index.html.twig`**:
   - Add filter input field below existing filter tabs
   - Add help button beside input
   - Add help modal structure
   - Add item count display ("Showing X of Y items")
   - Add "No items match filter" empty state

2. **`templates/components/item_card.html.twig`**:
   - Add data attributes for all filterable properties
   - Ensure attributes are lowercase for case-insensitive matching

**Assets:**
1. **`assets/js/inventory-filter.js`**: Complete filter implementation
2. **`assets/styles/app.css`**: Modal styles and filter UI styles

### Configuration

No environment variables or configuration changes needed.

## Implementation Steps

### Step 1: Add Data Attributes to Item Card Component
- Edit `templates/components/item_card.html.twig`
- Add data attributes to the root `<div>` element
- Handle null values gracefully (e.g., `data-float="{{ itemUser.floatValue|default('') }}"`)
- Add `data-is-normal` calculated attribute for non-ST/non-SV items
- Test that attributes render correctly in HTML

### Step 2: Update Inventory Index Template
- Edit `templates/inventory/index.html.twig`
- Add filter input field below the existing filter tabs (line ~43)
- Add help button (❓ icon) beside the input
- Add hidden help modal structure (after main content)
- Add "Showing X of Y items" counter display
- Add "No items match filter" empty state (hidden by default)

### Step 3: Create JavaScript Filter Parser
- Create `assets/js/inventory-filter.js`
- Implement `parseFilterQuery(queryString)` function
- Parse each filter type using regex:
  - Float range: `/<([\d.]+)|>([\d.]+)|([\d.]+)-([\d.]+)/`
  - Price range: `/<([\d.]+)|>([\d.]+)/` (price > 1.0)
  - Pattern: `/#(\d+)/`
  - Wear: `/w:([a-z]+)/i`
  - Category: `/cat:([a-z]+)/i`
  - Collection: `/col:([^"]+)/i`
  - Rarity: `/r:([a-z]+)/i`
  - Storage: `/box?:([^"]+)/i`
- Extract item name as remainder (anything not matching above patterns)
- Return structured filter object

### Step 4: Implement Filter Matching Logic
- Implement `matchesFilter(itemCard, filterObj)` function
- For each filter property:
  - Check if filter exists
  - Get data attribute from item card
  - Apply matching logic (substring, exact, range, etc.)
  - Return false immediately if any condition fails
- Return true only if all conditions pass

### Step 5: Create Filter UI Controller
- Implement `applyFilter()` function
- Get query string from input
- Parse query using FilterParser
- Iterate through all `.card` elements in items grid
- Show/hide based on `matchesFilter()` result
- Update item count display
- Show/hide empty state
- Implement debouncing (300ms delay)

### Step 6: Build Help Modal
- Create modal HTML structure with:
  - Title: "Filter Syntax Guide"
  - Sections for each filter type with examples
  - Multiple example queries
  - Close button (X) and backdrop click to close
  - Escape key to close
- Implement modal show/hide functionality
- Add modal styling (dark theme, centered, responsive)

### Step 7: Wire Up Event Listeners
- Add event listener to filter input (keyup with debounce)
- Add event listener to help button (click to show modal)
- Add event listener to modal close button
- Add event listener to modal backdrop
- Add keyboard listener (Escape to close modal, Escape to clear filter)
- Ensure Enter key in input doesn't submit form

### Step 8: Clear Filter on Navigation
- Add event listeners to existing filter tabs (All, Active, Storage boxes)
- Clear filter input value on tab click
- Reset item visibility
- Update item count

### Step 9: Add Styling
- Style filter input field (Tailwind classes)
- Style help button (icon, hover state)
- Style help modal (dark overlay, centered panel, close button)
- Style empty state message
- Style item count display
- Ensure mobile responsiveness

### Step 10: Test Filter Functionality
- Test each filter type individually
- Test combined filters (AND logic)
- Test edge cases:
  - Empty query (show all)
  - No matches (show empty state)
  - Items without float values (excluded from float filters)
  - Items without wear (excluded from wear filters)
  - Grouped items (filter entire group)
  - Case-insensitive matching
- Test performance with large inventory (500+ items)
- Test on mobile devices

### Step 11: Rebuild Frontend Assets
- Run `docker compose run --rm node npm run build`
- Clear browser cache and test
- Verify all JavaScript is working correctly

## Edge Cases & Error Handling

### Edge Cases

1. **Items without float values**: Float range filters skip these items entirely
2. **Items without wear categories**: Wear filters exclude these items (stickers, cases, etc.)
3. **Items without pattern seeds**: Pattern filter excludes these items
4. **Grouped items**: Filter operates on the group's aggregate price, not individual item prices
5. **Storage box filter in box view**: "Box:A1" works even when already viewing box A1 (no-op)
6. **Malformed float range**: "0.5-0.2" (min > max) - treat as no matches
7. **Invalid wear abbreviation**: "w:xyz" - no matches
8. **Empty filter query**: Show all items (no filtering)
9. **Price range ambiguity**: "0.5" could be float or price - prioritize float if < 1.0, price if > 1.0
10. **Special characters in item name**: Handle quotes, slashes in item names

### Error Handling

- **Invalid filter syntax**: Silently ignore invalid parts, apply valid parts
- **No matches**: Show clear empty state message "No items match your filter"
- **JavaScript disabled**: Filter input still visible but non-functional (graceful degradation)
- **Missing data attributes**: Treat as null/empty, won't match filters requiring that attribute
- **NaN in numeric comparisons**: Use `parseFloat()` with fallback to 0
- **Console logging**: Add debug logs for filter parsing (can be removed in production)

## Dependencies

### Blocking Dependencies
None - this is a standalone feature.

### Related Tasks
None - this is a new feature.

### Can Be Done in Parallel With
Any other tasks not modifying `inventory/index.html.twig` or `item_card.html.twig`.

### External Dependencies
- Tailwind CSS (already in project)
- No external JavaScript libraries needed (vanilla JS)

## Acceptance Criteria

- [ ] Filter input field displays below existing filter tabs on inventory index page
- [ ] Help button (❓) opens modal with comprehensive syntax documentation
- [ ] Modal closes on X button, backdrop click, or Escape key
- [ ] All filter types work correctly:
  - [ ] Item name search (case-insensitive, partial match)
  - [ ] Float range (`<0.04`, `>0.3`, `0.15-0.19`)
  - [ ] Price range (`<400`, `>34.66`)
  - [ ] Pattern seed (`#661`)
  - [ ] Wear category (`w:fn`, `w:mw`, etc.)
  - [ ] Item category (`cat:st`, `cat:sv`, `cat:norm`)
  - [ ] Collection (`col:chroma 2`)
  - [ ] Rarity (`r:covert`, `r:milspec`)
  - [ ] Storage box (`box:A1`, `b:B1`)
- [ ] Multiple filters combine with AND logic (all must match)
- [ ] Filter operates on entire grouped items (doesn't split groups)
- [ ] Real-time filtering with 300ms debounce
- [ ] Item count updates correctly ("Showing X of Y items")
- [ ] Empty state displays when no items match filter
- [ ] Filter clears when switching between tabs (All, Active, Storage boxes)
- [ ] All text-based filters are case-insensitive
- [ ] Filter works on inventories with 500+ items without lag
- [ ] Responsive design works on mobile devices
- [ ] Example queries work correctly:
  - [ ] "M4 Fade" matches "M4A1-S | Fade (Factory New)"
  - [ ] "Fade w:fn" matches only Factory New Fade items
  - [ ] "Fade <0.2" matches Fade items with float < 0.2
  - [ ] "dlore #661" matches Dragon Lore with pattern 661
  - [ ] "cat:st r:covert >100" matches StatTrak Covert items over $100
- [ ] Assets rebuilt with `npm run build`
- [ ] No console errors in browser developer tools
- [ ] Filter persists during page scroll and item card interactions
- [ ] Keyboard accessibility (Tab to navigate, Enter to apply, Escape to clear)

## Notes & Considerations

### Implementation Notes

1. **Performance Optimization**:
   - Use `display: none` instead of removing elements from DOM
   - Consider adding `will-change: display` for smoother transitions
   - Profile with Chrome DevTools on large inventories

2. **Filter Syntax Design**:
   - Kept simple and intuitive (no complex boolean logic)
   - Prefix-based for non-ambiguous parsing (w:, cat:, col:, r:, box:)
   - Natural language for item names (no quotes needed)
   - Numeric operators (<, >, -) are universally understood

3. **Future Enhancements** (not in scope):
   - Save favorite filters to localStorage
   - Filter history dropdown
   - Advanced boolean logic (OR, NOT)
   - Filter by sticker names
   - Filter by StatTrak™ kills count
   - URL query parameter for sharing filtered views
   - Export filtered items as CSV

### Security Considerations

- All filtering happens client-side (no security implications)
- No user input sent to server
- XSS protection: Use `textContent` instead of `innerHTML` for user queries
- Data attributes already escaped by Twig

### Performance Considerations

- Debounce prevents excessive filtering on rapid typing
- Simple string operations (indexOf, parseFloat) are fast
- Target: <100ms filter time for 500 items
- No DOM reflows (only show/hide, no repositioning)

### User Experience Considerations

- Clear visual feedback (item count, empty state)
- Helpful examples in modal
- Forgiving syntax (case-insensitive, partial matches)
- No need to learn complex query language for basic searches
- Filter clears on navigation to avoid confusion
- Help always accessible via button
