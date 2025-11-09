# CSFloat Frontend Links

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-08

## Overview

Add CSFloat marketplace links to the inventory UI. Display a CSFloat icon next to the existing Steam icon, linking to CSFloat marketplace search for each item using def_index and paint_index from the ItemCsfloat mapping.

## Problem Statement

Users want to:
- Compare Steam marketplace prices with CSFloat marketplace prices
- Quickly navigate to CSFloat to view detailed listings (float values, stickers, etc.)
- Access CSFloat marketplace directly from their inventory without manual searching

Currently, only Steam marketplace links are shown. Adding CSFloat links provides users with an additional marketplace option for better price discovery.

## Requirements

### Functional Requirements
- Display CSFloat icon/link next to Steam icon on inventory item cards
- Link to CSFloat marketplace search filtered by def_index and paint_index
- Only show CSFloat link if ItemCsfloat mapping exists (item is on CSFloat)
- Open link in new tab (`target="_blank"`)
- Show tooltip on hover: "View on CSFloat"
- Handle items without CSFloat mapping gracefully (no link shown)

### Non-Functional Requirements
- Icon visually consistent with Steam icon (size, spacing)
- Icon recognizable as CSFloat (use CSFloat logo or "CF" badge)
- Links must be valid CSFloat URLs
- Fast rendering (no additional API calls, use cached data)
- Responsive (works on mobile)

## Technical Approach

### CSFloat URL Format

**Search by def_index and paint_index:**
```
https://csfloat.com/search?def_index={defIndex}&paint_index={paintIndex}&sort_by=lowest_price
```

**Example:**
```
AK-47 | Redline (def_index=7, paint_index=282)
→ https://csfloat.com/search?def_index=7&paint_index=282&sort_by=lowest_price
```

### Template Changes

**Location:** `templates/inventory/index.html.twig` (or wherever inventory items are displayed)

**Current Structure (assumed):**
```twig
<div class="item-card">
  <img src="{{ item.imageUrl }}" alt="{{ item.name }}">
  <h3>{{ item.name }}</h3>
  <div class="marketplace-links">
    <a href="https://steamcommunity.com/market/listings/730/{{ item.hashName|url_encode }}"
       target="_blank"
       title="View on Steam">
      <img src="/images/steam-icon.png" alt="Steam" class="w-5 h-5">
    </a>
  </div>
</div>
```

**New Structure:**
```twig
<div class="item-card">
  <img src="{{ item.imageUrl }}" alt="{{ item.name }}">
  <h3>{{ item.name }}</h3>
  <div class="marketplace-links flex gap-2">
    {# Steam Icon #}
    <a href="https://steamcommunity.com/market/listings/730/{{ item.hashName|url_encode }}"
       target="_blank"
       title="View on Steam"
       class="hover:opacity-80 transition">
      <img src="/images/steam-icon.png" alt="Steam" class="w-5 h-5">
    </a>

    {# CSFloat Icon (only if mapping exists) #}
    {% if item.csfloatMapping and item.csfloatMapping.defIndex and item.csfloatMapping.paintIndex %}
      <a href="https://csfloat.com/search?def_index={{ item.csfloatMapping.defIndex }}&paint_index={{ item.csfloatMapping.paintIndex }}&sort_by=lowest_price"
         target="_blank"
         title="View on CSFloat"
         class="hover:opacity-80 transition">
        <img src="/images/csfloat-icon.png" alt="CSFloat" class="w-5 h-5">
      </a>
    {% endif %}
  </div>
</div>
```

### Icon Asset

**Option 1: CSFloat Logo**
- Download CSFloat logo from their website or branding page
- Resize to 20x20px or 24x24px (match Steam icon size)
- Save as: `public/images/csfloat-icon.png`

**Option 2: Custom Badge**
- Create simple "CF" badge with Tailwind CSS:
  ```html
  <span class="inline-flex items-center justify-center w-5 h-5 bg-blue-500 text-white text-xs font-bold rounded">
    CF
  </span>
  ```

**Option 3: SVG Icon**
- Use inline SVG for scalability:
  ```html
  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
    <!-- CSFloat logo path -->
  </svg>
  ```

### Twig Extension (Optional)

Create Twig extension for generating CSFloat URLs:

**Location:** `src/Twig/CsfloatExtension.php`

