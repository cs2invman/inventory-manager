# Discord Bot !price Command Implementation

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-08

## Overview

Implement the `!price` command for the Discord bot, allowing users to look up current market prices for CS2 items. This is the first functional command implementation demonstrating the command handler pattern.

## Problem Statement

Users need a quick way to check CS2 item prices without leaving Discord or accessing the web interface. The `!price` command provides instant price lookups using the item database synced from Steam Web API.

## Requirements

### Functional Requirements
- Respond to `!price [item_name]` command
- Search items by name (case-insensitive, partial matches)
- Display current price in USD (and user's preferred currency if linked)
- Handle multiple matches (show top 5 results)
- Handle no matches (suggest similar items)
- Show item details: name, type, price, last updated
- Format response as rich Discord embed
- Require authenticated user (linked Discord account)

### Non-Functional Requirements
- Response time < 2 seconds
- Efficient database queries (indexed search)
- Handle typos and partial names gracefully
- Limit results to prevent spam (max 5 items)
- Cache common queries (optional optimization)

## Technical Approach

### Service Layer

#### New Command Handler: `PriceCommand`
Location: `src/Service/Discord/Command/PriceCommand.php`

**Implements:** `DiscordCommandInterface`

**Dependencies:**
- `ItemRepository` (for item search)
- `ItemPriceRepository` (for latest prices)
- `CurrencyService` (for currency conversion)
- `DiscordUserRepository` (for user preferences)

**Methods:**
```php
public function execute(Message $message, array $args, User $user): array
{
    // 1. Validate arguments (require item name)
    // 2. Search items by name
    // 3. Get latest prices for matches
    // 4. Convert to user's preferred currency
    // 5. Format as Discord embed
    // 6. Return embed array
}

public function getName(): string { return 'price'; }

public function getDescription(): string { return 'Look up current market price for a CS2 item'; }

public function getUsage(): string { return '!price <item_name>'; }

public function hasPermission(User $user): bool { return true; } // All verified users

private function searchItems(string $query): array
{
    // Search items by name (LIKE query)
    // Return top 5 matches ordered by relevance
}

private function getLatestPrice(Item $item): ?ItemPrice
{
    // Get most recent ItemPrice for item
}

private function formatPriceEmbed(array $items, string $currency): array
{
    // Create Discord embed with price information
}

private function formatNoResultsEmbed(string $query): array
{
    // Create embed for no matches found
}
```

### Repository Enhancements

#### Update: `ItemRepository`
Add method for name-based search:

```php
public function searchByName(string $query, int $limit = 5): array
{
    return $this->createQueryBuilder('i')
        ->where('i.name LIKE :query')
        ->orWhere('i.marketHashName LIKE :query')
        ->setParameter('query', '%' . $query . '%')
        ->orderBy('i.name', 'ASC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}
```

#### Update: `ItemPriceRepository`
Add method for latest price per item:

```php
public function getLatestPriceForItem(Item $item): ?ItemPrice
{
    return $this->createQueryBuilder('ip')
        ->where('ip.item = :item')
        ->setParameter('item', $item)
        ->orderBy('ip.createdAt', 'DESC')
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
}

// More efficient: batch fetch latest prices for multiple items
public function getLatestPricesForItems(array $items): array
{
    // Subquery to get latest price ID per item
    // JOIN to get full ItemPrice entities
    // Return array keyed by item ID
}
```

### Discord Embed Format

**Single Item Match:**
```json
{
    "title": "AK-47 | Redline (Field-Tested)",
    "color": 3447003,
    "fields": [
        {
            "name": "Current Price",
            "value": "$12.50 USD ($11.25 EUR)",
            "inline": true
        },
        {
            "name": "Item Type",
            "value": "Rifle",
            "inline": true
        },
        {
            "name": "Last Updated",
            "value": "2 hours ago",
            "inline": true
        }
    ],
    "thumbnail": {
        "url": "https://community.cloudflare.steamstatic.com/economy/image/[icon_url]"
    },
    "footer": {
        "text": "Prices from Steam Community Market"
    },
    "timestamp": "2025-11-08T12:00:00Z"
}
```

**Multiple Matches:**
```json
{
    "title": "Price Results for: 'ak redline'",
    "description": "Found 3 matching items:",
    "color": 3447003,
    "fields": [
        {
            "name": "AK-47 | Redline (Field-Tested)",
            "value": "$12.50 USD",
            "inline": false
        },
        {
            "name": "AK-47 | Redline (Minimal Wear)",
            "value": "$28.75 USD",
            "inline": false
        },
        {
            "name": "AK-47 | Redline (Well-Worn)",
            "value": "$8.20 USD",
            "inline": false
        }
    ],
    "footer": {
        "text": "Use '!price <exact name>' for detailed info"
    }
}
```

**No Results:**
```json
{
    "title": "No Results Found",
    "description": "No items found matching: 'xyz123'",
    "color": 15158332,
    "footer": {
        "text": "Try a different search term or check spelling"
    }
}
```

## Implementation Steps

1. **Update ItemRepository**
   - Add `searchByName()` method with LIKE query
   - Add index on `name` column if not exists (check in migration)
   - Test query with common search terms

2. **Update ItemPriceRepository**
   - Add `getLatestPriceForItem()` method
   - Add `getLatestPricesForItems()` batch method
   - Optimize with subquery and JOIN

3. **Create PriceCommand Class**
   - Create `src/Service/Discord/Command/PriceCommand.php`
   - Implement DiscordCommandInterface
   - Add service tag: `discord.command`
   - Inject dependencies (repositories, CurrencyService)

4. **Implement Argument Validation**
   - Check if args array is not empty
   - Join args into search query string
   - Trim and validate minimum length (2 chars)
   - Return error embed if invalid

5. **Implement Item Search**
   - Call `ItemRepository::searchByName()` with query
   - Handle no results (return formatNoResultsEmbed())
   - Handle single result (detailed embed)
   - Handle multiple results (list embed)

6. **Implement Price Fetching**
   - For single item: call `getLatestPriceForItem()`
   - For multiple items: call `getLatestPricesForItems()` (batch)
   - Handle items with no price data (show "No price available")

7. **Implement Currency Conversion**
   - Get User's preferred currency from UserConfig
   - If no preference, use USD only
   - Call CurrencyService to convert price
   - Format both USD and user currency in embed

8. **Implement Embed Formatting**
   - Create `formatPriceEmbed()` for single item
   - Include: item name, price (USD + user currency), type, last updated
   - Add thumbnail (Steam item icon URL from Item entity)
   - Add timestamp and footer

9. **Implement Multiple Results Formatting**
   - Limit to top 5 matches
   - Show item name and USD price only (keep it concise)
   - Add footer: "Use '!price <exact name>' for details"

10. **Implement No Results Formatting**
    - Show friendly error message
    - Suggest checking spelling
    - Use red color for error embeds

11. **Add Error Handling**
    - Try-catch around database queries
    - Return error embed on exceptions (don't crash bot)
    - Log errors to system logger

12. **Register Command**
    - Add `discord.command` tag in services.yaml OR use autoconfiguration
    - Command should auto-register via DiscordCommandRegistry

13. **Test Command**
    - Test with exact item names
    - Test with partial names
    - Test with typos
    - Test with no matches
    - Test with user who has preferred currency
    - Test with user who has no preferred currency

## Edge Cases & Error Handling

- **No arguments provided**: Return error embed: "Usage: !price <item_name>"
- **Search term too short (<2 chars)**: Return error: "Search term must be at least 2 characters"
- **No items found**: Return friendly "No results" embed with suggestions
- **Item has no price data**: Show "Price unavailable" instead of crashing
- **Multiple exact matches**: Unlikely with Steam market hash names, but show all if happens
- **User has no currency preference**: Default to USD only
- **Currency conversion service unavailable**: Fallback to USD only, log warning
- **Database query timeout**: Return error embed, log error
- **Very long item names**: Truncate in embed to fit Discord limits (256 chars for field name)
- **Special characters in search**: Sanitize query to prevent SQL injection (use parameterized queries)

## Dependencies

### Blocking Dependencies
- Task 38: Discord database foundation (DiscordUser entity)
- Task 40: Discord bot foundation (command registry, bot service)

### Related Tasks (Discord Integration Feature)
- Task 39: Discord webhook service (parallel, independent)
- Task 42: Discord admin settings UI (parallel, manages user verification)
- Task 43: DISCORD.md documentation (update with !price command usage)

### Can Be Done in Parallel With
- Additional Discord commands (future tasks)
- Task 42: Discord admin settings UI

### External Dependencies
- CurrencyService (already exists in project - Tasks 16, 17)
- ItemRepository, ItemPriceRepository (already exist)
- Discord bot must be running (Task 40)

## Acceptance Criteria

- [ ] PriceCommand class created and implements DiscordCommandInterface
- [ ] ItemRepository::searchByName() method implemented
- [ ] ItemPriceRepository::getLatestPriceForItem() implemented
- [ ] ItemPriceRepository::getLatestPricesForItems() batch method implemented
- [ ] Command handles exact item name matches (single result)
- [ ] Command handles partial name matches (multiple results, max 5)
- [ ] Command handles no matches with friendly error
- [ ] Currency conversion works (shows USD + user's preferred currency)
- [ ] Falls back to USD only if no currency preference or conversion fails
- [ ] Embed formatting matches Discord spec (title, fields, color, footer, timestamp)
- [ ] Item thumbnail displayed in embed (Steam icon URL)
- [ ] "Last updated" timestamp shown in human-friendly format (e.g., "2 hours ago")
- [ ] Command registered in DiscordCommandRegistry
- [ ] Appears in `!help` command output
- [ ] Error handling prevents bot crashes
- [ ] All errors logged appropriately

## Manual Verification Steps

### Setup
```bash
# Ensure Discord bot is running
docker compose exec php php bin/console app:discord:bot:start

# Link your Discord account (if not already)
docker compose exec php php bin/console dbal:run-sql "INSERT INTO discord_user (user_id, discord_id, discord_username, is_verified, linked_at, verified_at) VALUES (1, 'YOUR_DISCORD_ID', 'YourUsername#0000', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE is_verified=1, verified_at=NOW();"

# Set preferred currency (optional)
docker compose exec php php bin/console dbal:run-sql "UPDATE user_config SET preferred_currency='EUR' WHERE user_id=1;"
```

### Test Cases in Discord

1. **Test Exact Match**
   - Command: `!price AK-47 | Redline (Field-Tested)`
   - Expected: Single item embed with price, type, thumbnail

2. **Test Partial Match**
   - Command: `!price ak redline`
   - Expected: List of all AK-47 Redline variants with prices

3. **Test Short Search**
   - Command: `!price awp`
   - Expected: List of AWP items (up to 5)

4. **Test No Match**
   - Command: `!price xyzinvaliditem123`
   - Expected: "No results found" embed (red color)

5. **Test No Arguments**
   - Command: `!price`
   - Expected: Error embed with usage example

6. **Test Currency Conversion**
   - Command: `!price M4A4 | Howl`
   - Expected: Price shown in USD and your preferred currency (EUR)

7. **Test Item Without Price**
   - Find item in database with no ItemPrice entries
   - Command: `!price [that item]`
   - Expected: "Price unavailable" or similar message

8. **Test Very Long Name**
   - Command: `!price StatTrak™ AK-47 | The Empress (Factory New)` (or longer)
   - Expected: Embed displays correctly, truncates if needed

### Verify in Database

```bash
# Check that command executions are logged
docker compose exec mariadb mysql -u cs2_user -p cs2_inventory -e "SELECT * FROM discord_notification WHERE notification_type='command_execution' AND message_content LIKE '%!price%' ORDER BY created_at DESC LIMIT 5;"
```

### Verify in !help

```bash
# In Discord, run:
!help

# Expected output should include:
# !price - Look up current market price for a CS2 item
# Usage: !price <item_name>
```

## Notes & Considerations

- **Search Relevance**: LIKE queries with % wildcards are simple but not ranked by relevance. Consider full-text search for better results if needed.
- **Performance**: Index on `item.name` crucial for fast LIKE queries. Current DB likely has this.
- **Price Staleness**: Show "Last updated" timestamp so users know if data is stale
- **Steam Market Link**: Consider adding field with link to Steam Market page for item
- **Image URLs**: Steam icon URLs in Item entity (from Steam Web API) should work in Discord embeds
- **Rate Limiting**: Consider per-user rate limit (e.g., max 10 !price commands per minute) to prevent abuse
- **Caching**: Popular items (e.g., "awp asiimov") could be cached for 5-10 minutes to reduce DB load
- **Future Enhancements**:
  - Add wear tiers (FN, MW, FT, WW, BS) as separate command or filter
  - Add price history graph (last 7 days)
  - Add StatTrak™ filter
  - Add souvenir filter
  - Compare prices across different markets (Steam, CSGOFloat, etc.)

## Related Tasks

- Task 38: Discord database foundation - completed (blocking)
- Task 40: Discord bot foundation - MUST be completed first (blocking)
- Task 39: Discord webhook service (parallel, independent)
- Task 42: Discord admin settings UI (parallel)
- Task 43: DISCORD.md documentation - update with !price command (depends on this)
