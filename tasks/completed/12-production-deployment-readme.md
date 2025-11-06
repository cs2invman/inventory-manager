# Production Deployment README

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-05

## Overview

Create a comprehensive README.md in the project root with deployment instructions, production setup guide, and operational documentation for running CS2 Inventory in production.

## Problem Statement

The project lacks documentation for:
- How to deploy the application to a production server
- Initial server setup steps
- Configuration requirements
- Ongoing maintenance and update procedures
- Troubleshooting common issues

A comprehensive README.md will enable anyone (including future you) to deploy and maintain the application without needing to reverse-engineer the deployment process.

## Requirements

### Functional Requirements
- Document complete server setup process
- Document initial deployment steps
- Document update/maintenance procedures
- Include configuration examples
- Include troubleshooting section
- Reference deploy/ folder scripts

### Non-Functional Requirements
- Clear, step-by-step instructions
- Organized with table of contents
- Code examples that can be copy-pasted
- Includes prerequisites and dependencies
- Links to external documentation where appropriate

## Technical Approach

### README Structure

```markdown
# CS2 Inventory Management System

[Project description]

## Table of Contents
- Features
- Technology Stack
- Prerequisites
- Development Setup
- Production Deployment
- Configuration
- Maintenance
- Troubleshooting
- Contributing

## Features
[List key features]

## Technology Stack
[Backend, frontend, database, etc.]

## Prerequisites
### Development
[Local development prerequisites]

### Production
[Production server prerequisites]

## Development Setup
[Local development instructions]

## Production Deployment
### Initial Server Setup
[First-time server setup]

### External Database Setup
[MySQL database provisioning]

### Application Deployment
[Deploying the application]

### Post-Deployment Configuration
[Configuration after deployment]

## Configuration
### Environment Variables
[All environment variables explained]

### Docker Configuration
[Docker Compose files explained]

## Maintenance
### Updating the Application
[How to deploy updates]

### Database Migrations
[How to run migrations]

### Backup and Restore
[Backup procedures]

## Troubleshooting
[Common issues and solutions]

## Contributing
[How to contribute]
```

## Implementation Steps

1. **Create README.md in Project Root**
   - Create new file or update existing
   - Add project title and description

2. **Write Features Section**
   - List key features from CLAUDE.md:
     - Steam inventory import
     - Item price tracking
     - Storage box management
     - Float values, stickers, keychains
     - Market value calculations

3. **Write Technology Stack Section**
   - Backend: PHP 8.4, Symfony 7+
   - Frontend: Tailwind CSS
   - Database: MySQL 8.0
   - Container: Docker + Docker Compose
   - API: SteamWebAPI.com

4. **Write Prerequisites Section**
   - Development:
     - Docker & Docker Compose
     - Git
     - Node.js 18+ (for asset building)
   - Production:
     - Ubuntu 22.04 server
     - External MySQL 8.0 database
     - SSH access
     - Domain name (optional)
     - SSL certificate (optional, external)

5. **Write Development Setup Section**
   - Clone repository
   - Copy .env file
   - Install dependencies: `docker compose run --rm node npm install`
   - Start containers: `docker compose up -d`
   - Run migrations: `docker compose exec php php bin/console doctrine:migrations:migrate`
   - Create user: `docker compose exec php php bin/console app:create-user`
   - Build assets: `docker compose run --rm node npm run build`
   - Access application: `http://localhost`

6. **Write Production Deployment Section**

   **Initial Server Setup:**
   - Start with Ubuntu 22.04
   - Copy deploy/setup.sh to server
   - Run as root: `sudo ./setup.sh`
   - Reboot server
   - Create deployment SSH key
   - Clone repository as gil user

   **External Database Setup:**
   - Provision MySQL 8.0 server
   - Create database: `CREATE DATABASE cs2inventory;`
   - Create user with permissions
   - Note connection details for .env.prod

   **Application Deployment:**
   - Copy .env.example to .env.prod
   - Configure .env.prod (DATABASE_URL, APP_SECRET, etc.)
   - Build assets locally: `npm run build`
   - Commit built assets
   - Push to repository
   - On server, run: `./deploy/update.sh -f`
   - Verify deployment
   - Create first user: `docker compose exec php php bin/console app:create-user`

