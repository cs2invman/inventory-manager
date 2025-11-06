# CS2 Inventory Management System

A web application for tracking, managing, and valuing CS2 (Counter-Strike 2) inventory items. Import your Steam inventory, track item details including float values, stickers, and keychains, view real-time market prices, and organize items in virtual storage boxes.

![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-7+-000000?logo=symfony&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-11.x-003545?logo=mariadb&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Enabled-2496ED?logo=docker&logoColor=white)

## Features

- **Steam Inventory Import**: Import CS2 inventory from Steam JSON exports
- **Item Price Tracking**: Real-time market prices with historical tracking
- **Storage Box Management**: Organize items in virtual containers (Steam-imported or manual)
- **Detailed Attributes**: Track float values, wear categories, paint seeds, StatTrak counters
- **Sticker & Keychain Support**: Full support with individual pricing
- **Market Value Calculations**: Automatic total value calculation
- **Profit/Loss Tracking**: Monitor acquired prices and profit/loss
- **Secure Authentication**: Email/password with rate limiting (3 attempts per 5 minutes)

## Technology Stack

- **Backend**: PHP 8.4 + Symfony 7+
- **Frontend**: Tailwind CSS
- **Database**: MariaDB 11.x with Doctrine ORM
- **Container**: Docker + Docker Compose
- **API**: SteamWebAPI.com for CS2 data
- **Web Server**: Nginx (production) / Symfony CLI (development)

## Prerequisites

### Development

- **Docker** (20.10+) and **Docker Compose** (2.0+)
- **Git**
- Basic command line knowledge

**Note**: All development tools (PHP, Composer, Node.js, MariaDB) run in Docker containers. No need to install on host machine.

### Production

See [deploy/README.md](deploy/README.md) for production deployment requirements.

## Development Setup

### 1. Clone Repository

```bash
git clone <repository-url>
cd cs2inventory
```

### 2. Configure Environment

```bash
cp .env.example .env
nano .env  # Edit DATABASE_URL, STEAM_WEB_API_KEY, etc.
```

### 3. Configure Docker Compose

Choose one option:

**Option A - Environment variable (recommended):**
```bash
echo 'export COMPOSE_FILE=compose.yml:compose.dev.yml' >> ~/.bashrc
source ~/.bashrc
```

**Option B - Helper file:**
```bash
source .env.compose
```

**Option C - Use `-f` flags each time:**
```bash
docker compose -f compose.yml -f compose.dev.yml [command]
```

### 4. Install Dependencies

```bash
docker compose run --rm php composer install
docker compose run --rm node npm install
```

### 5. Start Development Environment

```bash
docker compose up -d
docker compose logs -f  # Optional: view logs
```

### 6. Setup Database

```bash
docker compose exec php php bin/console doctrine:database:create
docker compose exec php php bin/console doctrine:migrations:migrate
```

### 7. Build Frontend Assets

```bash
docker compose run --rm node npm run build

# Or watch during development
docker compose run --rm node npm run watch
```

### 8. Create First User

```bash
docker compose exec php php bin/console app:create-user
```

### 9. Access Application

Open browser to: `http://localhost`

### 10. Import Steam Items (Optional)

```bash
docker compose exec php php bin/console app:steam:download-items
docker compose exec php php bin/console app:steam:sync-items
```

This may take several minutes.

## Common Commands

### Development

```bash
# Start services
docker compose up -d

# Stop services
docker compose down

# View logs
docker compose logs -f

# Create user
docker compose exec php php bin/console app:create-user

# List users
docker compose exec php php bin/console app:list-users

# Database migrations
docker compose exec php php bin/console make:migration
docker compose exec php php bin/console doctrine:migrations:migrate

# Clear cache
docker compose exec php php bin/console cache:clear
```

### Frontend Assets

```bash
# Install dependencies
docker compose run --rm node npm install

# Build for production
docker compose run --rm node npm run build

# Watch for changes
docker compose run --rm node npm run watch
```

## Configuration

### Environment Variables

Key development variables in `.env`:

| Variable | Description | Example |
|----------|-------------|---------|
| `APP_ENV` | Environment | `dev` |
| `APP_SECRET` | Symfony secret | Generate with `openssl rand -hex 32` |
| `DATABASE_URL` | Database connection | `mysql://app:password@mysql:3306/app?serverVersion=mariadb-11.4.8` |
| `STEAM_WEB_API_KEY` | Steam API key | Get from SteamWebAPI.com |

For production configuration, see [PRODUCTION.md](PRODUCTION.md).

## Troubleshooting

### Containers Won't Start

```bash
docker compose logs -f php
docker compose logs -f nginx
docker compose ps
```

### Database Connection Failed

```bash
# Verify DATABASE_URL in .env
cat .env | grep DATABASE_URL

# Test connection
docker compose exec php php bin/console dbal:run-sql "SELECT 1"
```

### Assets Not Loading

```bash
# Rebuild assets
docker compose run --rm node npm run build

# Clear cache
docker compose exec php php bin/console cache:clear
```

### Permission Errors

```bash
docker compose exec php chown -R appuser:appuser var/
docker compose exec php chmod -R 775 var/
```

## Contributing

Contributions welcome! Quick guide:

1. **Fork** the repository
2. **Create branch**: `git checkout -b feature/your-feature`
3. **Make changes** following project conventions (see [CLAUDE.md](CLAUDE.md))
4. **Test locally**: `docker compose up -d`
5. **Commit**: `git commit -m "Brief description"`
6. **Push**: `git push origin feature/your-feature`
7. **Submit Pull Request**

### Development Guidelines

- **Docker-Only**: NEVER run PHP, Composer, npm, or database commands directly on host
- **Security**: Follow OWASP best practices, validate input, use parameterized queries
- **Testing**: Test thoroughly in development before submitting
- **Documentation**: Update CLAUDE.md for architecture or major feature changes

## Documentation

- **[CLAUDE.md](CLAUDE.md)** - Development guide: architecture, entities, services, workflows
- **[PRODUCTION.md](PRODUCTION.md)** - Production environment configuration and operations
- **[deploy/README.md](deploy/README.md)** - Deployment process and workflows
- **[tasks/](tasks/)** - Project task and feature documentation

## Production Deployment

For production deployment instructions, see:
- **[deploy/README.md](deploy/README.md)** - Initial setup and deployment workflow
- **[PRODUCTION.md](PRODUCTION.md)** - Production configuration and operations

Quick overview:
1. Run `deploy/setup.sh` on Ubuntu 22.04 LTS server
2. Configure external MariaDB database
3. Build assets locally, commit to git
4. Run `deploy/update.sh -f` on server

See deployment docs for detailed instructions.

## License

MIT License - See LICENSE file for details.