```php
namespace App\Twig;

use App\Entity\ItemCsfloat;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CsfloatExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csfloat_url', [$this, 'getCsfloatUrl']),
        ];
    }

    public function getCsfloatUrl(?ItemCsfloat $mapping): ?string
    {
        if (!$mapping || !$mapping->getDefIndex() || !$mapping->getPaintIndex()) {
            return null;
        }

        return sprintf(
            'https://csfloat.com/search?def_index=%d&paint_index=%d&sort_by=lowest_price',
            $mapping->getDefIndex(),
            $mapping->getPaintIndex()
        );
    }
}
```

**Usage in template:**
```twig
{% set csfloatUrl = csfloat_url(item.csfloatMapping) %}
{% if csfloatUrl %}
  <a href="{{ csfloatUrl }}" target="_blank" title="View on CSFloat">
    <img src="/images/csfloat-icon.png" alt="CSFloat" class="w-5 h-5">
  </a>
{% endif %}
```

### Controller Changes

**Inventory Controller:**
Ensure Item entities are loaded with `csfloatMapping` relationship:

```php
// In InventoryController::index()
$items = $itemRepository->findByUser($user);

// Twig template will lazy-load csfloatMapping via Doctrine
// OR explicitly join:
$items = $itemRepository->createQueryBuilder('i')
    ->leftJoin('i.csfloatMapping', 'cf')
    ->addSelect('cf')
    ->where('i.user = :user')
    ->setParameter('user', $user)
    ->getQuery()
    ->getResult();
```

### Storage Box Controller

**Storage Box Items View:**
Same pattern as inventory - add CSFloat link next to Steam link in storage box item listings.

**Location:** `templates/storage_box/view.html.twig`

## Implementation Steps

1. **Add CSFloat Icon Asset**
   - Download CSFloat logo or create custom icon
   - Resize to 20x20px or 24x24px
   - Save as `public/images/csfloat-icon.png`
   - OR create CSS/SVG badge

2. **Create Twig Extension (Optional)**
   - Create `src/Twig/CsfloatExtension.php`
   - Add `csfloat_url()` function
   - Register extension in `services.yaml` (should auto-register)

3. **Update Inventory Template**
   - Open `templates/inventory/index.html.twig`
   - Find marketplace links section
   - Add CSFloat link with conditional rendering
   - Ensure Item relationship includes csfloatMapping

4. **Update Storage Box Template**
   - Open `templates/storage_box/view.html.twig`
   - Add CSFloat link to item cards
   - Same conditional rendering pattern

5. **Update Inventory Import Preview Template** (if applicable)
   - Open `templates/inventory_import/preview.html.twig`
   - Add CSFloat link to preview items
   - Show CSFloat link for items that will remain after import

6. **Optimize Query Performance**
   - Update InventoryController to eager-load csfloatMapping:
     ```php
     $items = $itemRepository->createQueryBuilder('i')
         ->leftJoin('i.csfloatMapping', 'cf')
         ->addSelect('cf')
         ->where('i.user = :user')
         ->setParameter('user', $user)
         ->getQuery()
         ->getResult();
     ```
   - Same for StorageBoxController

7. **Add Hover Tooltip**
   - Use Tailwind CSS `title` attribute (native browser tooltip)
   - OR use JavaScript tooltip library for richer tooltips

8. **Rebuild Frontend Assets**
   ```bash
   docker compose run --rm node npm run build
   ```

9. **Test Responsiveness**
   - Verify icons are properly sized on mobile
   - Ensure links don't overlap or break layout
   - Test with long item names

10. **Add Analytics (Optional)**
    - Track CSFloat link clicks using onclick event:
      ```html
      <a href="..." onclick="trackCsfloatClick('{{ item.name }}')">
      ```

## Edge Cases & Error Handling

- **Item without CSFloat mapping**: Don't show CSFloat link (use `{% if %}` conditional)
- **NULL def_index or paint_index**: Don't show link (check both fields)
- **Very long item names**: Ensure marketplace links don't overflow container
- **Mobile view**: Icons should be tappable (min 44x44px touch target)
- **No icon asset found**: Show text link "CF" instead of broken image
- **Item not on CSFloat**: No link shown (expected, not an error)
- **CSFloat URL changes**: Update URL pattern in Twig extension
- **User has no items**: No marketplace links to show (expected)

## Dependencies

### Blocking Dependencies
- Task 44: CSFloat database foundation (ItemCsfloat entity must exist)
- Task 46: CSFloat sync command (must populate ItemCsfloat data)

