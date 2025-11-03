# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CS2 Inventory Management System - A web application for tracking, managing, and valuing CS2 (Counter-Strike 2) inventory items. Users can import their Steam inventory, track item details (float
values, stickers, keychains), view market prices, and manage items in virtual storage boxes.

## Technology Stack

- **Backend**: PHP 8.4 + Symfony 7+
- **Frontend**: Tailwind CSS for styling
- **Database**: MySQL with Doctrine ORM
- **Containerization**: Docker + Docker Compose for development and deployment
- **API Integration**: SteamWebAPI.com for CS2 item data

## Core Architecture

### Entity Relationships

**User** (authentication & account management)

- Has one `UserConfig` (Steam ID, settings)
- Has many `ItemUser` (inventory items)
- Has many `StorageBox` (storage containers)
- Fields: email, password, firstName, lastName, isActive, lastLoginAt

**Item** (CS2 items from Steam marketplace)

- Has many `ItemPrice` (price history)
- Has many `ItemUser` (user inventory instances)
- Key fields: name, type, category, rarity, collection, stattrakAvailable, souvenirAvailable
- Steam fields: classId, instanceId, externalId, marketName, hashName, slug
- Indexed on: type, category, rarity, active, external_id

**ItemUser** (user inventory instances with custom attributes)

- Belongs to `User`, `Item`, and optionally `StorageBox`
- Fields: assetId, floatValue, paintSeed, wearCategory, isStattrak, isSouvenir
- JSON fields: stickers[], keychain{}
- Financial tracking: acquiredPrice, currentMarketValue
- Auto-calculates: wearCategory (FN/MW/FT/WW/BS), profit/loss

**StorageBox** (storage containers for organizing items)

- Belongs to `User`
- Two types: Steam-imported (with assetId) and manual (without assetId)
- Fields: assetId (nullable), name, itemCount, modificationDate
- Steam boxes sync automatically during inventory imports
- Manual boxes are for tracking items lent to friends, never modified by imports
- Helper methods: `isManualBox()`, `isSteamBox()`

**ItemPrice** (historical price data)

- Belongs to `Item`
- Fields: medianPrice, lowestPrice, highestPrice, volume, source, recordedAt

**UserConfig** (user settings)

- Belongs to `User` (one-to-one)
- Fields: steamId (SteamID64 format, 17 digits)

### Key Services

**SteamWebApiClient** (`src/Service/SteamWebApiClient.php`)

- Fetches CS2 item data from SteamWebAPI.com
- Methods: `getItems()`, `getItemPrices()`
- Requires: `STEAM_WEB_API_KEY` env variable

**ItemSyncService** (`src/Service/ItemSyncService.php`)

- Syncs item data from JSON files to database
- Handles deduplication by external_id
- Maps API fields to Item entity
- Tracks price history

**InventoryImportService** (`src/Service/InventoryImportService.php`)

- Parses Steam inventory JSON exports (including storage boxes)
- Matches items by classId to local database
- Extracts: float values, stickers, keychains, StatTrak counters
- Creates preview before final import
- **Import behavior**: Only deletes items with `storageBox = null`; items in storage boxes are preserved
- Calculates market values including sticker/keychain prices

**StorageBoxService** (`src/Service/StorageBoxService.php`)

- Creates and manages storage boxes
- Syncs Steam storage boxes during import (by assetId)
- Creates manual storage boxes for friend lending tracking
- Manual boxes are never touched by import process

**StorageBoxTransactionService** (`src/Service/StorageBoxTransactionService.php`)

- Handles deposit/withdraw workflows for storage boxes
- Compares inventory snapshots to detect item movements
- Preview/confirm pattern: stores transaction in session between steps
- Matches items by assetId or properties (handles assetId changes during withdrawals)
- Methods: `prepareDepositPreview()`, `prepareWithdrawPreview()`, `executeDeposit()`, `executeWithdraw()`

**UserConfigService** (`src/Service/UserConfigService.php`)

- Manages user configuration settings
- Validates Steam IDs (SteamID64 format)
- Generates Steam inventory URLs

**ItemService, InventoryService, PriceHistoryService**

- CRUD operations and business logic for their respective domains

### Available Console Commands

```bash
# User management
php bin/console app:create-user
php bin/console app:list-users

# Steam data synchronization
php bin/console app:steam:download-items  # Download from SteamWebAPI
php bin/console app:steam:sync-items      # Sync JSON to database
```

### Current Features

1. **Authentication System**
    - Email/password login with Symfony Security
    - User account management with active status
    - Login tracking (lastLoginAt)

2. **Steam Item Database**
    - Full CS2 item catalog from SteamWebAPI
    - Price history tracking
    - Support for StatTrak, Souvenir, and regular variants
    - Wear categories, rarities, collections

3. **Inventory Management**
    - Manual inventory import from Steam JSON exports
    - Import preview with item matching
    - Float value tracking and wear category calculation
    - Sticker and keychain support with pricing
    - Market value calculations
    - Storage box support (Steam-imported and manual boxes for friend lending)
    - Deposit/withdraw workflows for moving items in/out of storage boxes

