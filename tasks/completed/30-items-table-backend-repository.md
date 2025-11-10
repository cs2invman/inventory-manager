# Items Table - Backend Repository and Service Layer

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-07

## Overview

Create the backend repository methods and service layer logic to support the Items table feature. This provides efficient database queries with filtering, sorting, pagination, and price trend calculations for displaying all 25,000+ Steam marketplace items.

## Problem Statement

The application needs to display a searchable, sortable, paginated table of all CS2 marketplace items (not just user inventory). The current `ItemRepository` has basic search methods but lacks:
- Server-side pagination support
- Multi-column filtering (category, type, rarity, subcategory)
- Efficient sorting by various fields (price, volume, name, trends)
- Price trend calculations (7-day, 30-day percentage changes)
- Aggregated data combining Item, ItemPrice, and volume information
- Query optimization for handling 25k+ rows with minimal memory usage

## Requirements

### Functional Requirements
- Query items with server-side pagination (offset + limit)
- Filter items by multiple criteria simultaneously:
  - Text search: fuzzy match on name, marketName, hashName
  - Category: exact match (e.g., "Weapon", "Sticker", "Charm")
  - Subcategory: exact match (e.g., "Rifle", "Pistol", "SMG")
  - Type: exact match (e.g., "CSGO_Type_Knife", "CSGO_Type_Pistol")
  - Rarity: exact match (e.g., "Covert", "Classified", "Restricted")
  - Quality: StatTrak/Souvenir available (boolean filters)
- Sort by any column: name, category, subcategory, type, rarity, price, volume, updatedAt, trend7d, trend30d
- Support ascending and descending sort directions
- Calculate 7-day and 30-day price trends (percentage change)
- Include latest price and volume data for each item
- Return total count for pagination UI

### Non-Functional Requirements
- Performance: Queries must complete in <500ms even with 25k rows
- Memory: Use LIMIT/OFFSET to avoid loading all items into memory
- Database indexes: Leverage existing indexes on type, category, rarity, active
- Security: Prevent SQL injection via parameterized queries (Doctrine handles this)
- Scalability: Support future growth beyond 25k items

## Technical Approach

### Repository Methods (ItemRepository)

Add these methods to `src/Repository/ItemRepository.php`:

#### 1. `findAllWithFiltersAndPagination()`
Main method for fetching paginated, filtered, sorted items.

**Signature:**
```php
public function findAllWithFiltersAndPagination(
    array $filters = [],
    string $sortBy = 'name',
    string $sortDirection = 'ASC',
    int $limit = 25,
    int $offset = 0
): array
```

**Filters array structure:**
```php
[
    'search' => 'dragon lore',           // Optional: fuzzy text search
    'category' => 'Weapon',              // Optional: exact match
    'subcategory' => 'Rifle',            // Optional: exact match
    'type' => 'CSGO_Type_Pistol',        // Optional: exact match
    'rarity' => 'Covert',                // Optional: exact match
    'stattrakAvailable' => true,         // Optional: boolean
    'souvenirAvailable' => false,        // Optional: boolean
    'active' => true,                    // Default: only active items
]
```

**Returns:** Array of Item entities

