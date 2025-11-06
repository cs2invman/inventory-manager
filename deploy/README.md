# Deployment Guide

This guide covers deploying CS2 Inventory Management System to production.

**For production environment configuration, see [PRODUCTION.md](../PRODUCTION.md).**

## Prerequisites

### Server Requirements

- **OS**: Ubuntu 22.04 LTS
- **RAM**: Minimum 2GB (4GB recommended)
- **Disk**: Minimum 20GB free
- **Access**: SSH with sudo privileges

### External Services

- **MariaDB 11.x**: Hosted externally, accessible from server
- **Domain**: Optional but recommended
- **SSL**: External SSL termination (CloudFlare, AWS ALB, or reverse proxy)

### Local Requirements

- **Node.js**: v18+ for building assets
- **Git**: Version control
- **SSH Key**: For repository access

## Initial Setup

### 1. Run Setup Script

Copy and run `setup.sh` on fresh Ubuntu 22.04 LTS server:

```bash
# On server as root
sudo -i
cd /tmp
# Copy setup.sh to server (scp, vim, etc.)
chmod +x setup.sh
./setup.sh
```

**Script Actions:**
- Installs Docker and Docker Compose
- Configures firewall (UFW)
- Creates application directory
- Sets up user for Docker access

### 2. Reboot Server

**IMPORTANT**: Reboot for Docker group changes:

```bash
sudo shutdown -r now
```

### 3. Generate Deploy SSH Key

```bash
# After reboot, as non-root user
ssh-keygen -t ed25519 -C "cs2inventory-deploy"
cat ~/.ssh/id_ed25519.pub
```

Add public key to repository's deploy keys (GitHub/GitLab/Bitbucket settings).

### 4. Clone Repository

```bash
cd ~
git clone git@<your-git-host>:<your-repo>.git cs2inventory
cd cs2inventory
```

### 5. Setup External Database

On your MariaDB server:

```sql
CREATE DATABASE cs2inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cs2user'@'%' IDENTIFIED BY 'YourSecurePassword123!';
GRANT ALL PRIVILEGES ON cs2inventory.* TO 'cs2user'@'%';
FLUSH PRIVILEGES;
```

**Security**: Ensure database is accessible from app server only (firewall rules).

### 6. Configure Environment

```bash
cp .env.prod.example .env
nano .env
chmod 600 .env
```

**Required variables:**
- `APP_ENV=prod`
- `APP_SECRET` (generate: `openssl rand -hex 32`)
- `DATABASE_URL` (external MariaDB connection)
- `STEAM_WEB_API_KEY`
- `DEFAULT_URI` (your domain)

**See [PRODUCTION.md](../PRODUCTION.md) for detailed environment configuration.**

## Deployment Workflow

### Build Assets Locally

**IMPORTANT**: Assets must be built before deployment (production server has no Node.js).

```bash
# On your local machine
cd cs2inventory

# Install dependencies
npm install

# Build production assets
npm run build

# Commit built assets
git add public/build
git commit -m "Build assets for deployment"

# Push to repository
git push origin main
```

### Deploy to Server

```bash
# On production server
cd ~/cs2inventory
./deploy/update.sh -f
```

**Script Actions:**
1. Creates backup of current deployment
2. Pulls latest code from git
3. Verifies built assets exist
4. Checks database connectivity
5. Builds and restarts Docker containers
6. Runs database migrations
7. Warms cache
8. Performs health checks

**Flags:**
- `-f` or `--force`: Force full rebuild (recommended for first deployment)
- No flags: Quick update (pulls code, restarts containers)

### Expected Output

```
CS2 Inventory Deployment
========================================

Running pre-deployment checks...
✓ Environment file exists
✓ Disk space check passed (45GB available)
✓ Built assets found

Creating backup...
✓ Backup created: backups/backup-20251105-143022.tar.gz

Updating code...
✓ Code updated successfully

Building and deploying containers...
✓ Containers built and deployed

✓ Database connection successful

Running maintenance tasks...
✓ Database migrations completed
✓ Cache warmed

Performing health check...
✓ Containers are running
✓ Application is responding
✓ PHP is responding

========================================
Deployment Complete!
========================================

Backup:        3s
Git:           8s
Docker:       45s
Maintenance:   7s
─────────────────
Total:        63s
```

### Post-Deployment

```bash
# Create first user
docker compose exec php php bin/console app:create-user

# Sync Steam items
docker compose exec php php bin/console app:steam:download-items
docker compose exec php php bin/console app:steam:sync-items

# Access application
# Navigate to your domain or server IP
```

## Updating Application

### Local Workflow

```bash
# 1. Make code changes
# 2. Build assets
npm run build

# 3. Commit changes (including built assets)
git add .
git commit -m "Your changes"

# 4. Push to repository
git push origin main
```

### Server Workflow

```bash
# 5. Deploy updates
cd ~/cs2inventory
./deploy/update.sh

# 6. Verify
docker compose logs -f
docker compose ps
```