4. **User Settings**
    - Steam ID configuration (SteamID64 format)
    - Dynamic Steam inventory links

### Important Workflows

**Importing Inventory:**

1. User navigates to `/inventory/import`
2. If no Steam ID configured, redirect to `/settings`
3. User uploads Steam inventory JSON file
4. System shows preview with matched items and storage boxes
5. User confirms import:
   - Storage boxes synced (Steam boxes only, manual boxes untouched)
   - Only main inventory items (`storageBox = null`) deleted/replaced
   - Items in ANY storage box are preserved
6. Items saved to database as ItemUser records

**Item Matching:**

- Uses `classId` from Steam inventory to match local Item records
- Extracts wear, stickers, keychains from item descriptions/actions
- Calculates total value: base price + sticker prices + keychain value

**Depositing/Withdrawing Items:**

1. User clicks Deposit/Withdraw button on storage box
2. User pastes Steam inventory JSON (tradeable and/or trade-locked)
3. System compares current inventory with new snapshot:
   - **Deposit**: Finds items that disappeared from active inventory
   - **Withdraw**: Finds items that appeared in active inventory
4. Preview shows items to be moved
5. User confirms → items moved between inventory and storage box
6. AssetId changes are handled by matching on item properties (hash name, float, pattern)

**Price Calculation:**

- Stickers: looked up individually (cannot be removed/sold)
- Keychains: looked up as "Charm | {name}" (can be removed, adds value)

## Development Commands

### Docker Development Setup

```bash
# Start all services (PHP, MySQL, web server)
docker compose up -d

# Stop all services
docker compose down

# View logs
docker compose logs -f

# Execute commands in PHP container
docker compose exec php composer install
docker compose exec php php bin/console doctrine:database:create
docker compose exec php php bin/console doctrine:migrations:migrate
```

### Frontend Asset Building (Docker)

```bash
docker compose run --rm node npm install
docker compose run --rm node npm run build

# Watch for changes during development (run locally)
docker compose run --rm node npm run watch
```

### Console Commands (Docker)

```bash
# User management
docker compose exec php php bin/console app:create-user
docker compose exec php php bin/console app:list-users

# Steam data synchronization
docker compose exec php php bin/console app:steam:download-items
docker compose exec php php bin/console app:steam:sync-items
```

### Database Operations (Docker)

```bash
# Generate migration
docker compose exec php php bin/console make:migration

# Run migrations
docker compose exec php php bin/console doctrine:migrations:migrate

# Load fixtures (if available)
docker compose exec php php bin/console doctrine:fixtures:load
```

## Configuration Management

### Environment Variables (.env)

Key configuration variables:

- `APP_ENV`: Application environment (dev/prod)
- `APP_SECRET`: Symfony secret key
- `DATABASE_URL`: MySQL connection string
- `STEAM_WEB_API_KEY`: API key for SteamWebAPI.com
- `STEAM_WEB_API_BASE_URL`: Base URL for SteamWebAPI (https://www.steamwebapi.com/steam/api)
- `STEAM_ITEMS_STORAGE_PATH`: Local path for storing downloaded item JSON files (var/data/steam-items)

### Docker Environment Variables

MySQL configuration in docker-compose.yml:

- `MYSQL_ROOT_PASSWORD`: MySQL root password
- `MYSQL_DATABASE`: Database name (cs2inventory)
- `MYSQL_USER`: Database user
- `MYSQL_PASSWORD`: Database password

## Routes

Key application routes:

- `/login` - User authentication
- `/dashboard` - Main dashboard (after login)
- `/inventory` - View user inventory
- `/inventory/import` - Import inventory from Steam JSON
- `/storage/deposit/{id}` - Deposit items into storage box (preview/confirm)
- `/storage/withdraw/{id}` - Withdraw items from storage box (preview/confirm)
- `/settings` - User settings (Steam ID configuration)

## Database Schema Overview

**Tables:**

- `user` - User accounts with authentication
- `user_config` - User settings (one-to-one with user)
- `item` - CS2 items master data from Steam
- `item_price` - Historical price records for items
- `item_user` - User inventory instances (has FK to storage_box)
- `storage_box` - Storage containers (Steam-imported or manual)

**Key Indexes:**

- `item_user`: composite index on (user_id, item_id), index on storage_box_id
- `storage_box`: unique index on asset_id, index on user_id
- `item`: indexes on type, category, rarity, active, external_id

## Coding Conventions

- **Entities**: Use Doctrine attributes for ORM mapping
- **Services**: Injected via constructor, configured in services.yaml
- **Controllers**: Extend AbstractController, use route attributes
- **Commands**: Extend Command, namespace App\Command
- **Validation**: Use Symfony validators, custom validators in src/Validator/Constraints/
- **DTOs**: Used for data transfer between layers (src/DTO/)
- **Enums**: Backed enums for type safety (src/Enum/)
