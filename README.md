# CS2 Inventory Management System

A web application for tracking, managing, and valuing CS2 (Counter-Strike 2) inventory items. Import your Steam inventory, track item details including float values, stickers, and keychains, view real-time market prices, and organize items in virtual storage boxes.

![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-7+-000000?logo=symfony&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-11.x-003545?logo=mariadb&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Enabled-2496ED?logo=docker&logoColor=white)

---

## Table of Contents

- [Features](#features)
- [Technology Stack](#technology-stack)
- [Prerequisites](#prerequisites)
  - [Development](#development)
  - [Production](#production)
- [Development Setup](#development-setup)
- [Production Deployment](#production-deployment)
  - [Initial Server Setup](#initial-server-setup)
  - [External Database Setup](#external-database-setup)
  - [Application Deployment](#application-deployment)
  - [Post-Deployment Configuration](#post-deployment-configuration)
- [Configuration](#configuration)
  - [Environment Variables](#environment-variables)
  - [Docker Configuration](#docker-configuration)
- [Maintenance](#maintenance)
  - [Updating the Application](#updating-the-application)
  - [Database Migrations](#database-migrations)
  - [Backup and Restore](#backup-and-restore)
  - [Syncing Steam Items](#syncing-steam-items)
  - [Creating Users](#creating-users)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Documentation](#documentation)

---

## Features

- **Steam Inventory Import**: Import your CS2 inventory directly from Steam JSON exports
- **Item Price Tracking**: Real-time market price data from SteamWebAPI.com with historical tracking
- **Storage Box Management**: Organize items in virtual storage boxes (Steam-imported or manual for tracking items lent to friends)
- **Detailed Item Attributes**: Track float values, wear categories (FN/MW/FT/WW/BS), paint seeds, StatTrak counters
- **Sticker & Keychain Support**: Full support for stickers and keychains with individual pricing
- **Market Value Calculations**: Automatic calculation of total item value including base price + sticker prices + keychain value
- **Profit/Loss Tracking**: Track acquired prices and monitor profit/loss on your items
- **Secure Authentication**: Email/password authentication with rate limiting (3 attempts per 5 minutes per IP)
- **User Settings**: Configure your Steam ID for easy inventory access

---

## Technology Stack

- **Backend**: PHP 8.4 + Symfony 7+
- **Frontend**: Tailwind CSS for styling
- **Database**: MariaDB 11.x with Doctrine ORM
- **Containerization**: Docker + Docker Compose for development and production
- **API Integration**: SteamWebAPI.com for CS2 item data and pricing
- **Web Server**: Nginx (production) / Symfony CLI (development)

---

## Prerequisites

### Development

- **Docker** (20.10+) and **Docker Compose** (2.0+)
- **Git**
- Basic knowledge of Docker and command line

**Note**: All development tools (PHP, Composer, Node.js, MariaDB) run inside Docker containers. You do NOT need to install these on your host machine.

### Production

- **Ubuntu 22.04 LTS** server (or compatible Linux distribution)
- **Docker** and **Docker Compose** installed on the server
- **External MariaDB 11.x** database (hosted separately, not in Docker)
- **SSH access** to the server
- **Domain name** (optional but recommended)
- **SSL certificate** (handled externally via CloudFlare, AWS ALB, or reverse proxy)

---

## Development Setup

### 1. Clone Repository

```bash
git clone <repository-url>
cd cs2inventory
```

### 2. Configure Environment

```bash
# Copy the example environment file
cp .env.example .env

# Edit .env with your settings (DATABASE_URL, STEAM_WEB_API_KEY, etc.)
nano .env
```

### 3. Configure Docker Compose for Development

Choose one of the following options:

**Option A - Set environment variable (recommended):**

```bash
# Add to your shell profile (~/.bashrc, ~/.zshrc, etc.)
echo 'export COMPOSE_FILE=compose.yml:compose.dev.yml' >> ~/.bashrc
source ~/.bashrc

# Or for the current session only
export COMPOSE_FILE=compose.yml:compose.dev.yml
```

**Option B - Use the helper file:**

```bash
source .env.compose
```

**Option C - Use `-f` flags each time:**

```bash
docker compose -f compose.yml -f compose.dev.yml [command]
```

### 4. Install Dependencies

```bash
# Install PHP dependencies
docker compose run --rm php composer install

# Install Node.js dependencies
docker compose run --rm node npm install
```

### 5. Start Development Environment

```bash
# Start all services (PHP, MariaDB, Nginx)
docker compose up -d

# View logs (optional)
docker compose logs -f
```

### 6. Setup Database

```bash
# Create database (only needed first time)
docker compose exec php php bin/console doctrine:database:create

# Run migrations
docker compose exec php php bin/console doctrine:migrations:migrate
```

### 7. Build Frontend Assets

```bash
# Build assets for production
docker compose run --rm node npm run build

# Or watch for changes during development
docker compose run --rm node npm run watch
```

### 8. Create Your First User

```bash
docker compose exec php php bin/console app:create-user
```

Follow the prompts to enter your email, password, first name, and last name.

### 9. Access Application

Open your browser and navigate to:

```
http://localhost
```

Log in with the credentials you created in step 8.

### 10. Import Steam Items Database (Optional)

To enable item matching and pricing, sync the CS2 items database:

```bash
# Download items from Steam API (chunked for memory efficiency)
docker compose exec php php bin/console app:steam:download-items

# Sync items to database
docker compose exec php php bin/console app:steam:sync-items
```

This process may take several minutes depending on the number of items.

---

## Production Deployment

### Initial Server Setup

1. **Start with Ubuntu 22.04 LTS** server

2. **Copy setup script to server:**

```bash
# On your local machine
scp deploy/setup.sh user@your-server:/home/user/
```

3. **Run setup script as root:**

```bash
# On the server
sudo ./setup.sh
```

The script will:
- Install Docker and Docker Compose
- Create `gil` user for deployments
- Configure firewall rules
- Set up directory structure

4. **Reboot server:**

```bash
sudo reboot
```

5. **Create deployment SSH key:**

```bash
# On the server as gil user
ssh-keygen -t ed25519 -C "deployment@cs2inventory"
cat ~/.ssh/id_ed25519.pub
```

Add this public key to your Git repository's deploy keys.

6. **Clone repository:**

```bash
# On the server as gil user
git clone git@github.com:your-username/cs2inventory.git
cd cs2inventory
```

### External Database Setup

1. **Provision MariaDB 11.x server** (use managed service like AWS RDS, DigitalOcean, or self-hosted)

2. **Create database:**

```sql
CREATE DATABASE cs2inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

3. **Create user with permissions:**

```sql
CREATE USER 'cs2user'@'%' IDENTIFIED BY 'YourSecurePassword123!';
GRANT ALL PRIVILEGES ON cs2inventory.* TO 'cs2user'@'%';
FLUSH PRIVILEGES;
```

4. **Note connection details** for the next step:
   - Host/IP address
   - Port (usually 3306)
   - Database name: `cs2inventory`
   - Username: `cs2user`
   - Password: (the secure password you created)

5. **Ensure database is accessible** from your Docker host (check firewall rules, security groups, etc.)

### Application Deployment

1. **Create production environment file:**

```bash
# On the server
cp .env.prod.example .env

# Edit with your production values
nano .env
```

2. **Configure environment variables** (see [Configuration](#configuration) section below)

Required variables:
- `APP_ENV=prod`
- `APP_SECRET` (generate with: `openssl rand -hex 32`)
- `DATABASE_URL` (your external MariaDB connection string)
- `STEAM_WEB_API_KEY` (from SteamWebAPI.com)
- `STEAM_WEB_API_BASE_URL=https://www.steamwebapi.com/steam/api`
- `DEFAULT_URI` (your production domain)

Example `DATABASE_URL`:
```
mysql://cs2user:YourSecurePassword123!@db.example.com:3306/cs2inventory?serverVersion=mariadb-11.4.8&charset=utf8mb4
```

3. **Build assets locally** (before deploying):

```bash
# On your local machine in the project directory
docker compose run --rm node npm install
docker compose run --rm node npm run build
```

4. **Commit and push built assets:**

```bash
# On your local machine
git add public/build/
git commit -m "Build production assets"
git push origin main
```

5. **Deploy to server:**

```bash
# On the server
./deploy/update.sh -f
```

The `-f` flag forces a full rebuild (recommended for first deployment).

6. **Verify deployment:**

```bash
# Check containers are running
docker compose ps

# Check logs
docker compose logs -f

# Test database connection
docker compose exec php php bin/console doctrine:query:sql "SELECT 1"
```

### Post-Deployment Configuration

1. **Create first user:**

```bash
docker compose exec php php bin/console app:create-user
```

2. **Sync Steam items database:**

```bash
docker compose exec php php bin/console app:steam:download-items
docker compose exec php php bin/console app:steam:sync-items
```

3. **Access your application:**

Navigate to your domain or server IP address in a browser.

4. **Configure SSL/HTTPS** (handled externally):
   - Set up CloudFlare, AWS ALB, or reverse proxy for SSL termination
   - Update `DEFAULT_URI` in `.env` to use `https://`

---

## Configuration

### Environment Variables

All environment variables are defined in the `.env` file (development) or `.env` (production, copied from `.env.prod.example`).

#### Required Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `APP_ENV` | Application environment | `dev` or `prod` |
| `APP_SECRET` | Symfony secret key (generate with `openssl rand -hex 32`) | `a1b2c3d4e5f6...` |
| `DATABASE_URL` | Database connection string | `mysql://user:pass@host:3306/db?serverVersion=mariadb-11.4.8&charset=utf8mb4` |
| `STEAM_WEB_API_KEY` | API key from SteamWebAPI.com | `your-api-key-here` |
| `STEAM_WEB_API_BASE_URL` | Base URL for Steam API | `https://www.steamwebapi.com/steam/api` |
| `STEAM_ITEMS_STORAGE_PATH` | Local path for item storage | `var/data/steam-items` |

#### Optional Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `DEFAULT_URI` | Application base URL | `http://localhost` |
| `NGINX_PORT` | Port to expose nginx | `80` |

#### Production-Specific Notes

- **`APP_SECRET`**: Must be a strong random string. Never reuse between environments.
- **`DATABASE_URL`**:
  - Use your external MariaDB server details
  - `serverVersion` should match your database version (e.g., `mariadb-11.4.8`)
  - Doctrine requires full semantic version (major.minor.patch)
  - As long as your actual database version >= configured version, you're safe
- **`APP_ENV=prod`**: Enables production optimizations (OPcache, APCu caching, error logging only)

### Docker Configuration

The application uses multiple Docker Compose files for different environments:

| File | Purpose |
|------|---------|
| `compose.yml` | Base service definitions (PHP, Nginx, Node) |
| `compose.dev.yml` | Development overrides (includes MariaDB container, dev builds) |
| `compose.prod.yml` | Production overrides (external DB, prod builds, optimizations) |
| `compose.override.yml` | Auto-generated (gitignored), copied from dev/prod by scripts |

#### Development vs Production

**Development** (`compose.dev.yml`):
- Includes MariaDB 11.4 container
- Mounts source code as volume for live editing
- Uses development PHP settings (higher timeouts, error display)
- Includes Node container for asset building

**Production** (`compose.prod.yml`):
- Uses external MariaDB database (no DB container)
- Copies code into image (no volumes)
- Uses production PHP settings (OPcache enabled, APCu, optimized)
- No Node container (assets built before deployment)
- Nginx optimized with gzip, caching, security headers

#### Production Optimizations

- **PHP OPcache**: Enabled with 256M memory, `validate_timestamps=0` for maximum performance
- **APCu Cache**: Enabled for application cache and rate limiting
- **Nginx Gzip**: Enabled for text/css/js/json compression
- **Static Asset Caching**: 1 year cache for built assets
- **Security Headers**: Server tokens hidden, security headers enabled

---

## Maintenance

### Updating the Application

When deploying code updates or feature changes:

#### On Your Local Machine:

```bash
# 1. Make your code changes

# 2. Build production assets
docker compose run --rm node npm run build

# 3. Commit changes (including built assets)
git add .
git commit -m "Your commit message"

# 4. Push to repository
git push origin main
```

#### On the Production Server:

```bash
# 5. Pull and deploy updates
./deploy/update.sh -f

# 6. Verify deployment
docker compose logs -f
docker compose ps
```

The `update.sh` script will:
- Create a backup in `backups/`
- Pull latest code from Git
- Rebuild Docker images
- Run database migrations
- Restart containers
- Verify deployment

**Note**: With `opcache.validate_timestamps=0` in production, code changes require a container restart to take effect.

### Database Migrations

Migrations run automatically during the `update.sh` script. To run manually:

```bash
# Check migration status
docker compose exec php php bin/console doctrine:migrations:status

# Run migrations
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Generate new migration (after entity changes)
docker compose exec php php bin/console make:migration
```

### Backup and Restore

#### Database Backup

**Using mysqldump:**

```bash
# Backup database
mysqldump -h [db-host] -u [db-user] -p cs2inventory > backup-$(date +%Y%m%d-%H%M%S).sql

# Restore database
mysql -h [db-host] -u [db-user] -p cs2inventory < backup-20250106-120000.sql
```

**Automated backups:**

Set up a cron job on your database server or use your hosting provider's backup features.

#### Code Backup

The `deploy/update.sh` script automatically creates code backups in the `backups/` directory before each deployment:

```bash
# Backups are stored as: backups/backup-YYYYMMDD-HHMMSS.tar.gz
ls -lh backups/

# Restore from backup (manual process)
# 1. Stop containers
docker compose down

# 2. Extract backup
tar -xzf backups/backup-20250106-120000.tar.gz

# 3. Restart containers
docker compose up -d
```

### Syncing Steam Items

The CS2 items database should be updated periodically to include new items and price changes:

```bash
# Download latest items from Steam API (chunked for memory efficiency)
docker compose exec php php bin/console app:steam:download-items

# Sync items to database
docker compose exec php php bin/console app:steam:sync-items
```

**Recommendation**: Run this weekly or whenever new CS2 items are released.

### Creating Users

```bash
# Create a new user (interactive)
docker compose exec php php bin/console app:create-user

# List all users
docker compose exec php php bin/console app:list-users
```

The `app:create-user` command will prompt for:
- Email address
- Password (minimum 8 characters)
- First name
- Last name

### Clearing Cache

If you encounter issues after updates:

```bash
# Clear production cache
docker compose exec php php bin/console cache:clear --env=prod

# Or development cache
docker compose exec php php bin/console cache:clear --env=dev
```

---

## Troubleshooting

### Common Issues

#### Container Won't Start

**Symptoms**: Container exits immediately or fails to start

**Solutions**:

```bash
# Check logs for error messages
docker compose logs -f php
docker compose logs -f nginx

# Check container status
docker compose ps

# Rebuild containers
docker compose down
docker compose build --no-cache
docker compose up -d
```

#### Database Connection Failed

**Symptoms**: "Connection refused" or "Access denied" errors

**Solutions**:

```bash
# Verify DATABASE_URL in .env is correct
cat .env | grep DATABASE_URL

# Test database connection from PHP container
docker compose exec php php bin/console dbal:run-sql "SELECT 1"

# Test database connection with mysql client
docker compose exec php mysql -h [db-host] -u [db-user] -p

# Check database server is reachable
docker compose exec php ping [db-host]

# Verify firewall/security group rules allow connection from Docker host
```

#### Migration Failed

**Symptoms**: Migration command exits with error

**Solutions**:

```bash
# Check migration status
docker compose exec php php bin/console doctrine:migrations:status

# Check error details in logs
docker compose logs -f php

# Manually review the failed migration file in src/Migrations/

# If safe to do so, mark migration as executed
docker compose exec php php bin/console doctrine:migrations:version --add VersionYYYYMMDDHHMMSS

# Or rollback and try again
docker compose exec php php bin/console doctrine:migrations:migrate prev
docker compose exec php php bin/console doctrine:migrations:migrate
```

#### Assets Not Loading

**Symptoms**: Styles missing, JavaScript not working, 404 errors for /build/ files

**Solutions**:

```bash
# Verify built assets exist
ls -la public/build/

# Rebuild assets
docker compose run --rm node npm run build

# Check Nginx configuration
docker compose exec nginx cat /etc/nginx/conf.d/default.conf

# Check file permissions
docker compose exec php ls -la public/build/

# Clear Symfony cache
docker compose exec php php bin/console cache:clear
```

#### Login Rate Limiting

**Symptoms**: "Too many failed login attempts" message even with correct password

**Solutions**:

```bash
# Wait 5 minutes for rate limit to reset

# Or clear rate limiter cache
docker compose exec php php bin/console cache:pool:clear cache.rate_limiter

# Check rate limiter configuration in config/packages/framework.yaml
```

#### Permission Errors

**Symptoms**: "Permission denied" when writing to var/ directory

**Solutions**:

```bash
# Fix permissions in var/ directory
docker compose exec php chown -R appuser:appuser var/
docker compose exec php chmod -R 775 var/

# Or from host (if containers use same UID)
sudo chown -R 1000:1000 var/
sudo chmod -R 775 var/
```

#### Code Changes Not Reflected (Production)

**Symptoms**: Code changes don't appear after deployment

**Cause**: OPcache has `validate_timestamps=0` in production for maximum performance

**Solutions**:

```bash
# Restart PHP container to clear OPcache
docker compose restart php

# Or rebuild and restart
docker compose build php
docker compose up -d
```

### Debugging Commands

```bash
# View logs for specific service
docker compose logs -f [php|nginx|mysql]

# View all logs
docker compose logs -f

# Enter PHP container for debugging
docker compose exec php bash

# Check PHP configuration
docker compose exec php php -i
docker compose exec php php -m  # List installed modules

# Check OPcache status (production)
docker compose exec php php -r "print_r(opcache_get_status());"

# Run Symfony debug commands
docker compose exec php php bin/console debug:router      # List routes
docker compose exec php php bin/console debug:container   # List services
docker compose exec php php bin/console debug:config      # Show configuration

# Test database connection
docker compose exec php php bin/console doctrine:query:sql "SELECT VERSION()"

# Check disk space
docker compose exec php df -h
```

### Getting Help

If you encounter an issue not covered here:

1. **Check logs**: Always start with `docker compose logs -f`
2. **Search issues**: Check the project's issue tracker on GitHub
3. **Review documentation**: See [CLAUDE.md](CLAUDE.md) for detailed technical documentation
4. **Create an issue**: Provide logs, error messages, and steps to reproduce

---

## Contributing

Contributions are welcome! Here's how to get started:

### 1. Fork Repository

Click the "Fork" button on GitHub to create your own copy of the repository.

### 2. Clone Your Fork

```bash
git clone git@github.com:your-username/cs2inventory.git
cd cs2inventory
```

### 3. Create Feature Branch

```bash
git checkout -b feature/your-feature-name
```

Use descriptive branch names:
- `feature/add-trade-history` for new features
- `fix/login-rate-limit` for bug fixes
- `docs/deployment-guide` for documentation

### 4. Make Changes

Follow the project's coding conventions (see [CLAUDE.md](CLAUDE.md)):
- Use Doctrine attributes for ORM mapping
- Services injected via constructor
- Controllers extend AbstractController
- Use Symfony validators for validation
- Write PHPDoc comments for methods

### 5. Test Locally

```bash
# Start development environment
docker compose up -d

# Run your changes and verify they work

# Check for errors in logs
docker compose logs -f php
```

### 6. Commit Changes

```bash
git add .
git commit -m "Brief description of changes"
```

Write clear commit messages:
- Use present tense ("Add feature" not "Added feature")
- First line: brief summary (50 chars or less)
- Blank line
- Detailed explanation if needed

### 7. Push to Your Fork

```bash
git push origin feature/your-feature-name
```

### 8. Submit Pull Request

1. Go to your fork on GitHub
2. Click "Compare & pull request"
3. Provide a clear description of your changes
4. Reference any related issues

### Code Review Process

- Maintainers will review your PR
- Address any feedback or requested changes
- Once approved, your PR will be merged

### Development Guidelines

- **Docker-Only**: NEVER run PHP, Composer, npm, or database commands directly on host. Always use Docker containers.
- **Security**: Follow OWASP best practices, validate user input, use parameterized queries
- **Testing**: Test your changes thoroughly in development environment before submitting
- **Documentation**: Update CLAUDE.md if you change architecture or add major features

---

## Documentation

- **[CLAUDE.md](CLAUDE.md)** - Complete technical documentation including:
  - Detailed architecture and entity relationships
  - Service documentation
  - Workflow explanations
  - Console commands
  - Security features
  - Coding conventions

- **[deploy/README.md](deploy/README.md)** - Detailed deployment documentation including:
  - Server setup scripts
  - Deployment workflows
  - Update procedures
  - Troubleshooting deployment issues

- **[tasks/](tasks/)** - Project task and feature documentation

