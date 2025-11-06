# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CS2 Inventory Management System - A web application for tracking, managing, and valuing CS2 (Counter-Strike 2) inventory items. Users can import their Steam inventory, track item details (float
values, stickers, keychains), view market prices, and manage items in virtual storage boxes.

## Technology Stack

- **Backend**: PHP 8.4 + Symfony 7+
- **Frontend**: Tailwind CSS for styling
- **Database**: MariaDB 11.x with Doctrine ORM
- **Containerization**: Docker + Docker Compose for development and deployment
- **API Integration**: SteamWebAPI.com for CS2 item data

## CRITICAL: Docker-Only Environment

**⚠️ IMPORTANT: This project MUST run entirely within Docker containers. NEVER run PHP, Composer, MariaDB, npm, or any project commands directly on the host machine.**

- ✅ Always use: `docker compose exec php php bin/console ...`
- ✅ Always use: `docker compose run --rm php composer ...`
- ✅ Always use: `docker compose run --rm node npm ...`
- ❌ Never use: `php bin/console ...` (bare command)
- ❌ Never use: `composer ...` (bare command)
- ❌ Never use: `npm ...` (bare command)

All commands in this document are shown with Docker wrappers. If you see a command without `docker compose`, it is an error.

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
- Fields: assetId (nullable), name, itemCount, reportedCount, modificationDate
- `reportedCount`: Steam's reported item count, ALWAYS updated during inventory imports, NEVER touched by deposit/withdraw
- `itemCount`: Kept in sync with reportedCount during imports, NEVER touched by deposit/withdraw
- `actualCount`: Computed on-demand from database (not a field), used to detect sync issues
- Compare reportedCount vs actualCount to see if database is out of sync with Steam
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

- Fetches CS2 item data from SteamWebAPI.com using pagination
- Methods: `fetchItemsPaginated()` (5500 items per request), `getItemPrices()`
- Requires: `STEAM_WEB_API_KEY` env variable

**ItemSyncService** (`src/Service/ItemSyncService.php`)

- Syncs item data from JSON files to database (single file or chunked)
- Handles deduplication by external_id
- Supports deferred deactivation for chunked processing
- Maps API fields to Item entity
- Tracks price history

**InventoryImportService** (`src/Service/InventoryImportService.php`)

- Parses Steam inventory JSON exports (including storage boxes)
- Matches items by classId to local database
- Extracts: float values, stickers, keychains, StatTrak counters
- Preview workflow: compares new import vs current inventory (by assetId), enriches items with prices
- **Import behavior**: Only deletes items with `storageBox = null`; items in storage boxes are preserved
- Calculates market values including sticker/keychain prices

**StorageBoxService** (`src/Service/StorageBoxService.php`)

- Creates and manages storage boxes
- Syncs Steam storage boxes during import (by assetId)
- Sets `reportedCount` only on first import, never updates it after
- Creates manual storage boxes for friend lending tracking
- Manual boxes are never touched by import process

**StorageBoxTransactionService** (`src/Service/StorageBoxTransactionService.php`)

- Handles deposit/withdraw workflows for storage boxes
- Compares inventory snapshots to detect item movements
- Preview/confirm pattern: stores transaction in session between steps
- Matches items by assetId or properties (handles assetId changes during withdrawals)
- Does NOT update `itemCount` or `reportedCount` on transactions
- Methods: `prepareDepositPreview()`, `prepareWithdrawPreview()`, `executeDeposit()`, `executeWithdraw()`

**UserConfigService** (`src/Service/UserConfigService.php`)

- Manages user configuration settings
- Validates Steam IDs (SteamID64 format)
- Generates Steam inventory URLs

**ItemService, InventoryService, PriceHistoryService**

- CRUD operations and business logic for their respective domains

### Current Features

1. **Authentication System**
    - Email/password login with Symfony Security
    - User account management with active status
    - Login tracking (lastLoginAt)
    - **Login rate limiting**: 3 failed attempts per 5 minutes per IP address (protection against brute force attacks)

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
4. Preview shows items to add (NEW badge) vs items to remove (REMOVE badge) with prices
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
# Start all services (PHP, MariaDB, web server)
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

# Watch for changes during development
docker compose run --rm node npm run watch
```

### Console Commands (Docker)

```bash
# User management
docker compose exec php php bin/console app:create-user
docker compose exec php php bin/console app:list-users

# Steam data synchronization (chunked for memory efficiency)
docker compose exec php php bin/console app:steam:download-items  # Downloads chunks
docker compose exec php php bin/console app:steam:sync-items      # Syncs chunks to DB
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

## Production Deployment

### Production Docker Configuration

The application uses a multi-stage Dockerfile with separate configurations for development and production environments.

#### Production Architecture

- **PHP-FPM**: Production-optimized with OPcache enabled, APCu caching, no development tools
- **Nginx**: Production-optimized with gzip compression, security headers, proper timeouts
- **External Database**: MariaDB 11.x hosted externally (not containerized)
- **SSL**: Terminated externally via CloudFlare, AWS ALB, or reverse proxy

