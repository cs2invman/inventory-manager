# Responsive Navigation Component with Mobile Hamburger Menu

**Status**: Completed
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-18
**Completed**: 2025-11-18

## Overview

Refactor the navigation from `base.html.twig` into a reusable component (`templates/components/navigation.html.twig`) and implement a mobile-friendly hamburger menu. The navigation should be full-width, with logo on the left and hamburger menu on the right for mobile devices, while maintaining the current horizontal layout for desktop.

## Problem Statement

Current navigation issues:
1. **Mobile navigation broken**: On mobile, only Admin, Settings, and Logout buttons are visible; main navigation links (Inventory, Ledger, Items) are hidden
2. **Not full-width**: Navigation uses `max-w-7xl mx-auto` container, creating side margins instead of spanning full width
3. **Not a component**: Navigation lives directly in `base.html.twig` instead of following the established component pattern (see `templates/components/`)

User requirements:
- Mobile: Hamburger button on right, logo on left, all nav links in slide-in panel
- Desktop: Keep current horizontal navigation layout
- Full-width navigation bar
- Follow Symfony/Twig best practices by extracting to component

## Requirements

### Functional Requirements
- **Mobile (< 768px)**:
  - Show logo on left, hamburger icon on right
  - Hamburger opens slide-in panel from right side
  - Panel contains ALL navigation links: Inventory, Ledger, Items, Admin (if authorized), Settings, Logout
  - Clicking outside panel closes it
  - Clicking any navigation link auto-closes the panel
  - Panel should overlay the page content

- **Desktop (>= 768px)**:
  - Keep current horizontal navigation layout
  - Logo on left, main nav links in center area, Admin/Settings/Logout on right
  - No hamburger menu visible

- **Full-width navigation**:
  - Remove `max-w-7xl mx-auto` container constraint
  - Navigation spans entire viewport width
  - Content inside can still have reasonable padding/margins

### Non-Functional Requirements
- **Alpine.js for interactivity**: Use existing Alpine.js (already loaded in base.html.twig) for hamburger toggle state
- **Tailwind responsive utilities**: Use `md:` breakpoints for desktop/mobile differences
- **Accessibility**: Hamburger button should have proper ARIA labels
- **Maintain active link highlighting**: Keep existing `.nav-link.active` behavior
- **Component pattern**: Follow existing pattern from `templates/components/item_card.html.twig`

## Technical Approach

### Component Structure
Create `templates/components/navigation.html.twig` as a standalone, reusable component that:
- Accepts no parameters (reads from `app.user` and `app.request` directly)
- Can be included via `{{ include('components/navigation.html.twig') }}`
- Contains all navigation logic and markup

### Alpine.js State Management
Use Alpine.js `x-data` directive to manage hamburger menu state:
```html
<nav x-data="{ mobileMenuOpen: false }">
  <!-- Hamburger button toggles mobileMenuOpen -->
  <!-- Slide-in panel visibility controlled by mobileMenuOpen -->
  <!-- Click outside closes by setting mobileMenuOpen = false -->
</nav>
```

### Responsive Breakpoints
- Mobile: `< md` (< 768px)
- Desktop: `md:` and above (>= 768px)

### CSS Classes
Reuse existing Tailwind/custom classes:
- `.nav-link` (already defined in `assets/styles/app.css:78-83`)
- `.nav-link.active` (active state)
- `.text-cs2-orange`, `.bg-gray-800`, `.shadow-steam` (custom theme colors)

## Implementation Steps

1. **Create navigation component file**
   - Create `templates/components/navigation.html.twig`
   - Add Alpine.js data attribute: `x-data="{ mobileMenuOpen: false }"`

2. **Build full-width navigation container**
   - Remove `max-w-7xl mx-auto` wrapper
   - Use `px-4 sm:px-6 lg:px-8` for horizontal padding
   - Keep `bg-gray-800 shadow-steam` styling

3. **Implement mobile layout (< md breakpoint)**
   - **Logo on left**: Brand link wrapped in flex container
   - **Hamburger button on right**:
     - Only visible on mobile: `class="md:hidden"`
     - Toggle button: `@click="mobileMenuOpen = !mobileMenuOpen"`
     - Icon: Use SVG or Unicode for hamburger (☰) and close (✕) icons
     - ARIA label: `aria-label="Toggle navigation menu"`
   - **Slide-in panel**:
     - Positioned fixed, right side, full height
     - Transform/translate for slide animation: `x-show="mobileMenuOpen"` + `x-transition`
     - Overlay backdrop: Semi-transparent dark overlay behind panel
     - Close on outside click: `@click.away="mobileMenuOpen = false"`
     - Close on link click: `@click="mobileMenuOpen = false"` on each nav link

