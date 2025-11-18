# CLAUDE.md

This file provides guidance to Claude Code when working with this repository.

## Project Overview

CS2 Inventory Management System - Track, manage, and value CS2 inventory items. Users import Steam inventory, track item details (float values, stickers, keychains), view market prices, and organize items in storage boxes.

## Technology Stack

- **Backend**: PHP 8.4 + Symfony 7+
- **Frontend**: Tailwind CSS
- **Database**: MariaDB 11.x + Doctrine ORM
- **Container**: Docker + Docker Compose
- **API**: SteamWebAPI.com for CS2 data

## CRITICAL: Docker-Only Environment

**⚠️ ALL commands MUST run in Docker containers. NEVER run PHP, Composer, npm, or MySQL directly on host.**

- ✅ Always: `docker compose exec php php bin/console ...`
- ✅ Always: `docker compose run --rm php composer ...`
- ✅ Always: `docker compose run --rm node npm ...`
- ❌ Never: `php bin/console ...` or `composer ...` or `npm ...`

## Task Management Workflow

**⚠️ IMPORTANT: When working on tasks from the `tasks/` directory, ALWAYS follow the "Task Completion Instructions" section at the bottom of each task file.**

**What "Completed" means**: A task is "completed" when you finish the first pass of implementation - all code written, assets rebuilt, ready for user testing. The user will test and commit afterward. **Completing the task file (updating status and moving it) is part of the work itself**, not something done after user testing.

When completing tasks:
1. **Implement all requirements** listed in the task file
2. **Rebuild assets if needed** (after template/CSS/JS changes)
3. **Check for "Task Completion Instructions" section** at the bottom of the task file
4. **Update the task file**:
   - Change `**Status**: Not Started` to `**Status**: Completed`
   - Add completion date: `**Completed**: [Date]`
5. **Move to completed folder**:
   - Move the file from `tasks/` to `tasks/completed/`
   - Keep the same filename

**Do this immediately after implementing** - don't wait for user testing or commits. The task file completion is part of your deliverable.

This ensures proper tracking of completed work and maintains a clean task organization.

## Core Architecture

### Entity Relationships

**User** → **UserConfig** (one-to-one, Steam ID settings)
**User** → **ItemUser** (one-to-many, inventory items)
**User** → **StorageBox** (one-to-many, storage containers)
**User** → **LedgerEntry** (one-to-many, financial transactions)

**DiscordWebhook** (system-wide, standalone)
- Tracks: identifier (unique key), displayName, webhookUrl, description, isEnabled
- Referenced by identifier (e.g., 'system_events')

**Item** (Steam marketplace data) → **ItemPrice** (one-to-many, price history)
**Item** → **ItemUser** (one-to-many, user instances)
**Item** → **currentPrice** (reference to latest ItemPrice for query optimization)

**ItemUser** belongs to **User**, **Item**, optionally **StorageBox**
- Tracks: assetId, floatValue, paintSeed, wearCategory, isStattrak, isSouvenir
- JSON fields: stickers[], keychain{}
- Auto-calculates: wearCategory, profit/loss

**StorageBox** belongs to **User**
- Two types: Steam-imported (has assetId) or manual (no assetId, for friend lending)
- `reportedCount`: Steam's count, updated during imports AND deposit/withdraw transactions
- `itemCount`: Synced with reportedCount during imports
- `actualCount`: Computed from DB (not a field), used to detect sync issues
- Manual boxes never touched by imports

**LedgerEntry** belongs to **User**
- Tracks: transaction_date, type (income/expense), amount, currency, description, category
- For tracking CS2-related financial transactions

**ProcessQueue** (asynchronous processing queue)
- Queues items for background processing (price trends, anomaly detection, notifications)
- Tracks: item, processType, status (pending/processing/failed), attempts, errorMessage
- FIFO ordering, automatic deduplication
- Multi-processor support: one queue item can be processed by multiple processors
- Only deleted when ALL processors complete successfully

**ProcessQueueProcessor** (tracks individual processor completions)
- One entry per processor per queue item
- Tracks: processQueue, processorName, status, completedAt, failedAt, errorMessage, attempts
- Allows independent processor failures without blocking others

### Key Services

**SteamWebApiClient**: Fetches CS2 data from SteamWebAPI.com (5500 items/request)

