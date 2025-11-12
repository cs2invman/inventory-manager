# Production Environment Guide

This document covers production environment configuration, operations, and maintenance for CS2 Inventory Management System.

**For deployment instructions, see [deploy/README.md](deploy/README.md).**

## Production Architecture

- **PHP-FPM**: Production-optimized with OPcache, APCu caching
- **Nginx**: Gzip compression, security headers, optimized timeouts
- **Database**: MariaDB 11.x hosted externally (not containerized)
- **SSL**: Terminated externally via CloudFlare, AWS ALB, or reverse proxy
- **Assets**: Built locally, copied into Docker image (no Node container)

## Environment Variables

Production-specific variables in `.env`:

### Required

| Variable | Description | Example |
|----------|-------------|---------|
| `APP_ENV` | Must be `prod` | `prod` |
| `APP_SECRET` | Strong random secret | Generate: `openssl rand -hex 32` |
| `DATABASE_URL` | External MariaDB connection | `mysql://user:pass@db.host:3306/cs2inventory?serverVersion=mariadb-11.4.8&charset=utf8mb4` |
| `STEAM_WEB_API_KEY` | Steam API key | From SteamWebAPI.com |
| `DEFAULT_URI` | Production domain | `https://your-domain.com` |

### Optional

| Variable | Default | Description |
|----------|---------|-------------|
| `NGINX_PORT` | `80` | Port to expose nginx |
| `SYNC_BATCH_SIZE` | `25` | Items per transaction during sync |
| `SYNC_MEMORY_LIMIT` | `768M` | Memory limit for sync command |
| `SYNC_MEMORY_WARNING_THRESHOLD` | `80` | Log warning at % usage |

### Important Notes

- **APP_SECRET**: Never reuse between environments, keep private
- **DATABASE_URL**: Use full semantic version (e.g., `mariadb-11.4.8`)
- **Actual DB version must be >= configured version**
- **Set `.env` permissions**: `chmod 600 .env`

## Configuration Details

### PHP Settings (`docker/php/php.prod.ini`)

```ini
; OPcache - Maximum performance
opcache.enable=1
opcache.memory_consumption=256M
opcache.validate_timestamps=0  ; Requires container restart for code changes

; APCu - Application cache
apc.enabled=1
apc.shm_size=64M

; Performance
memory_limit=512M
max_execution_time=60
max_input_time=60

; Security
expose_php=Off
allow_url_include=Off
display_errors=Off
log_errors=On
```

**Key Points:**
- OPcache with `validate_timestamps=0` means code changes require container restart
- APCu used for rate limiting and application cache
- Higher memory limit than development (512M vs 256M)

### Nginx Settings (`docker/nginx/production.conf`)

- **Gzip**: Enabled for text/css/js/json
- **Timeouts**: 60s (matches PHP)
- **Client Max Body**: 10M
- **Security**: Server tokens hidden, security headers enabled
- **Caching**: Static assets cached 1 year
- **Buffers**: Optimized for production traffic

### Cache Configuration

**Application Cache** (`config/packages/prod/cache.yaml`):
- Uses APCu (faster than filesystem)
- Rate limiter uses APCu
- System cache uses filesystem

**Multi-Server**: Replace APCu with Redis for distributed caching.

## Console Commands

All commands must run in Docker containers:

### User Management

```bash
docker compose exec php php bin/console app:create-user
docker compose exec php php bin/console app:list-users
```

### Steam Data Sync

```bash
# Download items (chunked)
docker compose exec php php bin/console app:steam:download-items

# Sync to database (cron-optimized)
docker compose exec php php bin/console app:steam:sync-items
```

**Recommendation**: Run weekly or when new CS2 items released.

### Database Operations

```bash
# Check migration status
docker compose exec php php bin/console doctrine:migrations:status

# Run migrations
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Test connection
docker compose exec php php bin/console doctrine:query:sql "SELECT 1"
```

