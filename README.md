# CS2 Inventory Management System

A web application for tracking, managing, and valuing CS2 (Counter-Strike 2) inventory items.

## Quick Start - Development

### 1. Clone and Install Dependencies

```bash
git clone <repository-url>
cd cs2inventory
composer install
npm install
```

### 2. Configure Docker Compose for Development

Option A - Set environment variable (recommended):
```bash
# Add to your shell profile (~/.bashrc, ~/.zshrc, etc.)
echo 'export COMPOSE_FILE=compose.yml:compose.dev.yml' >> ~/.bashrc
source ~/.bashrc

# Or for the current session only
export COMPOSE_FILE=compose.yml:compose.dev.yml
```

Option B - Use the helper file:
```bash
source .env.compose
```

Option C - Use `-f` flags each time:
```bash
docker compose -f compose.yml -f compose.dev.yml up
```

### 3. Start Development Environment

```bash
# With COMPOSE_FILE set:
docker compose up -d

# Without COMPOSE_FILE:
docker compose -f compose.yml -f compose.dev.yml up -d
```

### 4. Setup Database

```bash
docker compose exec php php bin/console doctrine:database:create
docker compose exec php php bin/console doctrine:migrations:migrate
```

### 5. Build Frontend Assets

```bash
npm run build
# Or for development with watch:
npm run watch
```

### 6. Access Application

Open http://localhost in your browser.

## Documentation

- **[CLAUDE.md](CLAUDE.md)** - Complete technical documentation
- **[deploy/README.md](deploy/README.md)** - Production deployment guide

## Docker Compose Files

- `compose.yml` - Base service definitions
- `compose.dev.yml` - Development overrides (MySQL, dev builds)
- `compose.prod.yml` - Production overrides (external DB, prod builds)
- `compose.override.yml` - Generated (gitignored), copied from prod/dev

## Common Commands

```bash
# Start services
docker compose up -d

# View logs
docker compose logs -f

# Stop services
docker compose down

# Rebuild after code changes
docker compose build
docker compose up -d

# Run console commands
docker compose exec php php bin/console <command>

# Access PHP container
docker compose exec php bash
```

## Technology Stack

- **Backend**: PHP 8.4 + Symfony 7+
- **Frontend**: Tailwind CSS
- **Database**: MySQL 8.0
- **Containerization**: Docker + Docker Compose
- **API Integration**: SteamWebAPI.com

## License

All rights reserved.