**ItemSyncService**: Syncs JSON files to database, handles deduplication by external_id, deferred deactivation for chunks. Auto-updates Item.currentPrice when adding new prices. Automatically enqueues items with new prices (TYPE_PRICE_UPDATED) and newly created items (TYPE_NEW_ITEM) to ProcessQueue for background processing

**InventoryImportService**: Parses Steam inventory JSON, matches by classId, extracts float/stickers/keychains, import deletes only items where `storageBox = null`. Skips default Music Kit (Valve, CS:GO)

**StorageBoxService**: Creates/manages storage boxes, syncs Steam boxes during import

**StorageBoxTransactionService**: Deposit/withdraw workflows, compares inventory snapshots, preview/confirm pattern. Syncs `reportedCount` from Steam JSON during transactions

**UserConfigService**: Manages Steam ID settings, validates SteamID64 format

**DiscordWebhookService**: Sends notifications to Discord via webhooks. Uses DiscordWebhookRepository to look up webhooks by identifier. Handles rate limiting, message formatting, and tracks notification history in database. Messages dispatched via async message bus

**ProcessQueueService**: Manages the processing queue for asynchronous item operations. Provides enqueue/dequeue operations, status updates, and bulk enqueueing with automatic deduplication. Automatically creates processor tracking entries for all registered processors when enqueueing items

**ProcessorRegistry**: Auto-discovers and registers queue processors via compiler pass. Maps process types to MULTIPLE processor implementations. Processors implement ProcessorInterface (process(), getProcessType(), getProcessorName() methods). Supports multiple processors per type - all must complete before queue item is deleted

### Essential Workflows

**Inventory Import:**
1. Upload Steam JSON → Preview (shows NEW/REMOVE with prices, excludes boxed items from comparison)
2. Confirm → Storage boxes synced, main inventory items replaced, boxed items preserved
3. Delete All route (`inventory_import_delete_all`) - testing tool with two-step confirmation

**Item Matching:**
- Uses `classId` to match Steam items to local database
- Calculates value: base price + stickers + keychain

**Deposit/Withdraw:**
1. Paste Steam JSON → Compare snapshots to detect movements
2. Preview → Confirm → Items moved, assetId changes handled by property matching, `reportedCount` synced from JSON

**Admin Panel** (`/admin`):
- Requires `ROLE_ADMIN` access
- Discord admin: CRUD for webhooks, config editor, user verification, notification history
- Form types: DiscordWebhookFormType (validates webhook URLs), DiscordConfigFormType (dynamic config editor)

**Reusable Components:**
- `templates/components/confirmation_modal.html.twig` - Two-step confirmation pattern (confirm button + text input verification)

## Development Commands

### Docker Setup

```bash
# Start services
docker compose up -d

# Stop services
docker compose down

# View logs
docker compose logs -f
```

### Frontend Assets

**⚠️ CRITICAL: ALWAYS rebuild assets after ANY of these changes:**
- Adding/modifying CSS classes in HTML/Twig templates (Tailwind needs to scan for classes)
- Modifying CSS files
- Modifying JS files

```bash
docker compose run --rm node npm install
docker compose run --rm node npm run build
docker compose run --rm node npm run watch  # Development (auto-rebuilds on changes)
```

**When to rebuild:**
- After editing any `.twig` template with class changes
- After modifying `assets/styles/app.css`
- After modifying any JavaScript files
- Before testing UI changes in browser

### Console Commands

```bash
# User management
docker compose exec php php bin/console app:create-user
docker compose exec php php bin/console app:list-users

# Steam data sync (chunked for memory efficiency)
docker compose exec php php bin/console app:steam:download-items  # Downloads chunks
docker compose exec php php bin/console app:steam:sync-items      # Syncs to DB (cron-optimized)

# Item management
docker compose exec php php bin/console app:item:backfill-current-price  # Backfill currentPrice for existing items

# Queue processing
docker compose exec php php bin/console app:queue:process              # Process queue items (cron-optimized)
docker compose exec php php bin/console app:queue:process --limit=50   # Process max 50 items
docker compose exec php php bin/console app:queue:process --type=price_trend  # Process specific type only

# Discord notifications
docker compose exec php php bin/console app:discord:test-webhook system_events "Test message"

# Database
docker compose exec php php bin/console make:migration
docker compose exec php php bin/console doctrine:migrations:migrate

# Process async messages (Discord notifications, etc.)
docker compose exec php php bin/console messenger:consume async -vv
```