### Cache Operations

```bash
# Clear production cache
docker compose exec php php bin/console cache:clear --env=prod

# Warm cache
docker compose exec php php bin/console cache:warmup --env=prod

# Clear specific pool
docker compose exec php php bin/console cache:pool:clear cache.rate_limiter
```

## SSL/HTTPS Configuration

SSL termination handled externally:

- Nginx listens on HTTP (port 80) only
- CloudFlare/AWS ALB/reverse proxy handles HTTPS
- Configure trusted proxies in `config/packages/framework.yaml`:

```yaml
framework:
    trusted_proxies: '127.0.0.1,REMOTE_ADDR'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port']
```

## Security

### Features

- **Login Rate Limiting**: 3 attempts per 5 minutes per IP
- **CSRF Protection**: Enabled globally
- **Password Hashing**: Symfony 'auto' algorithm (bcrypt/argon2)
- **SQL Injection Prevention**: Parameterized queries via Doctrine
- **XSS Prevention**: Twig auto-escaping
- **Access Control**: Routes require `ROLE_USER`
- **Session Security**: Secure cookies, SameSite=Lax

### Security Checklist

- [ ] Strong `APP_SECRET` generated
- [ ] Strong database password
- [ ] `.env` permissions: `chmod 600 .env`
- [ ] Never commit `.env` with real secrets
- [ ] HTTPS enabled via external proxy
- [ ] Firewall configured (only necessary ports)
- [ ] Automated database backups
- [ ] Monitor logs regularly
- [ ] Keep Docker images updated
- [ ] Regular system updates: `apt update && apt upgrade`

## Maintenance

## Cron Job Configuration

### Setting Up Cron Jobs

**Open crontab editor:**
```bash
crontab -e
```

**Add the cron entries** (adjust path and schedule as needed):
```bash
# CS2 Inventory - Download Steam items weekly (Sundays at 3 AM)
0 3 * * 0 cd /home/user/cs2inventory && docker compose exec -T php php bin/console app:steam:download-items >> /dev/null 2>&1

# CS2 Inventory - Sync downloaded items every 2 minutes
*/2 * * * * cd /home/user/cs2inventory && docker compose exec -T php php bin/console app:steam:sync-items >> /dev/null 2>&1
```

**Notes:**
- Use `-T` flag with `docker compose exec` for cron compatibility
- Console output redirected to `/dev/null` - logs go to dedicated files
- `app:steam:sync-items` exits silently if no files to process
- Adjust paths to match your deployment location

## Command Logs

Each command writes to its own log file in `var/log/`:

| Command | Log File |
|---------|----------|
| `app:steam:download-items` | `var/log/cron-steam-download.log` |
| `app:steam:sync-items` | `var/log/cron-steam-sync.log` |

**Location:** `./var/log/` (relative to project root)

### Log Format

All logs are JSON-formatted, one entry per line.

**Download log example:**
```json
{
  "message": "Download completed successfully",
  "context": {
    "total_items": 26127,
    "total_chunks": 5,
    "duration_seconds": 12.34,
    "memory_peak": "245MB"
  },
  "level_name": "INFO",
  "datetime": "2025-01-15T10:30:45+00:00"
}
```

**Sync log example:**
```json
{
  "message": "Chunk 2/5 completed",
  "context": {
    "file": "items-chunk-2025-01-15-120000-002-of-005.json",
    "chunk_stats": {"added": 145, "updated": 5234, "skipped": 121},
    "total_processed": 11000,
    "memory_peak": "345MB"
  },
  "level_name": "INFO",
  "datetime": "2025-01-15T10:32:15+00:00"
}
```

**View logs:**
```bash
# View recent entries
tail -n 50 var/log/cron-steam-download.log
tail -n 50 var/log/cron-steam-sync.log

# Follow in real-time
tail -f var/log/cron-steam-sync.log
```

### Database Backups