7. **Write Configuration Section**

   **Environment Variables:**
   - `APP_ENV`: prod for production
   - `APP_SECRET`: Random secret (generate with `openssl rand -hex 32`)
   - `DATABASE_URL`: MySQL connection to external database
   - `STEAM_WEB_API_KEY`: API key from SteamWebAPI.com
   - `STEAM_WEB_API_BASE_URL`: https://www.steamwebapi.com/steam/api
   - `STEAM_ITEMS_STORAGE_PATH`: var/data/steam-items

   **Docker Configuration:**
   - `docker-compose.yml`: Base configuration for all environments
   - `compose.prod.yml`: Production overrides (external DB, optimizations)
   - `compose.override.yml`: Auto-merged, created by update.sh

8. **Write Maintenance Section**

   **Updating the Application:**
   - Local: Build assets: `npm run build`
   - Local: Commit changes
   - Local: Push to repository
   - Server: Run `./deploy/update.sh -f`
   - Verify deployment

   **Database Migrations:**
   - Migrations run automatically during updates
   - Manual: `docker compose exec php php bin/console doctrine:migrations:migrate`

   **Backup and Restore:**
   - Database backup: `mysqldump -h [host] -u [user] -p cs2inventory > backup.sql`
   - Restore: `mysql -h [host] -u [user] -p cs2inventory < backup.sql`
   - Code backup: Automatically created by update.sh in backups/

   **Syncing Steam Items:**
   - Download: `docker compose exec php php bin/console app:steam:download-items`
   - Sync: `docker compose exec php php bin/console app:steam:sync-items`

   **Creating Users:**
   - `docker compose exec php php bin/console app:create-user`
   - List users: `docker compose exec php php bin/console app:list-users`

9. **Write Troubleshooting Section**

   Common issues:
   - **Container won't start**: Check logs: `docker compose logs -f php`
   - **Database connection failed**: Verify DATABASE_URL, check database is reachable
   - **Migration failed**: Check error in logs, may need manual intervention
   - **Assets not loading**: Verify built assets exist in public/build/
   - **Login rate limiting**: Wait 5 minutes or check cache configuration
   - **Permission errors**: Check file permissions in var/ directory

   Debugging:
   - View logs: `docker compose logs -f [service]`
   - Enter container: `docker compose exec php bash`
   - Check PHP config: `docker compose exec php php -i`
   - Clear cache: `docker compose exec php php bin/console cache:clear`

10. **Write Contributing Section**
    - Fork repository
    - Create feature branch
    - Make changes
    - Test locally
    - Submit pull request

11. **Add Badges and Links**
    - PHP version badge
    - Symfony version badge
    - License badge (if applicable)
    - Link to CLAUDE.md for detailed architecture

12. **Review and Polish**
    - Check all links work
    - Verify all commands are correct
    - Ensure formatting is consistent
    - Proofread for clarity

## Edge Cases & Error Handling

- **Outdated instructions**: Add "Last updated" date at top of README
- **Platform differences**: Note that instructions are for Ubuntu 22.04, may differ on other systems
- **Missing details**: Link to deploy/README.md for more detailed deployment instructions

## Dependencies

- Task 10: Production Docker Configuration (need to know final compose structure)
- Task 11: Update Deploy Scripts (need to document script usage)

## Acceptance Criteria

- [ ] README.md created in project root
- [ ] Table of contents with all sections
- [ ] Features section lists key capabilities
- [ ] Technology stack documented
- [ ] Development setup instructions complete and tested
- [ ] Production deployment instructions complete
- [ ] External database setup documented
- [ ] Environment variables all documented
- [ ] Maintenance procedures documented
- [ ] Troubleshooting section includes common issues
- [ ] All code examples are correct and can be copy-pasted
- [ ] Links to deploy/ folder scripts
- [ ] Formatted with proper markdown
- [ ] Reviewed for accuracy and clarity

## Notes & Considerations

- **Audience**: Write for someone who has basic Linux/Docker knowledge but hasn't seen this project before
- **Depth**: Balance between comprehensive and concise
- **Examples**: Use real examples from the project, not generic placeholders
- **Links**: Link to CLAUDE.md for architecture details (avoid duplicating that content)
- **Maintenance**: Keep README in sync with actual deployment process

- **Structure**:
  - Start with quick overview
  - Development setup early (most common use case)
  - Production deployment later (less common)
  - Troubleshooting at end (reference material)

- **Future Sections** (consider adding):
  - API documentation (if any)
  - Monitoring and alerting setup
  - Performance tuning
  - Security best practices
  - Scaling considerations

## Related Tasks

- Task 10: Production Docker Configuration (prerequisite)
- Task 11: Update Deploy Scripts (prerequisite)
