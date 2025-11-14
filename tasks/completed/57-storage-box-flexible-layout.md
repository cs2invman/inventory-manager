# Storage Box Flexible Layout

**Status**: Completed
**Priority**: Low
**Estimated Effort**: Small
**Created**: 2025-11-14
**Completed**: 2025-11-14

## Overview

Replace the fixed CSS grid layout for storage boxes with a flexible layout that maximizes space utilization. Boxes should stretch to fill available space while maintaining a minimum width of 250px, allowing more boxes to fit on wider screens.

## Problem Statement

The current storage boxes section uses a fixed CSS grid (`grid-cols-1 md:grid-cols-2 lg:grid-cols-4`) which:
- Forces boxes into predefined columns regardless of available space
- On ultra-wide screens, boxes don't utilize the full width
- On screens between breakpoints, there's wasted horizontal space
- Cannot adapt dynamically to the number of boxes being displayed

The screenshot shows 5 boxes that have more than enough horizontal space to display in a single row, but the grid layout forces them into a 4-column layout with wrapping.

## Requirements

### Functional Requirements
- Boxes must stretch to fill available horizontal space
- Each box must maintain a minimum width of 250px
- Boxes should wrap to the next line when there isn't enough space for 250px width
- Maintain current gap spacing (1rem/16px) between boxes
- Layout should be responsive across all screen sizes
- When 2 boxes exist, they should take 50% width each
- When 3 boxes exist, they should take ~33% width each
- Continue this pattern for any number of boxes

### Non-Functional Requirements
- Must work with existing Tailwind CSS classes
- No JavaScript required (pure CSS solution)
- Maintain visual consistency with current design
- No impact on box card content layout (only affects container layout)

## Technical Approach

### Frontend Changes
The change is purely CSS-based and affects only the container div that wraps the storage box cards in `templates/inventory/index.html.twig`.

**Current Implementation:**
```html
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
```

**New Implementation:**
Use CSS flexbox with Tailwind utility classes:
```html
<div class="flex flex-wrap gap-4" style="display: flex; flex-wrap: wrap;">
    <!-- Each box card needs flex properties -->
    <div class="flex-1" style="min-width: 250px; max-width: 250px;">
```

**Alternative (Pure Tailwind):**
If inline styles are not preferred, create a custom CSS class or use Tailwind's arbitrary values:
```html
<div class="flex flex-wrap gap-4">
    <div class="flex-1 min-w-[250px]">
```

**Note:** The `flex-1` combined with `min-w-[250px]` will:
- Allow boxes to grow to fill space (`flex-1`)
- Prevent boxes from shrinking below 250px (`min-w-[250px]`)
- Automatically wrap to next line when container width can't fit another 250px box

### Asset Rebuild Required
After modifying the Twig template, rebuild frontend assets:
```bash
docker compose run --rm node npm run build
```

## Implementation Steps

1. **Update storage box container layout**
   - Open `templates/inventory/index.html.twig`
   - Locate line 118: `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">`
   - Replace with: `<div class="flex flex-wrap gap-4">`

2. **Update storage box card wrapper**
   - Each storage box card (currently starting at line 120) needs flex properties
   - Wrap or add `min-w-[250px] flex-1` to the outermost div of each box card
   - The card div currently starts with: `<div class="{% if box.isManualBox %}bg-gradient-to-br...`
   - Add `min-w-[250px] flex-1` to the class list

3. **Test responsive behavior**
   - View page with different numbers of boxes (1, 2, 3, 4, 5+)
   - Test on different screen widths:
     - Mobile (~375px): Should show 1 box per row
     - Tablet (~768px): Should show 2-3 boxes per row
     - Desktop (~1280px): Should show 4-5 boxes per row
     - Ultra-wide (~1920px+): Should show 6+ boxes per row
   - Verify 250px minimum width is maintained
   - Verify gaps between boxes are consistent

4. **Rebuild assets**
   - Run: `docker compose run --rm node npm run build`
   - Verify changes appear in browser

5. **Visual verification**
   - Check that boxes expand to fill space
   - Verify boxes wrap correctly when space runs out
   - Ensure no layout breaks with 1, 2, or many boxes
   - Confirm consistent spacing between boxes

## Edge Cases & Error Handling

- **Single box**: Should expand to fill width or stay at comfortable size (not stretch to 100% width)
  - Consider adding `max-w-sm` or similar to prevent single box from becoming too wide
- **Very narrow screens (<250px)**: Box may overflow, but this is an extreme edge case
  - Modern phones are typically 375px+ wide
- **No boxes**: Empty state rendering not affected
- **Mixed box types (manual vs synced)**: Visual styling (borders, colors) should remain intact

## Dependencies

### Blocking Dependencies
None - this is a standalone CSS change

### Can Be Done in Parallel With
Any other tasks - no conflicts

### External Dependencies
None - uses existing Tailwind CSS utilities

## Acceptance Criteria

- [ ] Storage box container uses flexbox layout instead of CSS grid
- [ ] Boxes stretch to fill available horizontal space
- [ ] Boxes maintain minimum 250px width
- [ ] Boxes wrap to new line when space runs out
- [ ] Gap spacing (1rem) maintained between boxes
- [ ] Tested on mobile, tablet, desktop, and ultra-wide screens
- [ ] Verified with 1, 2, 3, 5, and 10+ boxes
- [ ] No visual regressions (borders, colors, hover effects remain intact)
- [ ] Assets rebuilt and changes visible in browser
- [ ] Single box doesn't stretch excessively wide (add max-width if needed)

## Notes & Considerations

### CSS Approach Comparison

**Option A: Pure Tailwind (Recommended)**
```html
<div class="flex flex-wrap gap-4">
    <div class="min-w-[250px] flex-1 [max-width:250px] ...">
```
✅ No inline styles
✅ Tailwind-native
⚠️ `[max-width:250px]` is arbitrary value syntax

**Option B: Inline Style**
```html
<div class="flex flex-wrap gap-4">
    <div class="flex-1" style="min-width: 250px; max-width: 250px;">
```
✅ Explicit control
❌ Mixing inline styles

**Option C: Custom CSS Class**
Create a custom class in `assets/styles/app.css`:
```css
.storage-box-flex {
    min-width: 250px;
    flex: 1 1 250px;
}
```
✅ Clean separation
✅ Reusable
⚠️ Requires custom CSS file modification

**Recommendation**: Use Option A (Pure Tailwind) for consistency with the rest of the codebase.

### Single Box Max Width

If a single box stretches too wide when it's the only box on the row, consider adding a max-width:
```html
<div class="min-w-[250px] flex-1 max-w-md ...">
```
This caps the box at `max-w-md` (28rem/448px) when it's alone, but allows it to be smaller when sharing space.

### Visual Testing Targets

Test with these specific box counts:
- 1 box: Should not stretch excessively
- 2 boxes: 50% width each (minus gap)
- 3 boxes: ~33% width each
- 4 boxes: ~25% width each
- 5+ boxes: Should wrap appropriately based on screen width

### No Breaking Changes

This change only affects the layout container and card wrapper classes. All internal card content (buttons, text, badges) remains unchanged.

---

## Task Completion Instructions

When this task is fully complete:

1. **Update this file**:
   - Change `**Status**: Not Started` to `**Status**: Completed`
   - Add completion date: `**Completed**: [Date]`

2. **Move to completed folder**:
   - Move this file from `tasks/` to `tasks/completed/`
   - Keep the same filename: `57-storage-box-flexible-layout.md`

3. **Verify all acceptance criteria** are checked off before marking as complete
