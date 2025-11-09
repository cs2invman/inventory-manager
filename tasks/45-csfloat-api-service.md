# CSFloat API Service

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-08

## Overview

Create a service layer for interacting with the CSFloat API. Provides methods for searching items by market_hash_name, extracting def_index/paint_index identifiers, and handling API authentication, rate limiting, and error handling.

## Problem Statement

The application needs to communicate with CSFloat's marketplace API to:
- Search for items by Steam market_hash_name
- Extract def_index and paint_index from search results
- Handle API authentication via API key
- Respect rate limits and handle errors gracefully
- Provide a clean abstraction for other services to use

## Requirements

### Functional Requirements
- Authenticate with CSFloat API using API key from admin settings
- Search CSFloat listings by market_hash_name
- Extract def_index and paint_index from API responses
- Handle pagination for search results
- Return structured data (DTOs or arrays)
- Log API requests for debugging

### Non-Functional Requirements
- Respect API rate limits (implement exponential backoff on 429 errors)
- Timeout requests after 30 seconds
- Cache negative results (items not found) to avoid repeated failed searches
- Validate API responses before processing
- Throw descriptive exceptions for error handling
- Support dependency injection for testing

## Technical Approach

### Service Architecture

**CsfloatApiClient** (primary service)
- Handles HTTP communication with CSFloat API
- Manages authentication headers
- Implements retry logic and rate limiting
- Returns raw API responses

**CsfloatSearchService** (business logic)
- Uses CsfloatApiClient for HTTP requests
- Searches items by market_hash_name
- Parses responses to extract def_index/paint_index
- Handles edge cases (no results, multiple results, etc.)
- Returns CsfloatSearchResultDTO

### API Endpoints Used

**Search Listings:**
```
GET https://csfloat.com/api/v1/listings
Query params:
  - market_hash_name: string (exact match)
  - limit: 1 (we only need one result to get indexes)
  - sort_by: lowest_price (get cheapest listing for consistency)
```

**Response Structure:**
```json
{
  "data": [
    {
      "id": "listing-id",
      "item": {
        "market_hash_name": "AK-47 | Redline (Field-Tested)",
        "def_index": 7,
        "paint_index": 282,
        "paint_seed": 123,
        "float_value": 0.23456
      },
      "price": 1250
    }
  ]
}
```

### Configuration

**Environment Variables:**
```
CSFLOAT_API_KEY=your-api-key-here
CSFLOAT_API_BASE_URL=https://csfloat.com/api/v1
CSFLOAT_REQUEST_TIMEOUT=30
```

**Admin Settings (from database):**
- API key stored in admin_config table (similar to Discord webhook)
- Config key: `csfloat_api_key`
- Retrieved via repository, not .env (allows runtime changes)

### DTOs

**CsfloatSearchResultDTO:**
```php
class CsfloatSearchResultDTO
{
    public function __construct(
        public readonly ?int $defIndex,
        public readonly ?int $paintIndex,
        public readonly string $marketHashName,
        public readonly bool $found,
        public readonly ?string $errorMessage = null
    ) {}
}
```

### Caching Strategy

**Symfony Cache Component:**
- Cache successful results for 30 days (indexes rarely change)
- Cache "not found" results for 7 days (avoid repeated failed searches)
- Cache key: `csfloat.search.{md5(market_hash_name)}`
- Invalidate cache when admin manually triggers re-sync

## Implementation Steps

