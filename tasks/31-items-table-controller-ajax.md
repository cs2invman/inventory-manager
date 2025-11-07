# Items Table - Controller and AJAX Endpoint

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-07

## Overview

Create the controller with AJAX endpoint to handle server-side filtering, sorting, and pagination for the Items table. This controller uses the backend service layer (Task 30) to fetch data and returns JSON responses for frontend AJAX calls.

## Problem Statement

The Items table frontend needs a backend API endpoint that:
- Accepts filter parameters (search text, category, type, rarity, etc.)
- Accepts sorting parameters (column, direction)
- Accepts pagination parameters (page, perPage)
- Returns JSON with items data, prices, trends, and pagination metadata
- Handles invalid inputs gracefully
- Validates sort columns against whitelist (security)
- Respects user's currency preference

## Requirements

### Functional Requirements
- Route: `/items` (GET) - Main page with empty table skeleton
- Route: `/items/data` (GET) - AJAX endpoint returning JSON
- Accept query parameters:
  - `search`: text search term (optional)
  - `category`: category filter (optional)
  - `subcategory`: subcategory filter (optional)
  - `type`: type filter (optional)
  - `rarity`: rarity filter (optional)
  - `stattrak`: "1" or "0" for stattrakAvailable filter (optional)
  - `souvenir`: "1" or "0" for souvenirAvailable filter (optional)
  - `sortBy`: column name (default: "name")
  - `sortDirection`: "asc" or "desc" (default: "asc")
  - `page`: page number (default: 1)
  - `perPage`: items per page (default: 25, max: 100)
- Return JSON response with structure:
  ```json
  {
    "items": [
      {
        "id": 123,
        "name": "AK-47 | Redline",
        "imageUrl": "https://...",
        "type": "CSGO_Type_Rifle",
        "category": "Weapon",
        "subcategory": "Rifle",
        "rarity": "Classified",
        "rarityColor": "d32ce6",
        "stattrakAvailable": true,
        "souvenirAvailable": false,
        "price": 12.34,
        "volume": 150,
        "updatedAt": "2025-11-07T12:34:56+00:00",
        "trend7d": 5.2,
        "trend30d": -3.1,
        "currencySymbol": "$"
      }
    ],
    "pagination": {
      "total": 1234,
      "page": 1,
      "perPage": 25,
      "totalPages": 50,
      "hasMore": true
    },
    "filters": {
      "available": {
        "categories": ["Weapon", "Sticker", "Charm", ...],
        "subcategories": ["Rifle", "Pistol", "SMG", ...],
        "types": ["CSGO_Type_Pistol", "CSGO_Type_Rifle", ...],
        "rarities": ["Covert", "Classified", "Restricted", ...]
      },
      "active": {
        "search": "dragon lore",
        "category": "Weapon",
        "subcategory": null,
        ...
      }
    }
  }
  ```
- Initial page load (`/items`) renders filter UI and empty table skeleton
- Frontend calls `/items/data` via AJAX to populate table

### Non-Functional Requirements
- Response time: <500ms for paginated data
- Security: Validate and sanitize all inputs
- Error handling: Return meaningful error messages (400 Bad Request, 500 Internal Error)
- Logging: Log errors and slow queries
- Currency support: Apply user's currency preference to prices

## Technical Approach

### Controller Structure

Create: `src/Controller/ItemsController.php`

#### Route 1: Main Page (`/items`)
```php
#[Route('/items', name: 'items_index', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
public function index(): Response
```

**Responsibilities:**
- Render initial page with Twig template (`templates/items/index.html.twig`)
- Pass filter dropdown data to template:
  - Available categories (from ItemRepository)
  - Available subcategories
  - Available types
  - Available rarities
- Pass user's currency preference (from UserConfig)
- Table skeleton rendered, no item data (loaded via AJAX)

#### Route 2: AJAX Data Endpoint (`/items/data`)
```php
#[Route('/items/data', name: 'items_data', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
public function data(Request $request, ItemTableService $itemTableService): JsonResponse
```

**Responsibilities:**
1. **Extract Query Parameters:**
   ```php
   $search = $request->query->get('search', '');
   $category = $request->query->get('category', '');
   $subcategory = $request->query->get('subcategory', '');
   $type = $request->query->get('type', '');
   $rarity = $request->query->get('rarity', '');
   $stattrak = $request->query->get('stattrak', '');
   $souvenir = $request->query->get('souvenir', '');
   $sortBy = $request->query->get('sortBy', 'name');
   $sortDirection = $request->query->get('sortDirection', 'asc');
   $page = $request->query->getInt('page', 1);
   $perPage = $request->query->getInt('perPage', 25);
   ```

2. **Validate Inputs:**
   - **sortBy whitelist:** `['name', 'category', 'subcategory', 'type', 'rarity', 'price', 'volume', 'updatedAt', 'trend7d', 'trend30d']`
   - If sortBy not in whitelist, default to 'name' and log warning
   - **sortDirection:** must be 'asc' or 'desc' (case-insensitive), default to 'asc'
   - **page:** must be >= 1, default to 1
   - **perPage:** must be 1-100, default to 25
   - **search:** trim whitespace, ignore if < 2 characters
   - **Boolean filters:** convert 'stattrak' and 'souvenir' to boolean (only if "1" or "0")