4. **Implement desktop layout (>= md breakpoint)**
   - Keep current structure: logo left, main nav center-left, user nav right
   - Hide hamburger: `class="hidden md:flex"`
   - Show horizontal nav links: `class="hidden md:flex md:items-baseline md:space-x-4"`
   - Show right-side links: Admin, Settings, Logout

5. **Navigation links structure**
   - **Main navigation** (Inventory, Ledger, Items):
     - Mobile: Inside slide-in panel, stacked vertically
     - Desktop: Horizontal layout with `space-x-4`
   - **User navigation** (Admin, Settings, Logout):
     - Mobile: Inside slide-in panel, stacked below main nav (maybe separated with border)
     - Desktop: Right side, horizontal layout

6. **Active link highlighting**
   - Maintain existing logic:
     ```twig
     class="nav-link {% if app.request.get('_route') starts with 'inventory' %}active{% endif %}"
     ```
   - Ensure `.nav-link` and `.nav-link.active` classes work in both mobile and desktop

7. **Integrate component into base.html.twig**
   - Replace existing `<nav>...</nav>` block (lines 25-57) with:
     ```twig
     {{ include('components/navigation.html.twig') }}
     ```
   - Test that Alpine.js is still loaded (already in base.html.twig:17)

8. **Rebuild frontend assets**
   - Run: `docker compose run --rm node npm run build`
   - If adding new Tailwind classes, this step is critical

## Edge Cases & Error Handling

- **Alpine.js not loaded**: Component relies on Alpine.js being available. Already loaded in base.html.twig, but document dependency in component comments
- **Long usernames**: Ensure overflow handling in mobile menu (text truncation if needed)
- **Many navigation links**: If more links added in future, ensure mobile panel is scrollable
- **ROLE_ADMIN check**: Maintain existing `{% if is_granted('ROLE_ADMIN') %}` logic for Admin link
- **Active route detection**: Ensure route matching works for all navigation sections (e.g., `starts with 'inventory'`, `starts with 'admin'`, etc.)
- **Z-index layering**: Mobile panel and overlay should appear above all page content
- **Body scroll lock**: Consider whether to prevent body scrolling when mobile menu is open (not critical for MVP, but nice enhancement)

## Dependencies

### Blocking Dependencies
None - this is a standalone frontend change

### Related Tasks
None - isolated UI improvement

### External Dependencies
- **Alpine.js**: Already loaded in `base.html.twig:17` via CDN
- **Tailwind CSS**: Already configured and built
- **Existing CSS classes**: `.nav-link`, `.nav-link.active` (defined in `assets/styles/app.css`)

## Acceptance Criteria

- [ ] Navigation extracted to `templates/components/navigation.html.twig`
- [ ] `base.html.twig` includes the component via `{{ include('components/navigation.html.twig') }}`
- [ ] Navigation is full-width (no `max-w-7xl` constraint)
- [ ] **Mobile (< 768px)**:
  - [ ] Logo visible on left
  - [ ] Hamburger button visible on right
  - [ ] Clicking hamburger opens slide-in panel from right
  - [ ] Panel contains all nav links: Inventory, Ledger, Items, Admin, Settings, Logout
  - [ ] Clicking outside panel closes it
  - [ ] Clicking any nav link closes the panel
  - [ ] Active link highlighting works in mobile menu
- [ ] **Desktop (>= 768px)**:
  - [ ] Horizontal navigation layout maintained
  - [ ] All links visible (no hamburger)
  - [ ] Logo left, main nav center-left, user nav right
  - [ ] Active link highlighting works
- [ ] Admin link only shows for users with `ROLE_ADMIN`
- [ ] Logout button styled correctly (`.btn-secondary` class)
- [ ] Navigation styling matches existing theme (CS2 orange, gray-800, etc.)
- [ ] No JavaScript errors in browser console
- [ ] Component follows existing patterns from `templates/components/`

## Notes & Considerations

- **Alpine.js syntax**: Component uses Alpine.js for state management (toggle, transitions). Alpine.js documentation: https://alpinejs.dev/
- **Tailwind responsive design**: Uses `md:` breakpoint (768px) as mobile/desktop threshold
- **Component pattern**: Follows Symfony/Twig best practice of extracting reusable UI blocks into components
- **Future extensibility**: If navigation becomes more complex (dropdowns, nested menus), this component structure makes it easy to extend
- **Performance**: No performance concerns; Alpine.js is lightweight and already loaded
- **Browser compatibility**: Alpine.js and Tailwind both support modern browsers (IE11+ with polyfills if needed)

## Related Tasks

None - this is a standalone UI improvement

---

## Task Completion Instructions

When this task is fully complete:

1. **Update this file**:
   - Change `**Status**: Not Started` to `**Status**: Completed`
   - Add completion date: `**Completed**: [Date]`

2. **Move to completed folder**:
   - Move this file from `tasks/` to `tasks/completed/`
   - Keep the same filename

3. **Verify all acceptance criteria** are checked off before marking as complete