**Manual Backup:**
```bash
mysqldump -h [db-host] -u [db-user] -p cs2inventory > backup-$(date +%Y%m%d-%H%M%S).sql
```

**Restore:**
```bash
mysql -h [db-host] -u [db-user] -p cs2inventory < backup-20250106-120000.sql
```

**Automated**: Set up cron job or use hosting provider's backup features.

### Monitoring

**View Logs:**
```bash
# All containers
docker compose logs -f

# Specific service
docker compose logs -f php
docker compose logs -f nginx

# Last 100 lines
docker compose logs --tail=100 php
```

**Check Status:**
```bash
docker compose ps
docker compose top
```

**Monitor Resources:**
```bash
docker stats
df -h
docker system df
```

## Troubleshooting

### Database Connection Errors

```bash
# Test connection
docker compose exec php php bin/console dbal:run-sql "SELECT 1"

# Check from container
docker compose exec php mysql -h [db-host] -u [db-user] -p

# Check network
docker compose exec php ping [db-host]

# Verify DATABASE_URL
cat .env | grep DATABASE_URL
```

**Common Issues:**
- Incorrect credentials in DATABASE_URL
- Firewall blocking connection
- Database server not accessible
- Wrong serverVersion in DATABASE_URL

### OPcache Issues

**Check Status:**
```bash
docker compose exec php php -i | grep opcache
docker compose exec php php -r "print_r(opcache_get_status());"
```

**Code Changes Not Reflected:**

With `opcache.validate_timestamps=0`, restart required:
```bash
docker compose restart php nginx
```

### Permission Issues

```bash
# Fix var/ permissions
docker compose exec php chown -R appuser:appuser var/
docker compose exec php chmod -R 775 var/
```

### Migration Failures

```bash
# Check status
docker compose exec php php bin/console doctrine:migrations:status

# Verbose output
docker compose exec php php bin/console doctrine:migrations:migrate -vvv

# Rollback
docker compose exec php php bin/console doctrine:migrations:migrate prev
```

### Login Rate Limiting

**Symptoms**: "Too many failed login attempts" even with correct password.

**Solutions:**
```bash
# Wait 5 minutes for automatic reset

# Or clear rate limiter cache
docker compose exec php php bin/console cache:pool:clear cache.rate_limiter
```

**Adjust Limits**: Edit `config/packages/framework.yaml`:
```yaml
framework:
    rate_limiter:
        login:
            limit: 3              # Change attempts
            interval: '5 minutes' # Change window
```

### Container Issues

**Container Won't Start:**
```bash
docker compose logs -f
docker compose ps
docker compose down
docker compose build --no-cache
docker compose up -d
```

**High Memory Usage:**
- Check Steam sync configuration (SYNC_BATCH_SIZE, SYNC_MEMORY_LIMIT)
- Monitor with `docker stats`
- Review logs for memory warnings

## Performance Optimization

### OPcache

- Already optimized with `validate_timestamps=0`
- 256M memory allocation
- Preloading disabled by default

### APCu

- 64M shared memory
- Used for app cache and rate limiter
- Single-server only (use Redis for multi-server)

### Database

- Ensure indexes are optimized
- Monitor slow query log
- Use connection pooling if needed

### Static Assets

- Nginx caches for 1 year
- Gzip compression enabled
- Assets versioned via Webpack Encore

## Docker Configuration Files

- `compose.yml` - Base service definitions
- `compose.prod.yml` - Production overrides
- `compose.override.yml` - Auto-generated (gitignored)
- `docker/php/php.prod.ini` - PHP production settings
- `docker/nginx/production.conf` - Nginx production config
- `.dockerignore` - Excludes dev files from image

## Additional Resources

- **[deploy/README.md](deploy/README.md)** - Deployment process and workflows
- **[CLAUDE.md](CLAUDE.md)** - Development guide and architecture
- **[README.md](README.md)** - Project overview and development setup
