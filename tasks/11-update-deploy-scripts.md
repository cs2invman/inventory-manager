# Update Deploy Scripts for Production

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-05

## Overview

Update the deployment scripts (`deploy/setup.sh` and `deploy/update.sh`) to support production deployment with external MySQL database, asset pre-building, and proper production workflow.

## Problem Statement

Current deployment scripts are based on a previous project and need updating for CS2 Inventory:
- `setup.sh`: Basic server setup needs updating for current Docker Compose version and CS2 Inventory needs
- `update.sh`: References `compose.override.yml` and `compose.prod.yml` pattern that needs to be implemented
- Scripts need to handle external MySQL database (no containerized MySQL)
- Asset building workflow needs to be integrated (build locally before deployment)
- Update script assumes certain repository structure that should match CS2 Inventory

## Requirements

### Functional Requirements
- `setup.sh`: Install Docker, Docker Compose, and basic dependencies on Ubuntu 22.04
- `update.sh`: Pull latest code, copy production compose file, build/restart containers, run migrations
- Scripts should verify external database connectivity before deployment
- Update script should handle asset building workflow (assets built locally before push)
- Both scripts should include proper error handling and rollback capability
- Scripts should log deployment actions with timestamps

### Non-Functional Requirements
- Scripts must be idempotent (safe to run multiple times)
- Clear error messages when things go wrong
- Minimal downtime during updates
- Automatic migrations after code update

## Technical Approach

### setup.sh Updates
- Update Docker Compose installation to v2.29+ (current stable)
- Add APCu installation for PHP (for rate limiter cache)
- Add Git configuration for deployment user
- Create application directory structure
- Setup environment file template

### update.sh Updates
- Update to match CS2 Inventory structure
- Implement production compose file pattern
- Add pre-deployment checks (database connectivity, disk space)
- Add asset verification (built files must exist)
- Improve rollback mechanism
- Add deployment logging

### Deployment Workflow
1. Local: Build assets (`npm run build`)
2. Local: Commit built assets to git
3. Local: Push to repository
4. Server: Run `update.sh -f`
5. Server: Script pulls code, verifies assets, deploys
6. Server: Run migrations automatically
7. Server: Restart containers

## Implementation Steps

### Update setup.sh

1. **Update Header Comments**
   - Document what the script installs
   - Add version requirements
   - Document usage

2. **Update Docker Compose Installation**
   - Current script uses v2.2.3 (outdated)
   - Update to latest stable version (v2.29+)
   - Or use Docker's apt repository method for automatic updates:
     ```bash
     apt install docker-compose-plugin
     ```

3. **Add Additional Dependencies**
   - Install git (already present)
   - Add build-essential (if needed)
   - Add any monitoring tools (optional)

4. **Create Application Directory Structure**
   - Create `/home/gil/cs2inventory` directory
   - Create `.env.prod` template
   - Set proper permissions

5. **Add Environment Setup**
   - Create `.env.prod.template` with placeholders
   - Instructions for admin to fill in DATABASE_URL, APP_SECRET, etc.

6. **Document Post-Setup Steps**
   - Echo instructions for:
     - Setting up deploy SSH key
     - Configuring .env.prod
     - Initial repository clone
     - First-time database setup

### Update update.sh

1. **Update Script Variables**
   - Change USER from "gil" to "gil" (already correct)
   - Update REPO_BRANCH to "master" (already correct)
   - Add new variables:
     ```bash
     readonly PROJECT_NAME="cs2inventory"
     readonly BACKUP_DIR="${REPO_PATH}/backups"
     ```

2. **Add Pre-Deployment Checks**
   - Check if .env.prod exists
   - Verify database connectivity:
     ```bash
     docker compose -f docker-compose.yml -f compose.prod.yml run --rm php php bin/console doctrine:query:sql "SELECT 1"
     ```
   - Check disk space
   - Verify built assets exist in `public/build/`

3. **Update Git Pull Section**
   - Keep existing git pull logic
   - Add verification of current branch
   - Add check for built assets after pull

4. **Update Docker Compose Section**
   - Update compose file logic:
     ```bash
     # Copy production compose as override
     yes | cp -f compose.{prod,override}.yml
     ```
   - Use production build:
     ```bash
     DOCKER_BUILDKIT=1 docker compose build --pull --no-cache
     docker compose up --remove-orphans -d
     ```

5. **Add Database Migration Section**
   - Already present: `docker compose exec php php bin/console doctrine:migrations:migrate --allow-no-migration --no-interaction`
   - Verify it works with external database