**Query Logic:**
- Use QueryBuilder for dynamic WHERE clauses
- Apply filters only if present in $filters array
- Text search: `WHERE (i.name LIKE :search OR i.marketName LIKE :search OR i.hashName LIKE :search)`
- Use `%search%` pattern for fuzzy matching
- Ensure `active = true` by default (don't show inactive items)
- Apply ORDER BY based on $sortBy and $sortDirection
- Use `setFirstResult($offset)` and `setMaxResults($limit)`

#### 2. `countWithFilters()`
Count total items matching filters (for pagination).

**Signature:**
```php
public function countWithFilters(array $filters = []): int
```

**Returns:** Total count of items matching filters

**Query Logic:**
- Same WHERE clauses as `findAllWithFiltersAndPagination()`
- Use `SELECT COUNT(i.id)` instead of fetching entities
- No ORDER BY or LIMIT needed

#### 3. `findWithLatestPriceAndTrend()`
Fetch items with joined latest price data and calculated trends.

**Signature:**
```php
public function findWithLatestPriceAndTrend(
    array $itemIds
): array
```

**Parameters:**
- `$itemIds`: Array of item IDs to fetch data for (from pagination results)

**Returns:** Array of associative arrays:
```php
[
    [
        'item' => Item entity,
        'latestPrice' => float|null,
        'volume' => int|null,
        'priceDate' => DateTimeImmutable|null,
        'trend7d' => float|null,   // percentage change (e.g., 5.2 = +5.2%)
        'trend30d' => float|null,  // percentage change
    ],
    // ...
]
```

**Query Logic:**
- For each item ID, fetch latest ItemPrice (ORDER BY priceDate DESC, LIMIT 1)
- Use ItemPriceRepository::getPriceTrend($itemId, 7) for 7-day trend
- Use ItemPriceRepository::getPriceTrend($itemId, 30) for 30-day trend
- Consider batch processing if performance is an issue (fetch all latest prices in one query)

### Service Layer (ItemTableService)

Create new service: `src/Service/ItemTableService.php`

**Purpose:** Coordinate between repository calls, handle business logic, format data for controller

**Key Method:**
```php
public function getItemsTableData(
    array $filters = [],
    string $sortBy = 'name',
    string $sortDirection = 'ASC',
    int $page = 1,
    int $perPage = 25
): array
```

**Returns:**
```php
[
    'items' => [/* array of item data with prices and trends */],
    'total' => 1234,              // total items matching filters
    'page' => 1,                  // current page
    'perPage' => 25,              // items per page
    'totalPages' => 50,           // calculated total pages
    'hasMore' => true,            // whether there are more pages
]
```

**Logic:**
1. Calculate offset: `($page - 1) * $perPage`
2. Call `ItemRepository::countWithFilters($filters)` to get total
3. Call `ItemRepository::findAllWithFiltersAndPagination($filters, $sortBy, $sortDirection, $perPage, $offset)`
4. Extract item IDs from results
5. Call `ItemRepository::findWithLatestPriceAndTrend($itemIds)` to enrich data
6. Merge item entities with price/trend data
7. Calculate pagination metadata (totalPages, hasMore)
8. Return structured array

### Filter Dropdown Data

Add methods to get unique values for filter dropdowns:

#### ItemRepository Methods (already exist, verify completeness):
- `findAllCategories()` - Returns array of unique categories
- `findAllTypes()` - Returns array of unique types
- `findAllRarities()` - Returns array of unique rarities

Add if missing:
- `findAllSubcategories()` - Returns array of unique subcategories (WHERE subcategory IS NOT NULL)

## Implementation Steps

1. **Add Repository Methods (ItemRepository.php)**
   - Add `findAllWithFiltersAndPagination()` method
   - Add `countWithFilters()` method
   - Add `findWithLatestPriceAndTrend()` method
   - Add `findAllSubcategories()` method if not present
   - Test each method independently in controller

2. **Create ItemTableService**
   - Create `src/Service/ItemTableService.php`
   - Inject `ItemRepository`, `ItemPriceRepository`, `EntityManagerInterface`
   - Implement `getItemsTableData()` method
   - Handle edge cases (no results, invalid page numbers, empty filters)

3. **Add Service Registration**
   - Symfony should auto-register services
   - Verify in `config/services.yaml` if needed

4. **Optimize Database Queries**
   - Review EXPLAIN output for main query
   - Ensure indexes exist: `idx_item_type`, `idx_item_category`, `idx_item_rarity`, `idx_item_active`
   - Consider adding composite index: `idx_item_active_category` if needed

5. **Add Currency Support**
   - Respect user's preferred currency (from UserConfig)
   - Convert prices using currency service if user has non-default currency
   - Pass currency info to frontend for display

## Edge Cases & Error Handling

- **No price data for an item**: Return `null` for price, volume, trends. Display "--" in UI.
- **Invalid sort column**: Default to 'name' with error logging
- **Invalid sort direction**: Default to 'ASC' with error logging
- **Page number < 1**: Default to page 1
- **Page number > totalPages**: Return empty results with pagination metadata
- **Offset exceeds total rows**: Return empty array (valid case for AJAX)
- **Empty filters**: Return all active items (25k+ rows, paginated)
- **Search term too short (<2 chars)**: Ignore search filter to avoid slow queries
- **Division by zero in trend calculation**: Already handled in `ItemPriceRepository::getPriceTrend()`
- **Multiple filters result in 0 items**: Valid case, return empty array with total=0

## Dependencies

### Blocking Dependencies
- None (all required entities and base repositories exist)

### Related Tasks (same feature)
- Task 31: Items table controller and AJAX endpoint (depends on this task)
- Task 32: Items table frontend UI and interactivity (depends on Task 31)

### Can Be Done in Parallel With
- None (this is the foundation task)

### External Dependencies
- Existing: Item entity, ItemPrice entity, ItemPriceRepository
- Existing: Database indexes on type, category, rarity, active
- Existing: Currency support (Task 22-23 completed)

## Acceptance Criteria

- [ ] `ItemRepository::findAllWithFiltersAndPagination()` returns paginated Item entities
- [ ] `ItemRepository::countWithFilters()` returns correct count for any filter combination
- [ ] `ItemRepository::findWithLatestPriceAndTrend()` returns items with latest price and trends
- [ ] `ItemRepository::findAllSubcategories()` returns unique subcategories (if missing)
- [ ] `ItemTableService::getItemsTableData()` returns properly structured array
- [ ] Filtering by text search works (name/marketName/hashName)
- [ ] Filtering by category, subcategory, type, rarity works independently
- [ ] Filtering by multiple criteria works (e.g., category + rarity)
- [ ] Filtering by stattrakAvailable/souvenirAvailable works
- [ ] Sorting by name works (ASC/DESC)
- [ ] Sorting by price works (items with no price handled correctly)
- [ ] Sorting by volume works
- [ ] Sorting by trend7d and trend30d works
- [ ] Pagination works: page 1 shows first 25 items, page 2 shows next 25
- [ ] Pagination metadata correct: totalPages, hasMore calculated properly
- [ ] Empty filters return all items (paginated)
- [ ] Invalid inputs handled gracefully (no exceptions)
- [ ] Query performance <500ms for filtered + paginated results
- [ ] Currency conversion applied based on user's UserConfig
- [ ] Manual verification: Test in controller with various filter combinations

## Notes & Considerations

### Performance Optimization
- **Batch price fetching**: Instead of N queries for latest prices, consider single query with subquery:
  ```sql
  SELECT i.*, ip.price, ip.volume
  FROM item i
  LEFT JOIN item_price ip ON ip.id = (
    SELECT id FROM item_price WHERE item_id = i.id ORDER BY price_date DESC LIMIT 1
  )
  ```
- **Trend calculation caching**: For expensive trend calculations, consider caching daily or storing precomputed trends
- **Index optimization**: Monitor slow query log, add indexes as needed

### Future Improvements
- Add full-text search index on name fields for better fuzzy matching
- Implement Elasticsearch for advanced search capabilities
- Add price range filters (min/max price)
- Add "favorites" feature (user can favorite items for watchlist)
- Export to CSV functionality

### Known Limitations
- Trends only available if price history exists (>=7 or 30 days old)
- Text search is simple LIKE query (not fuzzy/Levenshtein distance)
- No support for multiple categories in one filter (AND only, no OR)

### Security Considerations
- All queries use Doctrine parameterized queries (SQL injection protected)
- No user-supplied raw SQL (sortBy column validated against whitelist in controller)
- Active-only items by default (don't expose inactive/deleted items)

## Related Tasks

- Task 31: Items table controller and AJAX endpoint (depends on this task - must be completed first)
- Task 32: Items table frontend UI with Alpine.js (depends on Task 31)
