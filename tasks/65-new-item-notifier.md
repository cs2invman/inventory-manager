# New Item Notification Processor

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-17

## Overview

Implement a queue processor that sends Discord notifications when new items are detected on the Steam marketplace during import operations. This helps track when new CS2 items (cases, skins, stickers, etc.) become available for trading, which can be valuable for early investment opportunities.

## Problem Statement

When Valve releases new CS2 content (operations, cases, collections), new items appear on the Steam marketplace. These early-detection moments are valuable for:
- **Early investment**: New items often have volatile prices in first hours/days
- **Market awareness**: Know when new content drops
- **Completeness tracking**: Ensure our database has all available items
- **Community engagement**: Share new releases with Discord community

Currently, new items are silently added to the database during sync operations. This processor provides immediate visibility when new items appear.

## Requirements

### Functional Requirements
- Send Discord notification when NEW_ITEM queue item is processed
- Include item details: name, type, rarity, initial price
- Use 'system_events' Discord webhook (existing)
- Format message with relevant item metadata
- Handle items that don't have initial price data
- Process NEW_ITEM queue items

### Non-Functional Requirements
- Fast execution (simple notification, no heavy computation)
- Handle missing data gracefully (some new items may have incomplete data)
- Clear, readable Discord messages
- Minimal dependencies

## Technical Approach

### Processor Implementation

**NewItemNotificationProcessor**
Location: `src/Service/QueueProcessor/NewItemNotificationProcessor.php`

```php
namespace App\Service\QueueProcessor;

use App\Entity\ProcessQueue;
use App\Service\DiscordWebhookService;
use Psr\Log\LoggerInterface;

class NewItemNotificationProcessor implements ProcessorInterface
{
    public function __construct(
        private DiscordWebhookService $discordService,
        private LoggerInterface $logger
    ) {}

    public function process(ProcessQueue $queueItem): void
    {
        $item = $queueItem->getItem();

        $this->logger->info('Sending new item notification', [
            'item_id' => $item->getId(),
            'item_name' => $item->getName()
        ]);

        try {
            $message = $this->buildDiscordMessage($item);
            $this->discordService->sendMessage('system_events', $message);

            $this->logger->info('New item notification sent', [
                'item_id' => $item->getId(),
                'item_name' => $item->getName()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to send new item notification', [
                'item_id' => $item->getId(),
                'error' => $e->getMessage()
            ]);

            throw $e; // Re-throw to mark queue item as failed
        }
    }

    private function buildDiscordMessage(\App\Entity\Item $item): string
    {
        $parts = [];
        $parts[] = "**New Item Detected** :new:";
        $parts[] = "";
        $parts[] = sprintf("**Name:** %s", $item->getName());

        // Type (Weapon, Knife, Glove, etc.)
        if ($item->getType()) {
            $parts[] = sprintf("**Type:** %s", $item->getType());
        }

        // Weapon Type (Rifle, SMG, Pistol, etc.)
        if ($item->getWeaponType()) {
            $parts[] = sprintf("**Category:** %s", $item->getWeaponType());
        }

        // Rarity
        if ($item->getRarity()) {
            $rarity = $item->getRarity();
            $rarityIcon = $this->getRarityIcon($rarity);
            $parts[] = sprintf("**Rarity:** %s %s", $rarityIcon, $rarity);
        }

        // Initial price (if available)
        $currentPrice = $item->getCurrentPrice();
        if ($currentPrice && $currentPrice->getMedianPrice()) {
            $parts[] = "";
            $parts[] = "**Initial Market Data:**";
            $parts[] = sprintf("Price: $%.2f", (float) $currentPrice->getMedianPrice());
            $parts[] = sprintf("Volume: %s", number_format($currentPrice->getVolume()));
        } else {
            $parts[] = "";
            $parts[] = "_No market data available yet_";
        }

        // External ID for reference
        $parts[] = "";
        $parts[] = sprintf("**External ID:** %s", $item->getExternalId());
        $parts[] = sprintf("**Database ID:** %d", $item->getId());

        // Steam Market URL (if we can construct it)
        if ($item->getMarketHashName()) {
            $marketUrl = sprintf(
                "https://steamcommunity.com/market/listings/730/%s",
                urlencode($item->getMarketHashName())
            );
            $parts[] = sprintf("**Steam Market:** %s", $marketUrl);
        }

        return implode("\n", $parts);
    }

    private function getRarityIcon(string $rarity): string
    {
        return match (strtolower($rarity)) {
            'common', 'consumer grade' => ':white_circle:',
            'uncommon', 'industrial grade' => ':blue_circle:',
            'rare', 'mil-spec' => ':purple_circle:',
            'mythical', 'restricted' => ':red_circle:',
            'legendary', 'classified' => ':orange_circle:',
            'ancient', 'covert' => ':yellow_circle:',
            'immortal', 'exceedingly rare', 'extraordinary' => ':star:',
            'contraband' => ':warning:',
            default => ':grey_question:'
        };
    }

    public function getProcessType(): string
    {
        return ProcessQueue::TYPE_NEW_ITEM;
    }
}
```

### Service Registration

The processor will be auto-registered via the `_instanceof` configuration from Task 62. No additional configuration needed.

## Implementation Steps

1. **Create NewItemNotificationProcessor**
   - Create `src/Service/QueueProcessor/NewItemNotificationProcessor.php`
   - Implement ProcessorInterface
   - Implement `buildDiscordMessage()` method
   - Implement `getRarityIcon()` helper method
   - Add proper PHPDoc

