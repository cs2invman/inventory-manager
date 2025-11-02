# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CS2 Steam Marketplace Platform - A real-time monitoring system that tracks CS2 item prices from Steam marketplace, stores historical data, and sends Discord notifications for price changes and game updates.

## Technology Stack

- **Backend**: PHP 8.4 + Symfony 7+
- **Frontend**: Tailwind CSS for styling
- **Database**: MySQL with Doctrine ORM
- **Queue System**: Symfony Messenger for async processing
- **Containerization**: Docker + Docker Compose for development and deployment
- **Scheduling**: Cron jobs via Symfony Console commands

## Core Architecture

## Development Commands

### Docker Development Setup
```bash
# Start all services (PHP, MySQL, web server)
docker compose up -d

# Stop all services
docker compose down

# View logs
docker compose logs -f

# Execute commands in PHP container
docker compose exec php composer install
docker compose exec php php bin/console doctrine:database:create
docker compose exec php php bin/console doctrine:migrations:migrate
```

### Frontend Asset Building (Docker)
```bash
docker compose run --rm node npm install
docker compose run --rm node npm run build

# Watch for changes during development (run locally)
docker compose run --rm node npm run watch
```

### Console Commands (Docker)
```bash
# Run item discovery
docker compose exec php php bin/console app:discover-items

# Update all item prices
docker compose exec php php bin/console app:update-prices

# Send Discord alerts
docker compose exec php php bin/console app:send-alerts

# Monitor game updates
docker compose exec php php bin/console app:monitor-updates
```

### Database Operations (Docker)
```bash
# Generate migration
docker compose exec php php bin/console make:migration

# Run migrations
docker compose exec php php bin/console doctrine:migrations:migrate

# Load fixtures (if available)
docker compose exec php php bin/console doctrine:fixtures:load
```

## Configuration Management

Environment-specific configurations for:
- MySQL database connection strings
- Cron job scheduling parameters
- Docker container environment variables
- Tailwind CSS build configuration

### Docker Environment Variables
Key environment variables for Docker setup:
- `DATABASE_URL`: MySQL connection string
- `MYSQL_ROOT_PASSWORD`: MySQL root password
- `MYSQL_DATABASE`: Database name
- `MYSQL_USER`: Database user
- `MYSQL_PASSWORD`: Database password

## Key Integration Points

## Cron Job Architecture

## Error Handling Strategy

- Comprehensive logging for all API calls
- Circuit breaker pattern for failing services
- Automatic retry with exponential backoff
