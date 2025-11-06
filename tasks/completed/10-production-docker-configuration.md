# Production Docker Configuration

**Status**: Completed
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-05
**Completed**: 2025-11-05

## Overview

Create production-ready Docker configuration (`compose.prod.yml`) that uses an external MySQL database instead of a containerized one, optimizes for production use, and removes development tools.

## Problem Statement

The current `docker-compose.yml` is designed for development with:
- Containerized MySQL database (not suitable for production)
- Development environment variables
- Development-focused PHP configuration
- Exposed MySQL port (security risk)
- Node service (not needed in production with pre-built assets)

We need a production compose file that:
- Connects to external MySQL database
- Uses production environment variables
- Optimizes PHP for production
- Removes unnecessary services
- Improves security posture

## Requirements

### Functional Requirements
- Connect to external MySQL database via environment variables
- Remove MySQL container from production setup
- Remove Node service (assets built locally before deployment)
- Use production APP_ENV
- Configure production PHP settings (OPcache, memory limits)
- Support SSL termination handled externally
- Run containers with restart policy for production

### Non-Functional Requirements
- Minimal container footprint (only necessary services)
- Optimized PHP performance (OPcache enabled)
- Secure configuration (no exposed ports, production secrets)
- Easy to configure via .env file

## Technical Approach

### Services to Include
- **php**: PHP-FPM with production configuration (port 9002)
- **nginx**: Web server (port 9003, SSL terminated externally)

### Services to Remove
- **mysql**: Use external database instead
- **node**: Assets pre-built locally before deployment

### Configuration Files
- `compose.prod.yml`: Production Docker Compose configuration
- `compose.override.yml.example`: Example override for local production testing
- `docker/php/php.prod.ini`: Production PHP configuration
- `.env.example`: Example environment variables for production

### Environment Variables
Production requires:
- `DATABASE_URL`: Connection string to external MySQL
- `APP_ENV=prod`
- `APP_SECRET`: Strong random secret
- `STEAM_WEB_API_KEY`: API key for Steam integration
- Other app-specific variables

## Implementation Steps

1. **Create compose.prod.yml**
   - Copy docker-compose.yml as starting point
   - Remove mysql service entirely
   - Remove node service entirely
   - Configure php service for production:
     - Remove volume mounts for source code (use COPY in Dockerfile)
     - Use production PHP INI file
     - Set APP_ENV=prod
     - Remove USER_ID/GROUP_ID args (not needed in prod)
   - Configure nginx service for production:
     - Keep port 9003 (SSL terminated externally)
     - Remove source code volume mount
     - Use production nginx config

2. **Create Production PHP Configuration**
   - Create `docker/php/php.prod.ini`
   - Enable OPcache:
     ```ini
     opcache.enable=1
     opcache.memory_consumption=256
     opcache.interned_strings_buffer=16
     opcache.max_accelerated_files=20000
     opcache.validate_timestamps=0
     ```
   - Set production limits:
     ```ini
     memory_limit=512M
     upload_max_filesize=10M
     post_max_size=10M
     max_execution_time=60
     ```
   - Disable development features:
     ```ini
     display_errors=Off
     display_startup_errors=Off
     log_errors=On
     ```

3. **Update Dockerfile for Production**
   - Consider multi-stage build:
     - Stage 1: Install composer dependencies
     - Stage 2: Copy production code without vendor
     - Final stage: Production-ready image
   - Or keep simple: COPY code and run composer install --no-dev --optimize-autoloader

4. **Create .env.prod.example**
   - Template for production environment variables
   - External DATABASE_URL format: `mysql://user:password@hostname:3306/database?serverVersion=8.0`
   - Production APP_SECRET placeholder
   - All required variables documented

5. **Update compose.prod.yml Database Connection**
   - Use DATABASE_URL from .env file
   - Remove depends_on: mysql (no longer exists)
   - Remove mysql network dependencies

6. **Production Nginx Configuration**
   - Review `docker/nginx/default.conf`
   - Ensure production-ready:
     - Proper PHP-FPM timeouts
     - Client body size limits
     - Security headers (if not handled by external proxy)
   - Consider creating `docker/nginx/production.conf` if changes needed

7. **Configure Cache for Rate Limiter**
   - Create `config/packages/prod/cache.yaml` (if not exists)
   - Configure APCu or filesystem cache:
     ```yaml
     framework:
         cache:
             app: cache.adapter.apcu
     ```
   - Or use filesystem: `cache.adapter.filesystem`

8. **Update .dockerignore**
   - Ensure development files aren't copied to production image:
     - `.git/`
     - `.env.local`
     - `node_modules/`
     - `var/cache/*` (except .gitkeep)
     - `var/log/*` (except .gitkeep)

9. **Test Production Compose Locally**
   - Create .env.prod with test external database
   - Run: `docker compose -f docker-compose.yml -f compose.prod.yml up -d`
   - Verify application works
   - Check PHP configuration: `docker compose exec php php -i | grep opcache`
   - Test database connectivity

10. **Document Production Configuration**
    - Update CLAUDE.md with production setup
    - Document required environment variables
    - Add notes about external database requirements

## Edge Cases & Error Handling

- **External database unreachable**: Application won't start, clear error in logs
- **Missing environment variables**: Docker Compose will fail to start, document required vars
- **OPcache with volume mounts**: If using volumes in prod (not recommended), must set `opcache.validate_timestamps=1`
- **File permissions in production**: Consider running as non-root user in production image

## Dependencies

- External MySQL database must be provisioned
- SSL termination handled externally (CloudFlare, ALB, etc.)
- Assets must be built locally before deployment

## Acceptance Criteria

- [x] compose.prod.yml created and tested
- [x] PHP service connects to external DATABASE_URL
- [x] MySQL container is removed from production setup
- [x] Node container is removed from production setup
- [x] docker/php/php.prod.ini created with OPcache enabled
- [x] Production environment variables documented in .env.prod.example
- [x] OPcache is enabled and verified in production mode
- [x] Containers restart automatically (restart: unless-stopped)
- [x] Multi-stage Dockerfile with production target created
- [x] APCu installed for production caching
- [x] CLAUDE.md updated with production configuration guide
- [x] .dockerignore created to exclude development files
- [x] Production nginx configuration optimized (docker/nginx/production.conf)
- [x] Production cache configuration created (config/packages/prod/cache.yaml)

## Notes & Considerations

- **External MySQL Setup**: Requires:
  - MySQL 8.0 server accessible from Docker host
  - Database created: `CREATE DATABASE cs2inventory;`
  - User with permissions: `GRANT ALL ON cs2inventory.* TO 'user'@'%';`
  - Connection from Docker requires host network or proper routing

- **SSL Termination**:
  - Nginx listens on port 9003 only
  - External proxy (CloudFlare, ALB, nginx) handles HTTPS
  - May need to configure Symfony to trust proxies:
    ```yaml
    # config/packages/framework.yaml
    framework:
        trusted_proxies: '127.0.0.1,REMOTE_ADDR'
        trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port']
    ```

- **OPcache in Production**:
  - Must be enabled for performance
  - `validate_timestamps=0` means code changes require container restart
  - Deployment workflow: build new image → restart containers

- **Volumes in Production**:
  - Avoid mounting source code as volume
  - Use COPY in Dockerfile instead
  - Exceptions: var/log, var/cache (ephemeral), uploaded files (if any)

## Related Tasks

- Task 11: Update Deploy Scripts (depends on this)
- Task 9: Login Rate Limiting (needs cache configuration)