3. **Build Filters Array:**
   ```php
   $filters = ['active' => true]; // Always filter active items

   if (strlen($search) >= 2) {
       $filters['search'] = $search;
   }
   if ($category) {
       $filters['category'] = $category;
   }
   if ($subcategory) {
       $filters['subcategory'] = $subcategory;
   }
   if ($type) {
       $filters['type'] = $type;
   }
   if ($rarity) {
       $filters['rarity'] = $rarity;
   }
   if ($stattrak === '1') {
       $filters['stattrakAvailable'] = true;
   }
   if ($souvenir === '1') {
       $filters['souvenirAvailable'] = true;
   }
   ```

4. **Call Service Layer:**
   ```php
   try {
       $result = $itemTableService->getItemsTableData(
           $filters,
           $sortBy,
           strtoupper($sortDirection),
           $page,
           $perPage
       );
   } catch (\Exception $e) {
       $this->logger->error('Items table data fetch failed', [
           'error' => $e->getMessage(),
           'filters' => $filters,
           'sortBy' => $sortBy,
           'page' => $page,
       ]);
       return $this->json(['error' => 'Failed to fetch items data'], 500);
   }
   ```

5. **Get User's Currency:**
   ```php
   $user = $this->getUser();
   $currencyCode = $user->getConfig()?->getCurrency() ?? 'USD';
   $currencySymbol = $currencyCode === 'USD' ? '$' : '$'; // Extend for CAD
   ```

6. **Format Response Data:**
   - Transform Item entities to JSON-friendly arrays
   - Include currency symbol in each item
   - Apply currency conversion if needed (use CurrencyService)
   - Format dates to ISO 8601 (DateTimeImmutable::format('c'))
   - Round prices to 2 decimal places
   - Round trends to 1 decimal place

7. **Return JSON Response:**
   ```php
   return $this->json([
       'items' => $formattedItems,
       'pagination' => $result['pagination'],
       'filters' => [
           'available' => $this->getAvailableFilters(),
           'active' => $filters,
       ],
   ]);
   ```

### Helper Methods

#### `getAvailableFilters()`
Private method to fetch all filter dropdown options.

```php
private function getAvailableFilters(): array
{
    return [
        'categories' => $this->itemRepository->findAllCategories(),
        'subcategories' => $this->itemRepository->findAllSubcategories(),
        'types' => $this->itemRepository->findAllTypes(),
        'rarities' => $this->itemRepository->findAllRarities(),
    ];
}
```

#### `formatItemForJson()`
Private method to transform Item entity to JSON array.

```php
private function formatItemForJson(
    array $itemData,
    string $currencySymbol,
    ?float $exchangeRate = null
): array
{
    $item = $itemData['item']; // Item entity

    $price = $itemData['latestPrice'];
    if ($price !== null && $exchangeRate !== null) {
        $price = $price * $exchangeRate;
    }

    return [
        'id' => $item->getId(),
        'name' => $item->getName(),
        'marketName' => $item->getMarketName(),
        'imageUrl' => $item->getImageUrl(),
        'type' => $item->getType(),
        'category' => $item->getCategory(),
        'subcategory' => $item->getSubcategory(),
        'rarity' => $item->getRarity(),
        'rarityColor' => $item->getRarityColor(),
        'stattrakAvailable' => $item->isStattrakAvailable(),
        'souvenirAvailable' => $item->isSouvenirAvailable(),
        'price' => $price !== null ? round($price, 2) : null,
        'volume' => $itemData['volume'],
        'updatedAt' => $itemData['priceDate']?->format('c'),
        'trend7d' => $itemData['trend7d'] !== null ? round($itemData['trend7d'], 1) : null,
        'trend30d' => $itemData['trend30d'] !== null ? round($itemData['trend30d'], 1) : null,
        'currencySymbol' => $currencySymbol,
    ];
}
```

### Dependencies Injection

Constructor injection:
```php
public function __construct(
    private ItemTableService $itemTableService,
    private ItemRepository $itemRepository,
    private CurrencyService $currencyService,
    private LoggerInterface $logger
) {}
```

### Route Configuration

Add to `config/routes.yaml` (if not using attributes):
```yaml
items_index:
    path: /items
    controller: App\Controller\ItemsController::index
    methods: GET

items_data:
    path: /items/data
    controller: App\Controller\ItemsController::data
    methods: GET
```

## Implementation Steps

1. **Create Controller File**
   - Create `src/Controller/ItemsController.php`
   - Extend `AbstractController`
   - Add security attribute `#[IsGranted('ROLE_USER')]` at class level

2. **Implement `index()` Method**
   - Fetch available filters using repository methods
   - Get user's currency from UserConfig
   - Render `templates/items/index.html.twig` (created in Task 32)
   - Pass filter options and currency to template