### Cron Setup (Optional)

The `app:steam:sync-items` command is cron-optimized: processes files in `var/data/steam-items/import`, exits silently if none found.

```bash
# Run every minute (silent when no files)
* * * * * cd /path/to/project && docker compose exec -T php php bin/console app:steam:sync-items >> var/log/steam-sync.log 2>&1

# Or every 5 minutes
*/5 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:steam:sync-items >> var/log/steam-sync.log 2>&1
```

**Memory Management:**
- Configurable via: `SYNC_BATCH_SIZE`, `SYNC_MEMORY_LIMIT`, `SYNC_MEMORY_WARNING_THRESHOLD`
- Processed files → `var/data/steam-items/processed`
- Failed files remain for retry

**Queue Processing:**

The `app:queue:process` command is cron-optimized: processes pending queue items, exits silently if none found.

```bash
# Process queue every 5 minutes (recommended)
*/5 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:queue:process >> var/log/queue-process.log 2>&1
```

**Queue Behavior:**
- Processes up to 100 items per run (configurable via --limit)
- FIFO ordering (oldest items first)
- **Multi-processor support**: Each queue item processed by ALL registered processors for that type
- Individual processor tracking: Each processor's completion tracked separately
- Queue item only deleted when ALL processors complete successfully
- If any processor fails, others continue processing; failed processor tracked with error details
- Discord notifications sent to 'system_events' webhook on individual processor failures
- Entity manager cleared every 10 items to prevent memory issues

**Maintenance:**
- Find stuck 'processing' items: `SELECT * FROM process_queue WHERE status='processing' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)`
- Reset stuck items: `UPDATE process_queue SET status='pending' WHERE status='processing' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)`
- View failed processors: `SELECT pq.id, pq.process_type, pqp.processor_name, pqp.error_message FROM process_queue pq JOIN process_queue_processor pqp ON pq.id = pqp.process_queue_id WHERE pqp.status='failed'`
- Clean up old failed items: `DELETE FROM process_queue WHERE status='failed' AND failed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)`

**Creating New Processors:**
To add a new processor for a queue type (e.g., PRICE_UPDATED):
1. Create class implementing `ProcessorInterface` in `src/Service/QueueProcessor/`
2. Implement: `process()`, `getProcessType()`, `getProcessorName()` methods
3. Auto-registered via compiler pass (tagged as `app.queue_processor`)
4. Next enqueued item will automatically create tracking entry for new processor

## Configuration

### Environment Variables

**Required:**
- `APP_ENV`: dev/prod
- `APP_SECRET`: Symfony secret key
- `DATABASE_URL`: MySQL connection string
- `STEAM_WEB_API_KEY`: API key for SteamWebAPI.com

**Steam Sync:**
- `STEAM_WEB_API_BASE_URL`: https://www.steamwebapi.com/steam/api
- `STEAM_ITEMS_STORAGE_PATH`: var/data/steam-items (has import/ and processed/ subdirs)
- `SYNC_BATCH_SIZE`: Items per transaction (default: 25)
- `SYNC_MEMORY_LIMIT`: Memory limit for sync (default: 768M)
- `SYNC_MEMORY_WARNING_THRESHOLD`: Log warning % (default: 80)

**Messenger:**
- `MESSENGER_TRANSPORT_DSN`: Doctrine transport for async messaging (default: doctrine://default)

**Discord:**
- Discord webhook URLs are stored in `discord_webhook` table, NOT environment variables
- See DISCORD.md for complete setup and usage documentation

### Docker Variables (compose.yml, compose.dev.yml for dev)

- `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`

## Security

- **Login rate limiting**: 3 attempts per 5 minutes per IP (configure in `config/packages/framework.yaml`)
- **CSRF protection**: Enabled globally
- **Password hashing**: Symfony 'auto' algorithm
- **Access control**: Routes require `ROLE_USER`, admin panel requires `ROLE_ADMIN`

## Production Deployment

See `PRODUCTION.md` for detailed production setup, Docker configuration, and deployment workflows.