### Related Tasks (CSFloat Integration Feature)
- Task 45: CSFloat API service (not blocking, but ensures data quality)
- Task 47: Admin settings UI (provides sync trigger, not blocking)

### Can Be Done in Parallel With
- Task 47: Admin settings UI (both use ItemCsfloat data)

### External Dependencies
- Tailwind CSS (already in project)
- Twig (already in project)
- CSFloat icon asset (need to obtain or create)

## Acceptance Criteria

- [ ] CSFloat icon asset added to `public/images/`
- [ ] Twig extension created with `csfloat_url()` function
- [ ] Inventory template updated with CSFloat link
- [ ] Storage box template updated with CSFloat link
- [ ] CSFloat link only shown if mapping exists
- [ ] Links open in new tab (`target="_blank"`)
- [ ] Tooltip shows "View on CSFloat" on hover
- [ ] Icons properly sized (match Steam icon)
- [ ] Icons aligned horizontally with Steam icon
- [ ] Query optimized to eager-load csfloatMapping
- [ ] Responsive on mobile (icons are tappable)
- [ ] Frontend assets rebuilt (`npm run build`)
- [ ] Links tested and working (navigate to CSFloat search)
- [ ] No broken images or layout issues

## Manual Verification Steps

### 1. Add Test Data

```bash
# Ensure some items have CSFloat mappings
docker compose exec php php bin/console app:csfloat:sync-items --limit=10

# Verify mappings exist
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
SELECT i.market_name, cf.def_index, cf.paint_index
FROM item i
JOIN item_csfloat cf ON i.id = cf.item_id
LIMIT 5;
"
```

### 2. View Inventory

```bash
# Login to application
# Navigate to: http://localhost/inventory
# Should see items with both Steam and CSFloat icons
```

### 3. Test CSFloat Links

1. **Click CSFloat icon** on an item
2. Should open new tab
3. Should navigate to CSFloat search page
4. Should show correct item (verify by market_hash_name)

### 4. Test Items Without Mapping

```bash
# Delete one mapping
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
DELETE FROM item_csfloat WHERE id=1;
"

# Reload inventory
# Item should show only Steam icon, no CSFloat icon
```

### 5. Test Hover Tooltip

1. Hover over CSFloat icon
2. Should see tooltip: "View on CSFloat"
3. Should appear within 1 second

### 6. Test Responsive Design

1. Open inventory in browser
2. Resize to mobile width (375px)
3. Icons should be visible and tappable
4. Icons should not overlap or break layout

### 7. Test Storage Box View

```bash
# Navigate to a storage box with items
# Should see CSFloat links on box items (if mapped)
```

### 8. Verify Query Performance

```bash
# Enable Symfony profiler
# Navigate to inventory
# Check profiler toolbar → Doctrine queries
# Should see LEFT JOIN on csfloatMapping (eager loading)
# Should NOT see N+1 queries for csfloatMapping
```

### 9. Test Link Format

**Expected URL format:**
```
https://csfloat.com/search?def_index=7&paint_index=282&sort_by=lowest_price
```

**Inspect link in browser:**
1. Right-click CSFloat icon
2. Copy link address
3. Verify format matches expected pattern

### 10. Test Multiple Items

1. View inventory with 20+ items
2. Verify CSFloat links show for mapped items
3. Verify no broken images or missing icons
4. Verify performance (page loads in < 2 seconds)

## Notes & Considerations

- **Icon Source**: Obtain CSFloat logo from official branding or create simple "CF" badge
- **Icon Size**: Match Steam icon size exactly (typically 20x20px or 24x24px)
- **Performance**: Eager-load csfloatMapping to avoid N+1 queries
- **Fallback**: If icon fails to load, show text "CF" instead
- **Future Enhancements**:
  - Show CSFloat lowest price next to icon (requires pricing data)
  - Add badge: "New listing!" if recent listing matches user's item
  - Compare Steam price vs CSFloat price (highlight better deal in green)
  - Show CSFloat link in item detail modal/page
  - Add "Copy CSFloat URL" button for sharing
- **Mobile UX**: Ensure touch targets are at least 44x44px for accessibility
- **Analytics**: Track CSFloat link clicks to measure feature usage
- **External Link Icon**: Consider adding ↗ icon to indicate external link

## Related Tasks

- Task 44: CSFloat database foundation (blocking)
- Task 46: CSFloat sync command (blocking - populates data)
- Task 45: CSFloat API service (ensures data quality)
- Task 47: Admin settings UI (provides sync trigger)