1. **Add Configuration Parameters**
   - Add `CSFLOAT_API_BASE_URL` to `.env` (default: https://csfloat.com/api/v1)
   - Add `CSFLOAT_REQUEST_TIMEOUT` to `.env` (default: 30)
   - Document in `config/services.yaml` if needed

2. **Create CsfloatSearchResultDTO**
   - Create `src/DTO/CsfloatSearchResultDTO.php`
   - Define readonly properties: defIndex, paintIndex, marketHashName, found, errorMessage
   - Add constructor with type hints

3. **Create CsfloatApiClient Service**
   - Create `src/Service/CsfloatApiClient.php`
   - Inject `HttpClientInterface` (Symfony HttpClient)
   - Inject `CacheInterface` for caching
   - Inject `LoggerInterface` for logging
   - Inject `AdminConfigRepository` to get API key from database
   - Add method: `searchByMarketHashName(string $marketHashName): array`
   - Implement authentication header: `Authorization: {API_KEY}`
   - Add retry logic for 429 (rate limited) responses (exponential backoff: 1s, 2s, 4s)
   - Add timeout handling (30s default)
   - Log all requests (URL, params, response time)

4. **Create CsfloatSearchService**
   - Create `src/Service/CsfloatSearchService.php`
   - Inject `CsfloatApiClient`
   - Add method: `searchItemIndexes(string $marketHashName): CsfloatSearchResultDTO`
   - Parse API response to extract def_index and paint_index
   - Handle edge cases:
     - No results found → return DTO with found=false
     - Multiple results → take first result (all should have same indexes)
     - Invalid response structure → return DTO with errorMessage
   - Implement cache check before API call
   - Store result in cache after successful search

5. **Add Error Handling**
   - Create custom exception: `src/Exception/CsfloatApiException.php`
   - Throw on: HTTP errors, invalid API key, timeout, malformed responses
   - Include original error message and HTTP status code

6. **Add Admin Config Support**
   - Create migration to add `csfloat_api_key` to admin_config table (or reuse Discord pattern)
   - Add repository method: `AdminConfigRepository::getCsfloatApiKey(): ?string`
   - Handle missing API key gracefully (return "not configured" in DTO)

7. **Implement Rate Limiting**
   - Add `RateLimiterInterface` injection (Symfony Rate Limiter component)
   - Configure rate limiter in `config/packages/rate_limiter.yaml`:
     ```yaml
     framework:
         rate_limiter:
             csfloat_api:
                 policy: 'sliding_window'
                 limit: 100
                 interval: '1 minute'
     ```
   - Apply rate limiter before API requests

8. **Add Logging**
   - Log successful searches: `[CSFloat] Found indexes for {market_hash_name}: def={def_index}, paint={paint_index}`
   - Log failed searches: `[CSFloat] Item not found: {market_hash_name}`
   - Log API errors: `[CSFloat] API error: {status_code} - {message}`
   - Log rate limit hits: `[CSFloat] Rate limited, retrying in {seconds}s`

9. **Write Service Tests (Optional)**
   - Create `tests/Service/CsfloatSearchServiceTest.php`
   - Mock HttpClient responses
   - Test: successful search
   - Test: item not found
   - Test: API error handling
   - Test: cache hit/miss
   - Test: missing API key

## Edge Cases & Error Handling

- **Missing API key**: Return DTO with found=false, errorMessage="API key not configured"
- **API key invalid (401)**: Throw CsfloatApiException with message for admin
- **Rate limit exceeded (429)**: Retry with exponential backoff (max 3 retries), then fail gracefully
- **Item not on CSFloat**: Return DTO with found=false (not an error, cache for 7 days)
- **Multiple listings with different indexes**: Shouldn't happen (same item = same indexes), log warning if detected
- **Timeout**: Throw CsfloatApiException, log error
- **Network error**: Throw CsfloatApiException, include original exception
- **Malformed response**: Throw CsfloatApiException with parsing error details
- **Empty market_hash_name**: Throw InvalidArgumentException before API call
- **Special characters in market_hash_name**: URL-encode properly using HttpClient's query params

## Dependencies

### Blocking Dependencies
- Task 44: CSFloat database foundation (ItemCsfloat entity must exist for sync command)

### Related Tasks (CSFloat Integration Feature)
- Task 44: CSFloat database foundation (can be parallel, both are foundation)
- Task 46: CSFloat sync cronjob (depends on Tasks 44 & 45)
- Task 47: Admin settings UI (depends on Task 45 for testing API key)
- Task 48: Frontend CSFloat links (uses data populated by Task 46)

### Can Be Done in Parallel With
- Task 44: CSFloat database foundation (both are foundation tasks)
- Task 42: Discord admin settings UI (unrelated feature)

### External Dependencies
- Symfony HttpClient component (already in project)
- Symfony Cache component (already in project)
- Symfony Rate Limiter component (may need installation)
- CSFloat API access (requires API key from csfloat.com)

## Acceptance Criteria

- [ ] CsfloatApiClient created and handles HTTP requests
- [ ] API authentication working with API key from database
- [ ] CsfloatSearchService created with searchItemIndexes() method
- [ ] CsfloatSearchResultDTO created with proper typing
- [ ] Rate limiting implemented (100 requests per minute)
- [ ] Retry logic for 429 responses (exponential backoff)
- [ ] Caching implemented (30 days for found, 7 days for not found)
- [ ] Error handling with custom CsfloatApiException
- [ ] Logging for all API interactions
- [ ] Missing API key handled gracefully
- [ ] Timeout set to 30 seconds
- [ ] Services registered in services.yaml (autowiring should handle this)

## Manual Verification Steps

### 1. Install Dependencies (if needed)

```bash
# Check if Rate Limiter is installed
docker compose exec php composer show symfony/rate-limiter

# Install if missing
docker compose run --rm php composer require symfony/rate-limiter
```

### 2. Configure Environment

```bash
# Add to .env.local
echo "CSFLOAT_API_BASE_URL=https://csfloat.com/api/v1" >> .env.local
echo "CSFLOAT_REQUEST_TIMEOUT=30" >> .env.local
```

### 3. Add API Key to Database

```bash
# Option 1: Via SQL (temporary for testing)
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
INSERT INTO admin_config (config_key, config_value, created_at, updated_at)
VALUES ('csfloat_api_key', 'YOUR_API_KEY_HERE', NOW(), NOW())
ON DUPLICATE KEY UPDATE config_value='YOUR_API_KEY_HERE', updated_at=NOW();
"

# Option 2: Via admin UI (after Task 47 is complete)
```

### 4. Test API Client Directly

Create temporary test command:
```php
// src/Command/TestCsfloatCommand.php
namespace App\Command;

use App\Service\CsfloatSearchService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestCsfloatCommand extends Command
{
    protected static $defaultName = 'app:test:csfloat';

    public function __construct(
        private CsfloatSearchService $csfloatSearch
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $testItems = [
            'AK-47 | Redline (Field-Tested)',
            'AWP | Asiimov (Field-Tested)',
            'Glock-18 | Water Elemental (Minimal Wear)',
            'Nonexistent Item That Does Not Exist',
        ];

        foreach ($testItems as $marketHashName) {
            $output->writeln("Searching: {$marketHashName}");

            $result = $this->csfloatSearch->searchItemIndexes($marketHashName);

            if ($result->found) {
                $output->writeln("  ✓ Found: def_index={$result->defIndex}, paint_index={$result->paintIndex}");
            } else {
                $output->writeln("  ✗ Not found: {$result->errorMessage}");
            }

            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
```

Run test:
```bash
docker compose exec php php bin/console app:test:csfloat
```

Expected output:
```
Searching: AK-47 | Redline (Field-Tested)
  ✓ Found: def_index=7, paint_index=282

Searching: AWP | Asiimov (Field-Tested)
  ✓ Found: def_index=9, paint_index=279

Searching: Glock-18 | Water Elemental (Minimal Wear)
  ✓ Found: def_index=4, paint_index=207

Searching: Nonexistent Item That Does Not Exist
  ✗ Not found: Item not found on CSFloat marketplace
```

### 5. Test Caching

```bash
# Run test command twice, second run should be instant (cache hit)
time docker compose exec php php bin/console app:test:csfloat
# First run: ~2-3 seconds (API calls)

time docker compose exec php php bin/console app:test:csfloat
# Second run: <100ms (cache hits)
```

### 6. Test Rate Limiting

Create test for rapid requests:
```bash
# Send 150 requests rapidly (should trigger rate limiter)
for i in {1..150}; do
  docker compose exec php php bin/console app:test:csfloat &
done
wait

# Check logs for rate limit messages
docker compose logs php | grep "Rate limited"
```

### 7. Test Error Handling

```bash
# Test with invalid API key
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
UPDATE admin_config SET config_value='invalid-key' WHERE config_key='csfloat_api_key';
"

docker compose exec php php bin/console app:test:csfloat
# Should show: "API error: 401 - Unauthorized"

# Restore valid API key
docker compose exec mariadb mysql -u app_user -p cs2inventory -e "
UPDATE admin_config SET config_value='YOUR_VALID_KEY' WHERE config_key='csfloat_api_key';
"
```

### 8. Verify Logging

```bash
# Check logs for CSFloat entries
docker compose exec php tail -f var/log/dev.log | grep CSFloat

# Should see entries like:
# [CSFloat] Searching for: AK-47 | Redline (Field-Tested)
# [CSFloat] Found indexes: def=7, paint=282
# [CSFloat] Item not found: Nonexistent Item
```

## Notes & Considerations

- **API Key Security**: Store in database (admin_config table), NOT in .env file (allows runtime changes without deploy)
- **Rate Limits**: CSFloat API documentation doesn't specify rate limits, but implement conservative 100/min to be safe
- **Caching Strategy**: Long cache TTL (30 days) because def_index/paint_index never change for a given item
- **Market Hash Name Variations**: CSFloat requires exact match. Handle wear variations: "(Factory New)", "(Minimal Wear)", etc.
- **No Batch Search**: CSFloat API doesn't support batch queries, must search one item at a time
- **Future Enhancements**:
  - Add `getLowestPrice()` method to fetch current pricing data
  - Add `searchByIndexes()` for reverse lookup (def_index + paint_index → market_hash_name)
  - Implement webhook support if CSFloat adds it
  - Add metrics tracking (requests per hour, success rate, etc.)

## Related Tasks

- Task 44: CSFloat database foundation (parallel - both foundation tasks)
- Task 46: CSFloat sync cronjob (depends on Tasks 44 & 45)
- Task 47: Admin settings UI (depends on Task 45 for API key validation)
- Task 48: Frontend CSFloat links (uses data from Task 46 sync)