2. **Test Processor with Existing Item**
   - Manually create a NEW_ITEM queue entry for an existing item
   - Run: `docker compose exec php php bin/console app:queue:process --type=NEW_ITEM`
   - Verify Discord notification is sent
   - Check notification formatting and content

3. **Test with Real New Item**
   - Wait for next Steam sync that detects new items
   - OR manually add a fake new item to test import
   - Verify NEW_ITEM queue items are created (from Task 61)
   - Verify notifications sent when queue processed

4. **Verify Discord Webhook**
   - Ensure 'system_events' webhook exists and is enabled
   - Test webhook: `docker compose exec php php bin/console app:discord:test-webhook system_events "New item test"`
   - Verify messages appear in correct Discord channel

5. **Handle Edge Cases**
   - Test with item that has no price data
   - Test with item that has minimal metadata
   - Verify all cases handled gracefully

## Edge Cases & Error Handling

### Item with No Price Data
- **Scenario**: New item just appeared, Steam hasn't collected market data yet
- **Handling**: Show "_No market data available yet_" message
- **Example**: Brand new case released 5 minutes ago

### Item with No Rarity
- **Scenario**: Some items (music kits, graffiti) don't have traditional rarity
- **Handling**: Check if rarity exists before displaying
- **Icon**: Use `:grey_question:` for unknown rarities

### Item with No Type/Category
- **Scenario**: Item metadata incomplete in Steam data
- **Handling**: Only show fields that exist, skip nulls
- **Result**: Clean message without "Type: null" entries

### Missing Market Hash Name
- **Scenario**: Item doesn't have market_hash_name set
- **Handling**: Don't include Steam Market URL if can't construct it
- **Impact**: Notification still sent, just without URL

### Discord Webhook Failure
- **Scenario**: Discord API down or webhook invalid
- **Handling**: Exception thrown, queue item marked as failed
- **Recovery**: Will retry in next queue processing cycle
- **Notification**: Admin gets "queue processing failed" alert via system_events webhook

### Duplicate Notifications
- **Scenario**: Item appears in multiple sync chunks, creates multiple queue entries
- **Handling**: Each queue entry processed independently
- **Impact**: Could get multiple notifications for same item
- **Mitigation**: ItemSyncService should only create NEW_ITEM queue entry once per item
- **Note**: This is acceptable for MVP - rare occurrence and not critical

## Dependencies

### Blocking Dependencies
- Task 61: Queue Processing System Foundation (MUST be completed)
- Task 62: Queue Processor Command and Infrastructure (MUST be completed)

### Related Tasks (same feature)
- Task 63: Price Trend Calculation Processor (parallel - separate processor)
- Task 64: Price Anomaly Detection Processor (parallel - separate processor)

### Can Be Done in Parallel With
- Task 63 and 64 (all are independent processors)

### External Dependencies
- DiscordWebhookService (already exists)
- 'system_events' Discord webhook (already exists)
- Item entity with all metadata fields

## Acceptance Criteria

- [ ] NewItemNotificationProcessor created implementing ProcessorInterface
- [ ] Processor auto-registered via compiler pass
- [ ] Processor correctly implements getProcessType() returning NEW_ITEM
- [ ] buildDiscordMessage() includes item name, type, rarity, price
- [ ] Rarity icons mapped correctly (colored circles for each tier)
- [ ] Steam Market URL included if market_hash_name available
- [ ] Missing data handled gracefully (no null/undefined in messages)
- [ ] Manual verification: Create test queue item, verify notification sent
- [ ] Manual verification: Discord message formatting is clean and readable
- [ ] Manual verification: All item metadata displayed correctly
- [ ] Manual verification: Items without price data show "no data" message
- [ ] Logging includes item ID and success/failure status
- [ ] Integration verified: Works with queue system and Discord service
- [ ] Webhook sends to 'system_events' channel

## Notes & Considerations

### Why system_events Webhook
- New items are system-level events (not price alerts)
- Reuses existing webhook (no new configuration needed)
- Keeps system events centralized in one channel
- Could be changed to dedicated 'new_items' webhook later if desired

### Why Include Steam Market URL
- Provides quick access to Steam marketplace for the item
- Users can immediately check current market status
- Helpful for investment decisions
- One-click access from Discord

### Message Formatting
- Uses Discord markdown for bold/italic
- Emoji icons for visual appeal and quick recognition
- Rarity colors help identify item value at a glance
- Clean separation of sections (metadata vs. market data)

### Notification Timing
- Notifications sent when queue processed (every 5 minutes via cron)
- Not real-time, but close enough for this use case
- New items typically appear during content updates (predictable times)
- 5-minute delay is acceptable

### Performance
- Simplest processor of the three
- No heavy calculations or database queries
- Just reads item data and formats message
- Fast execution, no memory concerns

### Future Enhancements (NOT in MVP)
- Include image/icon of the item (Discord embed)
- Include collection information
- Include exterior/wear data (for skins)
- Price prediction based on similar items
- Dedicated 'new_items' webhook
- Cooldown to prevent duplicate notifications

## Related Tasks

- Task 61: Queue Processing System Foundation (blocking)
- Task 62: Queue Processor Command and Infrastructure (blocking)
- Task 63: Price Trend Calculation Processor (parallel)
- Task 64: Price Anomaly Detection Processor (parallel)

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
