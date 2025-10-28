# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CS2 Steam Marketplace Platform - A real-time monitoring system that tracks CS2 item prices from Steam marketplace, stores historical data, and sends Discord notifications for price changes and game updates.

## Technology Stack

- **Backend**: PHP 8.4 + Symfony 7+
- **Frontend**: Tailwind CSS for styling
- **Database**: MySQL with Doctrine ORM
- **Queue System**: Symfony Messenger for async processing
- **Proxy Management**: Rotating proxy service for Steam API rate limiting
- **Notifications**: Discord webhooks with rich embeds
- **Containerization**: Docker + Docker Compose for development and deployment
- **Scheduling**: Cron jobs via Symfony Console commands

## Core Architecture

### Services Layer
- **Steam Market Client**: HTTP client with proxy rotation for Steam marketplace API calls
- **CS2 Item Scraper**: Service for discovering and fetching item metadata and pricing
- **Discord Notification Service**: Webhook-based messaging with customizable alert thresholds
- **Price Monitoring Service**: Real-time price change detection and trend analysis
- **Proxy Rotation Service**: Manages proxy pool to avoid Steam API rate limiting

### Data Layer
- **CS2 Item Entity**: Stores item metadata (name, type, rarity, image URLs)
- **Price History Entity**: Time-series data for price tracking and trend analysis
- **Discord Alert Log**: Notification history and delivery status
- **Proxy Entity**: Proxy configuration and health status tracking

### Background Processing
- **Item Discovery Command**: Finds new CS2 items and updates metadata
- **Price Update Command**: Fetches current market prices for all tracked items
- **Discord Alert Command**: Processes price changes and sends notifications
- **Game Update Monitor Command**: Watches for CS2 game updates and announcements

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
- Steam API credentials and rate limits
- Discord webhook URLs and bot tokens
- MySQL database connection strings
- Proxy service endpoints and authentication
- Alert thresholds and monitoring intervals
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

### Steam API Integration
- Uses Steam Community Market API for price data
- Implements exponential backoff for rate limiting
- Proxy rotation to handle IP-based restrictions
- Handles Steam's HTML scraping when API is insufficient

### Discord Integration
- Rich embed formatting for price alerts
- Customizable notification templates
- Channel-specific routing based on item types or price thresholds
- Error handling for webhook failures

### Proxy Management
- Health checking for proxy endpoints
- Automatic failover and rotation
- Performance metrics tracking
- Support for HTTP/HTTPS/SOCKS proxies

## Cron Job Architecture

Jobs are designed to run independently with proper error handling:
- Item discovery: Every 6 hours
- Price updates: Every 15 minutes for tracked items
- Discord alerts: Every 5 minutes for processed price changes
- Game update monitoring: Every 30 minutes
- Proxy health checks: Every 10 minutes

## Error Handling Strategy

- Comprehensive logging for all Steam API calls and proxy usage
- Circuit breaker pattern for failing services
- Dead letter queue for failed Discord notifications
- Automatic retry with exponential backoff
- Health check endpoints for monitoring system status