# Production Deployment Guide

This guide covers deploying the CS2 Inventory Management System to a production server.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Initial Server Setup](#initial-server-setup)
- [Repository Setup](#repository-setup)
- [Configuration](#configuration)
- [Deployment Workflow](#deployment-workflow)
- [Troubleshooting](#troubleshooting)
- [Rollback Procedure](#rollback-procedure)

## Prerequisites

### Server Requirements

- **OS**: Ubuntu 22.04 LTS
- **RAM**: Minimum 2GB (4GB recommended)
- **Disk**: Minimum 20GB free space
- **Network**: Public IP or domain pointing to server
- **Access**: SSH access with sudo privileges

### External Services

- **MySQL Database**: MySQL 8.0 hosted externally
  - Database created: `cs2inventory`
  - User with full permissions
  - Accessible from your server
- **Domain**: Domain or subdomain configured (e.g., cs2invman.gms.tools)
- **SSL**: CloudFlare, AWS ALB, or other external SSL termination

### Local Development

- **Node.js**: v18+ (for building frontend assets)
- **Git**: For version control
- **SSH Key**: Deploy key for repository access

## Initial Server Setup

### 1. Run Setup Script

Copy the `setup.sh` script to your server and run it as root:

```bash
# On your server
sudo -i
cd /tmp
# Copy setup.sh to the server (using scp, vim, or other method)
chmod +x setup.sh
./setup.sh
```

The setup script will:
- Install Docker Engine and Docker Compose
- Configure firewall (UFW)
- Create application directory structure
- Set up the ubuntu user for Docker access

### 2. Reboot Server

**IMPORTANT**: After running setup.sh, reboot the server for Docker group changes to take effect:

```bash
sudo shutdown -r now
```

### 3. Set Up Deploy SSH Key

After reboot, generate an SSH key for deploying from the repository:

```bash
# As ubuntu user
ssh-keygen -t ed25519 -C "cs2inventory-deploy"

# Display the public key
cat ~/.ssh/id_ed25519.pub
```

Add this public key to your git repository's deploy keys:
- GitHub: Settings → Deploy Keys → Add deploy key
- GitLab: Settings → Repository → Deploy Keys
- Bitbucket: Repository Settings → Access keys

### 4. Clone Repository

```bash
# As ubuntu user
cd ~
git clone git@<your-git-host>:<your-repo>.git cs2inventory
cd cs2inventory
```

## Repository Setup

### External MySQL Database

Set up your MySQL database:

```sql
-- Connect to your MySQL server
mysql -u root -p

-- Create database
CREATE DATABASE cs2inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user (replace with secure password)
CREATE USER 'cs2user'@'%' IDENTIFIED BY 'YourSecurePassword123!';

-- Grant permissions
GRANT ALL PRIVILEGES ON cs2inventory.* TO 'cs2user'@'%';
FLUSH PRIVILEGES;
```

**Security Note**: Ensure your MySQL server:
- Is accessible from your application server
- Has proper firewall rules (only allow your app server IP)
- Uses strong passwords
- Has SSL enabled (recommended)

## Configuration

### 1. Create Production Environment File

```bash
cd ~/cs2inventory
cp .env.prod.example .env
nano .env
```

### 2. Configure Required Variables

Edit `.env` with your production values:

```bash
# Application
APP_ENV=prod
APP_SECRET=<generate-with: openssl rand -hex 32>

# Database (External MySQL)
DATABASE_URL="mysql://cs2user:YourSecurePassword123!@your-db-host:3306/cs2inventory?serverVersion=8.0&charset=utf8mb4"

# Steam API
STEAM_WEB_API_KEY=your_actual_steam_api_key

# Application URL
DEFAULT_URI=https://cs2invman.gms.tools

# Other settings (usually defaults are fine)
STEAM_WEB_API_BASE_URL=https://www.steamwebapi.com/steam/api
STEAM_ITEMS_STORAGE_PATH=var/data/steam-items
LOCK_DSN=flock
NGINX_PORT=80
```

### 3. Secure Environment File

```bash
chmod 600 .env
```

## Deployment Workflow

### Local Development Workflow

Before deploying, you must build frontend assets locally:

```bash
# On your local machine
cd cs2inventory

# Install dependencies (if not already installed)
npm install

# Build production assets
npm run build

# Commit built assets
git add public/build
git commit -m "Build assets for deployment"

# Push to repository
git push origin master
```

**Why build locally?**
- Production server doesn't need Node.js installed
- Faster deployments (no npm install/build on server)
- Consistent builds across environments

### Deploy to Production

Once assets are built and pushed, deploy on the server:

```bash
# On production server
cd ~/cs2inventory
./deploy/update.sh -f
```

The update script will:
1. Create a backup of current deployment
2. Pull latest code from git
3. Verify built assets exist
4. Check database connectivity
5. Build and restart Docker containers
6. Run database migrations
7. Warm up cache
8. Perform health checks

### What Happens During Deployment

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

## Troubleshooting

### Database Connection Errors

**Symptom**: `Database connection failed` during deployment

**Solutions**:
1. Verify DATABASE_URL in `.env` is correct
2. Test connection manually:
   ```bash
   docker compose -f compose.prod.yml run --rm php php bin/console dbal:run-sql "SELECT 1"
   ```
3. Check MySQL server is accessible:
   ```bash
   mysql -h your-db-host -u cs2user -p
   ```
4. Verify firewall allows connection from app server
5. Check MySQL user has proper permissions

### Built Assets Missing

**Symptom**: Warning about missing assets in `public/build/`

**Solutions**:
1. Build assets locally:
   ```bash
   npm run build
   ```
2. Commit and push:
   ```bash
   git add public/build
   git commit -m "Build assets"
   git push
   ```
3. Re-run deployment

### Container Startup Failures

**Symptom**: Containers fail to start or crash immediately

**Solutions**:
1. Check container logs:
   ```bash
   docker compose logs -f
   docker compose logs php
   docker compose logs nginx
   ```
2. Verify `.env` file exists and is properly configured
3. Check disk space: `df -h`
4. Verify Docker is running: `docker ps`

### Permission Issues

**Symptom**: Permission denied errors in logs

**Solutions**:
```bash
cd ~/cs2inventory
docker compose exec php chown -R appuser:appuser var/
docker compose exec php chmod -R 775 var/
```

### OPcache Not Reflecting Changes

**Symptom**: Code changes not visible after deployment

**Solution**: With `opcache.validate_timestamps=0`, you must restart containers:
```bash
docker compose restart php nginx
```

### Migration Failures

**Symptom**: Database migration fails during deployment

**Solutions**:
1. Check migration error in logs
2. Manually run migrations with verbose output:
   ```bash
   docker compose exec php php bin/console doctrine:migrations:migrate -vvv
   ```
3. If migration is stuck, check database locks:
   ```sql
   SHOW FULL PROCESSLIST;
   ```
4. Roll back migration if needed:
   ```bash
   docker compose exec php php bin/console doctrine:migrations:migrate prev
   ```

## Rollback Procedure

If a deployment causes issues, you can rollback:

### Method 1: Restore from Backup

```bash
cd ~/cs2inventory

# Find the backup
ls -lh backups/

# Extract the backup (this will overwrite current files)
tar -xzf backups/backup-20251105-143022.tar.gz

# Rebuild and restart
docker compose build --no-cache
docker compose up -d
```

### Method 2: Git Reset

```bash
cd ~/cs2inventory

# Find the commit to rollback to
git log --oneline -10

# Reset to previous commit
git reset --hard <commit-hash>

# Rebuild and restart
docker compose build --no-cache
docker compose up -d

# Run migrations down if needed
docker compose exec php php bin/console doctrine:migrations:migrate prev --no-interaction
```

### Verify Rollback

After rollback:
1. Check containers are running: `docker compose ps`
2. Check application: `curl http://localhost/`
3. Check logs: `docker compose logs -f`

## Monitoring and Maintenance

### View Logs

```bash
# All containers
docker compose logs -f

# Specific service
docker compose logs -f php
docker compose logs -f nginx

# Last 100 lines
docker compose logs --tail=100 php

# Deployment log
tail -f var/log/deploy.log
```

### Check Container Status

```bash
docker compose ps
docker compose top
```

### Monitor Resources

```bash
# CPU and memory usage
docker stats

# Disk usage
df -h
docker system df
```

### Database Backups

Set up automated database backups (recommended):

```bash
# Example backup script
mysqldump -h your-db-host -u cs2user -p cs2inventory > backup-$(date +%Y%m%d).sql
```

Consider:
- Daily automated backups
- Off-site backup storage
- Regular backup testing

### Update Docker Images

Periodically update Docker images:

```bash
cd ~/cs2inventory
docker compose pull
docker compose up -d
```

## Security Checklist

- [ ] `.env` file has chmod 600 permissions
- [ ] Strong APP_SECRET generated
- [ ] Strong database password used
- [ ] STEAM_WEB_API_KEY kept private
- [ ] Firewall (UFW) enabled and configured
- [ ] SSH key-based authentication enabled
- [ ] MySQL only accessible from app server
- [ ] SSL/HTTPS enabled via CloudFlare or reverse proxy
- [ ] Regular security updates: `apt update && apt upgrade`
- [ ] Database backups automated
- [ ] Logs monitored regularly

## Additional Resources

- [CLAUDE.md](../CLAUDE.md) - Full production deployment documentation
- [Task 10](../tasks/completed/10-production-docker-configuration.md) - Production Docker configuration details
- [compose.prod.yml](../compose.prod.yml) - Production Docker Compose configuration
- [.env.prod.example](../.env.prod.example) - Environment variable template

## Support

For issues or questions:
1. Check the troubleshooting section above
2. Review container logs: `docker compose logs -f`
3. Check deployment log: `tail -f var/log/deploy.log`
4. Review the CLAUDE.md file for detailed configuration info
