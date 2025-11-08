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

## Core Architecture

### Entity Relationships

**User** → **UserConfig** (one-to-one, Steam ID settings)
**User** → **ItemUser** (one-to-many, inventory items)
**User** → **StorageBox** (one-to-many, storage containers)

**Item** (Steam marketplace data) → **ItemPrice** (one-to-many, price history)
**Item** → **ItemUser** (one-to-many, user instances)

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

### Key Services

**SteamWebApiClient**: Fetches CS2 data from SteamWebAPI.com (5500 items/request)

**ItemSyncService**: Syncs JSON files to database, handles deduplication by external_id, deferred deactivation for chunks

**InventoryImportService**: Parses Steam inventory JSON, matches by classId, extracts float/stickers/keychains, import deletes only items where `storageBox = null`. Skips default Music Kit (Valve, CS:GO)

**StorageBoxService**: Creates/manages storage boxes, syncs Steam boxes during import

**StorageBoxTransactionService**: Deposit/withdraw workflows, compares inventory snapshots, preview/confirm pattern. Syncs `reportedCount` from Steam JSON during transactions

**UserConfigService**: Manages Steam ID settings, validates SteamID64 format

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

```bash
docker compose run --rm node npm install
docker compose run --rm node npm run build
docker compose run --rm node npm run watch  # Development
```

### Console Commands

```bash
# User management
docker compose exec php php bin/console app:create-user
docker compose exec php php bin/console app:list-users

# Steam data sync (chunked for memory efficiency)
docker compose exec php php bin/console app:steam:download-items  # Downloads chunks
docker compose exec php php bin/console app:steam:sync-items      # Syncs to DB (cron-optimized)

# Database
docker compose exec php php bin/console make:migration
docker compose exec php php bin/console doctrine:migrations:migrate
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

### Docker Variables (compose.yml, compose.dev.yml for dev)

- `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`

## Security

- **Login rate limiting**: 3 attempts per 5 minutes per IP (configure in `config/packages/framework.yaml`)
- **CSRF protection**: Enabled globally
- **Password hashing**: Symfony 'auto' algorithm
- **Access control**: Routes require `ROLE_USER`

## Production Deployment

See `PRODUCTION.md` for detailed production setup, Docker configuration, and deployment workflows.