3. **Implement `data()` Method**
   - Extract and validate all query parameters
   - Build filters array
   - Call ItemTableService
   - Format items for JSON response
   - Apply currency conversion
   - Return JSON with proper structure

4. **Add Helper Methods**
   - Implement `getAvailableFilters()`
   - Implement `formatItemForJson()`
   - Add input validation logic

5. **Test Endpoint Manually**
   - Use browser dev tools or curl to test `/items/data`
   - Test with various filter combinations
   - Test pagination (page 1, 2, 100, 999999)
   - Test sorting by each column (asc/desc)
   - Test invalid inputs (invalid sortBy, negative page, etc.)
   - Verify JSON response structure matches spec

6. **Error Handling**
   - Add try-catch around service calls
   - Log errors with context (filters, sort, page)
   - Return appropriate HTTP status codes (400, 500)

7. **Performance Testing**
   - Test with 25k items in database
   - Measure response time with Symfony profiler
   - Optimize if response time > 500ms

## Edge Cases & Error Handling

- **Empty search term**: Ignore filter, return all items (paginated)
- **Search term < 2 chars**: Ignore filter (avoid slow LIKE queries)
- **Invalid sortBy column**: Default to 'name', log warning
- **Invalid sortDirection**: Default to 'asc'
- **Page out of range**: Return empty items array with pagination metadata
- **perPage > 100**: Cap at 100 items per page
- **perPage < 1**: Default to 25
- **No items match filters**: Return empty array with total=0
- **Service layer exception**: Catch, log, return 500 error with generic message
- **User not logged in**: Handled by IsGranted attribute (redirect to login)
- **User has no UserConfig**: Default to USD currency
- **No price data for item**: Return null for price fields in JSON

## Dependencies

### Blocking Dependencies
- Task 30: Items table backend repository and service layer (MUST BE COMPLETED FIRST)

### Related Tasks (same feature)
- Task 32: Items table frontend UI and interactivity (depends on this task)

### Can Be Done in Parallel With
- None (this depends on Task 30, and Task 32 depends on this)

### External Dependencies
- ItemTableService (from Task 30)
- ItemRepository (extended in Task 30)
- CurrencyService (from Task 23)
- UserConfig entity (for currency preference)

## Acceptance Criteria

- [ ] Route `/items` renders page with filter UI (no items yet)
- [ ] Route `/items/data` returns JSON with correct structure
- [ ] Query parameters extracted and validated correctly
- [ ] Invalid inputs handled gracefully (no 500 errors)
- [ ] sortBy whitelist enforced (invalid columns rejected)
- [ ] Pagination works: ?page=1, ?page=2, etc.
- [ ] Filtering works: ?category=Weapon returns only weapons
- [ ] Multiple filters work: ?category=Weapon&rarity=Covert
- [ ] Text search works: ?search=dragon returns matching items
- [ ] Sorting works: ?sortBy=price&sortDirection=desc
- [ ] Boolean filters work: ?stattrak=1 returns StatTrak-capable items
- [ ] Currency conversion applied based on user's UserConfig
- [ ] Response includes pagination metadata (total, totalPages, hasMore)
- [ ] Response includes available filters for dropdowns
- [ ] Empty results return valid JSON with total=0
- [ ] Errors logged with context (filters, sort, page)
- [ ] 500 errors return generic error message (no stack traces exposed)
- [ ] Response time <500ms for typical queries
- [ ] Manual verification: Test all query parameter combinations
- [ ] Manual verification: Test with different users (different currencies)

## Notes & Considerations

### Security
- **SQL Injection**: Protected by Doctrine parameterized queries
- **XSS**: JSON data properly escaped when rendered in frontend
- **CSRF**: Not needed for GET requests with no side effects
- **Column name validation**: sortBy whitelist prevents SQL injection via ORDER BY
- **Access control**: IsGranted ensures only logged-in users can access

### Performance
- **Response caching**: Consider HTTP cache headers (Cache-Control, ETag)
- **Pagination limits**: Prevent excessive perPage values (max 100)
- **Query optimization**: Rely on Task 30's optimized queries

### Future Improvements
- Add rate limiting to prevent API abuse
- Add response compression (gzip) for large JSON payloads
- Support multiple sort columns (e.g., sort by category, then price)
- Add "export to CSV" endpoint using same filters
- Add "favorites" feature (user-specific wishlist)

### Known Limitations
- No support for OR logic in filters (only AND)
- Text search is simple LIKE (not full-text search)
- Currency conversion happens at request time (no caching)
- Trends calculated on-the-fly (can be slow for many items)

### Currency Handling
- Fetch user's currency from UserConfig
- Use CurrencyService to get exchange rate
- Apply conversion to all price fields (price, not trends - trends are %)
- Pass currency symbol to frontend for display
- If user has no UserConfig or currency not set, default to USD

## Related Tasks

- Task 30: Items table backend repository and service layer (BLOCKING - must be completed first)
- Task 32: Items table frontend UI and interactivity (depends on this task - must wait for completion)