#### Production Files

- `compose.prod.yml`: Production Docker Compose configuration (no MySQL/Node containers)
- `docker/php/php.prod.ini`: Production PHP settings with OPcache
- `docker/nginx/production.conf`: Production-optimized nginx configuration
- `config/packages/prod/cache.yaml`: Production cache configuration (APCu)
- `.env.prod.example`: Template for production environment variables
- `.dockerignore`: Excludes development files from Docker image

### Setting Up Production Environment

#### 1. Prepare External MariaDB Database

```bash
# Connect to your MariaDB server
mysql -u root -p

# Create database
CREATE DATABASE cs2inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Create user with secure password
CREATE USER 'cs2user'@'%' IDENTIFIED BY 'YourSecurePassword123!';

# Grant permissions
GRANT ALL PRIVILEGES ON cs2inventory.* TO 'cs2user'@'%';
FLUSH PRIVILEGES;
```

Ensure the MariaDB server is accessible from your Docker host (check firewall rules, security groups, etc.).

#### 2. Configure Environment Variables

```bash
# Copy the production environment template
cp .env.prod.example .env

# Edit .env with your production values
nano .env
```

Required environment variables:

- `APP_ENV=prod` - Must be 'prod' for production
- `APP_SECRET` - Generate strong random secret: `openssl rand -hex 32`
- `DATABASE_URL` - External MySQL connection string
- `STEAM_WEB_API_KEY` - Your Steam Web API key
- `DEFAULT_URI` - Your production domain (https://your-domain.com)
- `NGINX_PORT` - Port to expose nginx (default: 80)

Example `DATABASE_URL`:
```
mysql://cs2user:YourSecurePassword123!@db.example.com:3306/cs2inventory?serverVersion=mariadb-11.4.8&charset=utf8mb4
```

**Important**: Doctrine requires full semantic version (major.minor.patch) for `serverVersion`. The version should match or be slightly behind your production database version. As long as your actual database version is >= the configured version, you're safe.

#### 3. Build Frontend Assets

Assets must be built BEFORE deploying to production (using Node container):

```bash
# Install dependencies (using Node container)
docker compose run --rm node npm install

# Build production assets (using Node container)
docker compose run --rm node npm run build

# Assets will be in public/build/ and copied to Docker image
```

#### 4. Build and Deploy

```bash
# Build production Docker image
docker compose build

# Start production containers
docker compose up -d

# Run database migrations
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Verify services are running
docker compose ps
```

#### 5. Verify Production Configuration

```bash
# Check PHP OPcache is enabled
docker compose exec php php -i | grep opcache.enable

# Check APCu is available
docker compose exec php php -i | grep apcu

# Verify database connection
docker compose exec php php bin/console doctrine:query:sql "SELECT 1"

# Check application health
curl http://localhost/login
```

### Production Console Commands

All console commands in production must use the production compose file:

```bash
# User management
docker compose exec php php bin/console app:create-user
docker compose exec php php bin/console app:list-users

# Steam data synchronization
docker compose exec php php bin/console app:steam:download-items
docker compose exec php php bin/console app:steam:sync-items

# Database migrations
docker compose exec php php bin/console doctrine:migrations:migrate

# Clear cache (required after code changes)
docker compose exec php php bin/console cache:clear --env=prod
```

### Production Deployment Workflow

When deploying code updates to production:

```bash
# 1. Build assets (using Node container)
docker compose run --rm node npm run build

# 2. Rebuild Docker image (includes new code and assets)
docker compose build --no-cache

# 3. Stop old containers
docker compose down

# 4. Start new containers
docker compose up -d

# 5. Run migrations if needed
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# 6. Verify deployment
docker compose logs -f
```

**Important**: With `opcache.validate_timestamps=0`, code changes require container restart to take effect.

### Production Configuration Details

#### PHP Production Settings (`docker/php/php.prod.ini`)

- **OPcache**: Enabled with 256M memory, validate_timestamps=0 for maximum performance
- **APCu**: Enabled for caching (rate limiter, application cache)
- **Memory**: 512M limit (vs 256M in dev)
- **Timeouts**: 60s execution time (vs 300s in dev)
- **Uploads**: 10M max (vs 32M in dev)
- **Errors**: Logged only, not displayed
- **Security**: expose_php=Off, allow_url_include=Off

#### Nginx Production Settings (`docker/nginx/production.conf`)

- **Gzip**: Enabled for text/css/js/json compression
- **Timeouts**: 60s to match PHP settings
- **Client Size**: 10M max body size
- **Security**: Server tokens hidden, security headers enabled
- **Caching**: Static assets cached for 1 year
- **Buffers**: Optimized for production traffic

#### Cache Configuration (`config/packages/prod/cache.yaml`)

- **Application Cache**: Uses APCu (faster than filesystem)
- **Rate Limiter**: Uses APCu for distributed rate limiting
- **System Cache**: Uses filesystem

For multi-server deployments, consider using Redis instead of APCu.

### SSL/HTTPS Configuration

The production setup expects SSL termination to be handled externally:

- Nginx listens on HTTP (port 80) only
- CloudFlare, AWS ALB, or reverse proxy handles HTTPS
- Configure trusted proxies in `config/packages/framework.yaml`:

```yaml
framework:
    trusted_proxies: '127.0.0.1,REMOTE_ADDR'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port']
```

### Production Security Checklist

- [ ] Change `APP_SECRET` to strong random value
- [ ] Use strong database password
- [ ] Keep `STEAM_WEB_API_KEY` private
- [ ] Set `.env` file permissions: `chmod 600 .env`
- [ ] Never commit `.env` with real secrets to git
- [ ] Enable HTTPS via external proxy/CDN
- [ ] Configure firewall rules (only allow necessary ports)
- [ ] Set up automated backups for MySQL database
- [ ] Monitor application logs regularly
- [ ] Keep Docker images updated

### Troubleshooting Production Issues

#### Database Connection Errors

```bash
# Test database connection from PHP container
docker compose exec php php bin/console dbal:run-sql "SELECT 1"

# Check MariaDB is accessible from Docker host
docker compose exec php mysql -h DB_HOST -u DB_USER -p
```

#### OPcache Not Working

```bash
# Verify OPcache is enabled and configured
docker compose exec php php -i | grep opcache

# Check OPcache statistics
docker compose exec php php -r "print_r(opcache_get_status());"
```

#### Code Changes Not Reflected

With `opcache.validate_timestamps=0`, restart containers after code changes:

```bash
docker compose restart php nginx
```

#### Permission Issues

```bash
# Fix var directory permissions
docker compose exec php chown -R appuser:appuser var/
docker compose exec php chmod -R 775 var/
```

## Configuration Management

### Environment Variables (.env)

Key configuration variables:

- `APP_ENV`: Application environment (dev/prod)
- `APP_SECRET`: Symfony secret key
- `DATABASE_URL`: MySQL connection string
- `STEAM_WEB_API_KEY`: API key for SteamWebAPI.com
- `STEAM_WEB_API_BASE_URL`: Base URL for SteamWebAPI (https://www.steamwebapi.com/steam/api)
- `STEAM_ITEMS_STORAGE_PATH`: Local path for chunk storage (var/data/steam-items with import/ and processed/ subdirs)

### Docker Environment Variables

MariaDB configuration in docker-compose.yml:

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

## Security Features

### Login Rate Limiting

The application implements login rate limiting to protect against brute force attacks:

- **Policy**: Sliding window algorithm
- **Limit**: 3 failed login attempts per 5 minutes per IP address
- **Implementation**: `LoginRateLimitSubscriber` (src/EventSubscriber/LoginRateLimitSubscriber.php)
- **Configuration**: `config/packages/framework.yaml` (rate_limiter section)
- **Dependencies**:
  - `symfony/rate-limiter` - Rate limiting component
  - `symfony/lock` - Lock component for atomic operations
- **Storage**: Uses Symfony cache (filesystem by default, can be configured for Redis/APCu)

**Behavior**:
- Rate limit is checked before authentication attempt
- Failed logins consume a token from the rate limiter
- Successful logins reset the rate limiter for that IP
- When limit is exceeded, users see: "Too many failed login attempts. Please try again in X minute(s)."

**Trusted Proxies**: Configured for Docker environment to correctly detect client IP addresses through nginx proxy.

**Adjusting Rate Limits**: Edit the `limit` and `interval` values in `config/packages/framework.yaml`:
```yaml
framework:
    rate_limiter:
        login:
            policy: 'sliding_window'
            limit: 3              # Change number of attempts
            interval: '5 minutes' # Change time window
```

### Other Security Features

- **CSRF Protection**: Enabled globally for all forms
- **Password Hashing**: Uses Symfony's 'auto' algorithm (bcrypt/argon2)
- **SQL Injection Prevention**: All queries use Doctrine ORM with parameterized queries
- **XSS Prevention**: Twig auto-escaping enabled by default
- **Access Control**: All protected routes require `ROLE_USER` via `#[IsGranted]` attribute
- **User Ownership Validation**: Controllers validate user ownership before accessing/modifying resources
- **Session Security**: Secure session cookies with SameSite=Lax protection
- **Open Redirect Prevention**: Redirect parameters validated against whitelist

See `SECURITY_AUDIT_REPORT.md` for detailed security audit findings.

## Coding Conventions

- **Entities**: Use Doctrine attributes for ORM mapping
- **Services**: Injected via constructor, configured in services.yaml
- **Controllers**: Extend AbstractController, use route attributes
- **Commands**: Extend Command, namespace App\Command
- **Validation**: Use Symfony validators, custom validators in src/Validator/Constraints/
- **DTOs**: Used for data transfer between layers (src/DTO/)
- **Enums**: Backed enums for type safety (src/Enum/)
- **Event Subscribers**: Use EventSubscriberInterface for event handling (src/EventSubscriber/)