6. **Add Cache Warming**
   - After migration, warm up production cache:
     ```bash
     docker compose exec php php bin/console cache:clear --env=prod
     docker compose exec php php bin/console cache:warmup --env=prod
     ```

7. **Add Backup Mechanism**
   - Before deployment, create backup of current code:
     ```bash
     tar -czf "${BACKUP_DIR}/backup-$(date +%Y%m%d-%H%M%S).tar.gz" --exclude=var --exclude=.git .
     ```
   - Keep last 5 backups only

8. **Add Rollback Instructions**
   - Add commented section showing how to rollback:
     ```bash
     # To rollback:
     # git reset --hard <previous-commit>
     # docker compose build --pull --no-cache
     # docker compose up -d
     ```

9. **Improve Logging**
   - Add deployment log file:
     ```bash
     LOG_FILE="${REPO_PATH}/var/log/deploy.log"
     echo "[$(date)] Starting deployment..." >> "${LOG_FILE}"
     ```
   - Log each major step

10. **Add Health Check**
    - After deployment, verify app is running:
      ```bash
      sleep 5
      curl -f http://localhost/ > /dev/null || echo "WARNING: Health check failed"
      ```

11. **Update Timing Output**
    - Keep existing timing sections
    - Add total deployment time

### Update deploy/README.md

1. **Update Documentation**
   - Update for CS2 Inventory project
   - Document external MySQL requirement
   - Document asset building workflow
   - Add troubleshooting section

2. **Add Prerequisites Section**
   - Ubuntu 22.04 server
   - External MySQL 8.0 database
   - SSH access with deploy key
   - Domain/subdomain pointing to server (if needed)

3. **Add Initial Setup Instructions**
   - Running setup.sh
   - Configuring .env.prod
   - Setting up deploy SSH key
   - Initial git clone
   - First deployment

4. **Add Update Workflow**
   - Local: Build assets
   - Local: Commit and push
   - Server: Run update.sh -f
   - Server: Verify deployment

5. **Add Troubleshooting Guide**
   - Database connection issues
   - Asset building issues
   - Container startup failures
   - Rollback procedures

## Edge Cases & Error Handling

- **Git pull conflicts**: Script does `git clean -fd` and `git checkout -f` to force clean state
- **Database connection fails**: Pre-deployment check should catch this
- **Migration fails**: Script should exit, container won't restart with broken migration
- **Disk space full**: Pre-deployment check should warn
- **Built assets missing**: Pre-deployment check should catch this
- **Container fails to start**: Health check should warn (but not fail deployment)

## Dependencies

- Task 10: Production Docker Configuration (must complete first)
- External MySQL database must be provisioned and accessible
- Assets must be built locally before each deployment

## Acceptance Criteria

- [ ] setup.sh updated with current Docker Compose installation method
- [ ] setup.sh creates proper directory structure for application
- [ ] update.sh updated with CS2 Inventory project structure
- [ ] update.sh includes pre-deployment checks (database, disk, assets)
- [ ] update.sh copies compose.prod.yml as compose.override.yml
- [ ] update.sh runs migrations automatically
- [ ] update.sh includes cache warming after deployment
- [ ] update.sh creates backups before deployment
- [ ] update.sh includes deployment logging
- [ ] update.sh includes basic health check after deployment
- [ ] deploy/README.md updated with current instructions
- [ ] Scripts tested in staging/test environment
- [ ] Rollback procedure documented and tested

## Notes & Considerations

- **Asset Building Workflow**:
  - Recommended: Build locally, commit built files
  - Why: Production server doesn't need Node.js installed
  - Tradeoff: Larger git repository, but simpler deployment

- **compose.override.yml Pattern**:
  - update.sh copies `compose.prod.yml` to `compose.override.yml`
  - Docker Compose automatically merges docker-compose.yml + compose.override.yml
  - This allows keeping production-specific config separate

- **Database Migrations**:
  - Migrations run automatically with `--no-interaction` flag
  - Migrations are idempotent (safe to run multiple times)
  - Failed migration will exit script and prevent deployment

- **Zero-Downtime Deployment**:
  - Current approach has brief downtime during container restart
  - For zero-downtime: would need blue-green deployment or rolling updates
  - Not critical for single-user/small-scale application

- **Monitoring**:
  - Consider adding health check endpoint: `/health`
  - Consider adding deployment notifications (email, Slack, etc.)
  - Consider adding application monitoring (Sentry, etc.)

## Related Tasks

- Task 10: Production Docker Configuration (prerequisite)
- Task 12: Production Deployment README (next)