**Note**: With OPcache `validate_timestamps=0`, code changes require container restart (handled by update script).

## Rollback Procedures

### Method 1: Restore from Backup

```bash
cd ~/cs2inventory

# Find backup
ls -lh backups/

# Extract backup (overwrites current files)
tar -xzf backups/backup-20251105-143022.tar.gz

# Setup production override
cp compose.prod.yml compose.override.yml

# Rebuild and restart
docker compose build --no-cache
docker compose up -d
```

### Method 2: Git Reset

```bash
cd ~/cs2inventory

# Find commit to rollback to
git log --oneline -10

# Reset to previous commit
git reset --hard <commit-hash>

# Setup production override
cp compose.prod.yml compose.override.yml

# Rebuild and restart
docker compose build --no-cache
docker compose up -d

# Rollback migrations if needed
docker compose exec php php bin/console doctrine:migrations:migrate prev --no-interaction
```

### Verify Rollback

```bash
docker compose ps
curl http://localhost/
docker compose logs -f
```

## Troubleshooting

### Built Assets Missing

**Symptom**: Warning about missing assets in `public/build/`

**Solution:**
```bash
# On local machine
npm run build
git add public/build
git commit -m "Build assets"
git push

# On server
cd ~/cs2inventory
./deploy/update.sh
```

### Database Connection Failed

**Symptom**: `Database connection failed` during deployment

**Solutions:**
```bash
# Verify DATABASE_URL in .env
cat .env | grep DATABASE_URL

# Test connection
docker compose run --rm php php bin/console dbal:run-sql "SELECT 1"

# Check database accessibility
mysql -h your-db-host -u cs2user -p

# Check firewall allows connection from app server
```

**See [PRODUCTION.md](../PRODUCTION.md) for detailed troubleshooting.**

### Container Startup Failures

**Solutions:**
```bash
# Check logs
docker compose logs -f
docker compose logs php
docker compose logs nginx

# Verify .env exists and configured
cat .env

# Check disk space
df -h

# Rebuild containers
docker compose down
docker compose build --no-cache
docker compose up -d
```

### Migration Failures

**Solutions:**
```bash
# Check error in logs
docker compose logs php

# Verbose migration output
docker compose exec php php bin/console doctrine:migrations:migrate -vvv

# Check database locks
# In MySQL: SHOW FULL PROCESSLIST;

# Rollback if needed
docker compose exec php php bin/console doctrine:migrations:migrate prev
```

### Deployment Script Fails

**Check deployment log:**
```bash
tail -f var/log/deploy.log
```

**Common issues:**
- Missing `.env` file
- Insufficient disk space
- Database not accessible
- Built assets missing
- Permissions issues

## Monitoring

### View Logs

```bash
# All containers
docker compose logs -f

# Specific service
docker compose logs -f php
docker compose logs -f nginx

# Deployment log
tail -f var/log/deploy.log
```

### Check Status

```bash
docker compose ps
docker compose top
docker stats
```

### Health Check

```bash
# Application responding
curl http://localhost/

# PHP responding
docker compose exec php php -v

# Database connection
docker compose exec php php bin/console dbal:run-sql "SELECT 1"
```

## Security Checklist

Before going live:

- [ ] `.env` permissions: `chmod 600 .env`
- [ ] Strong `APP_SECRET` generated
- [ ] Strong database password
- [ ] `STEAM_WEB_API_KEY` kept private
- [ ] Firewall (UFW) enabled and configured
- [ ] SSH key-based authentication
- [ ] Database only accessible from app server
- [ ] HTTPS enabled via external proxy
- [ ] Regular system updates scheduled
- [ ] Database backups automated
- [ ] Logs monitored regularly

## Docker Compose Structure

The project uses flexible Docker Compose configuration:

- **`compose.yml`**: Base service definitions
- **`compose.dev.yml`**: Development overrides (MySQL, dev volumes)
- **`compose.prod.yml`**: Production overrides (external DB, optimized)
- **`compose.override.yml`**: Auto-generated (gitignored)

**Production**: Deployment script copies `compose.prod.yml` to `compose.override.yml`, then `docker compose up` automatically merges base + override.

## Additional Resources

- **[PRODUCTION.md](../PRODUCTION.md)** - Environment configuration, console commands, maintenance
- **[CLAUDE.md](../CLAUDE.md)** - Development guide and architecture
- **[README.md](../README.md)** - Project overview and development setup
- **[compose.prod.yml](../compose.prod.yml)** - Production Docker configuration
- **[.env.prod.example](../.env.prod.example)** - Environment template

## Support

For deployment issues:
1. Check troubleshooting section above
2. Review deployment log: `tail -f var/log/deploy.log`
3. Review container logs: `docker compose logs -f`
4. Check [PRODUCTION.md](../PRODUCTION.md) for environment-specific issues
