# PHP Docker Configuration

## Architecture Overview

This setup uses a multi-stage Dockerfile with separate configurations for development and production environments.

## Configuration Files

### Base Configuration (All Environments)
- **symfony.ini** - Base Symfony settings loaded in all environments
  - Applied during build time (COPY to container)
  - Settings: expose_php, execution time, upload limits, memory, etc.

### Development Configuration
- **php.ini** - Development PHP settings
  - Mounted at runtime via compose.dev.yml and compose.override.yml
  - Location: `/usr/local/etc/php/php.ini`
  - Features: Error display ON, assertions enabled, OPcache disabled

- **xdebug.ini** - Xdebug debugger configuration
  - Mounted at runtime via compose.dev.yml and compose.override.yml
  - Location: `/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini`
  - Features: Debug mode, client_host, start_with_request

- **development.ini** - Additional development overrides (currently unused)
  - Available for future development-specific settings

### Production Configuration
- **php.prod.ini** - Production PHP settings
  - Mounted at runtime via compose.prod.yml
  - Location: `/usr/local/etc/php/php.ini`
  - Features: Error display OFF, OPcache enabled with validate_timestamps=0, APCu enabled, security hardening

- **preload.ini** - OPcache preload configuration
  - Copied during build (production stage only)
  - Location: `/usr/local/etc/php/conf.d/preload.ini`
  - Preloads Symfony autoloader for maximum performance

- **disable-access-logs.conf** - PHP-FPM access log disabling
  - Copied during build (production stage only)
  - Reduces I/O overhead in production

- **increase-fpm-children.conf** - PHP-FPM worker pool settings
  - Copied during build (production stage only)
  - Sets pm.max_children = 10

## Configuration Loading Order

PHP loads configuration in this order:

1. **php.ini-production** or **php.ini-development** (from base image)
2. **symfony.ini** (copied during build, applies to ALL stages)
3. **php.ini** or **php.prod.ini** (mounted at runtime, environment-specific)
4. Files in **conf.d/** directory (alphabetically):
   - **docker-php-ext-xdebug.ini** (dev only, mounted)
   - **preload.ini** (prod only, copied during build)
   - Other extension configs from base image

Later files override earlier ones, so runtime-mounted files take precedence.

## Docker Stages

### php-base
- Base stage with common setup
- Creates user with configurable UID/GID
- Copies symfony.ini
- Sets working directory and switches to app user

### php-development (extends php-base)
- Installs xdebug via pecl
- Does NOT copy any additional configs (mounted at runtime instead)
- Full project directory mounted as volume
- OPcache disabled for live code changes

### php-production (extends php-base)
- Copies entire application code into image
- Installs production-only dependencies (no dev packages)
- Copies PHP-FPM production configs
- Copies preload.ini for OPcache preloading
- Code baked into image (no volumes except var/ directories)
- OPcache enabled with validate_timestamps=0 (no file checks)

## Usage

### Development
```bash
# Auto-loads compose.override.yml (development settings)
docker compose up -d

# Or explicitly use compose.dev.yml
docker compose -f compose.yml -f compose.dev.yml up -d
```

Configuration loaded:
- symfony.ini (from build)
- php.ini (mounted)
- xdebug.ini (mounted)

### Production
```bash
# Explicit production override
docker compose -f compose.yml -f compose.prod.yml up -d

# Or for deployment automation
cp compose.prod.yml compose.override.yml
docker compose up -d
```

Configuration loaded:
- symfony.ini (from build)
- php.prod.ini (mounted)
- preload.ini (from build)
- disable-access-logs.conf (from build)
- increase-fpm-children.conf (from build)

## Build Arguments

All stages accept these build arguments:
- **USER** - Username (default: www-data)
- **UID** - User ID (default: 1000 for prod, 1001 for dev)
- **GID** - Group ID (default: 1000 for prod, 1001 for dev)

## Key Differences: Dev vs Prod

| Feature | Development | Production |
|---------|------------|------------|
| OPcache | Disabled | Enabled (validate_timestamps=0) |
| Error Display | ON | OFF (logged only) |
| Code Location | Mounted volume | Baked into image |
| Xdebug | Installed & enabled | Not installed |
| Memory Limit | 256M | 512M |
| Max Execution | 600s | 60s |
| Upload Limit | 96M/64M | 10M/10M |
| Preloading | Disabled | Enabled (Symfony) |
| APCu | N/A | Enabled (64M) |
| Assertions | Enabled (1) | Disabled (-1) |

## Troubleshooting

### Verify loaded configuration
```bash
# Development
docker compose exec php php -i | grep -E "(Configuration File|Loaded Configuration|opcache|xdebug)"

# Production
docker compose -f compose.yml -f compose.prod.yml exec php php -i | grep -E "(Configuration File|Loaded Configuration|opcache)"
```

### Check specific settings
```bash
docker compose exec php php -r "echo ini_get('opcache.enable');"
docker compose exec php php -r "echo ini_get('display_errors');"
docker compose exec php php -r "echo ini_get('memory_limit');"
```

### Code changes not reflected (Production)
With `opcache.validate_timestamps=0`, you must restart the container after code changes:
```bash
docker compose -f compose.yml -f compose.prod.yml restart php
```

### Permission issues
Ensure UID/GID build arguments match your host user:
```bash
id -u  # Get your UID
id -g  # Get your GID

# Update compose.dev.yml or compose.override.yml with your values
```
