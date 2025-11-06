#!/bin/bash -e

################################################################################
# CS2 Inventory Management System - Production Deployment Script
################################################################################
#
# This script deploys updates to the CS2 Inventory application in production.
#
# What this script does:
#   1. Pulls latest code from git
#   2. Creates backup of current deployment
#   3. Runs pre-deployment checks (database, disk space, assets)
#   4. Builds and restarts Docker containers
#   5. Runs database migrations
#   6. Warms up cache
#   7. Performs health check
#
# Prerequisites:
#   - Server set up with deploy/setup.sh
#   - External MySQL database configured
#   - .env file configured with production settings
#   - Frontend assets built and committed (npm run build)
#
# Usage:
#   ./deploy/update.sh -f
#
# The -f flag is required to ensure the script is run intentionally.
#
################################################################################

readonly USER="ubuntu"
readonly REPO_BRANCH="master"
readonly PROJECT_NAME="cs2inventory"

readonly REPO_PATH="$(cd -P "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly IAM="$(whoami)"
readonly BACKUP_DIR="${REPO_PATH}/backups"
readonly LOG_FILE="${REPO_PATH}/var/log/deploy.log"

# Color codes for output
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m' # No Color

################################################################################
# Helper Functions
################################################################################

log() {
    local message="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo -e "${BLUE}[${timestamp}]${NC} ${message}"
    mkdir -p "$(dirname "${LOG_FILE}")"
    echo "[${timestamp}] ${message}" >> "${LOG_FILE}"
}

log_success() {
    local message="$1"
    echo -e "${GREEN}✓${NC} ${message}"
    log "SUCCESS: ${message}"
}

log_warning() {
    local message="$1"
    echo -e "${YELLOW}⚠${NC} ${message}"
    log "WARNING: ${message}"
}

log_error() {
    local message="$1"
    echo -e "${RED}✗${NC} ${message}"
    log "ERROR: ${message}"
}

usage() {
    echo "Usage: $0 -f"
    echo ""
    echo "Deploy CS2 Inventory application updates to production"
    echo ""
    echo "Options:"
    echo "  -f    Force deployment (required to run the script)"
    echo ""
    echo "Example:"
    echo "  $0 -f"
    exit 1
}

################################################################################
# Pre-flight Checks
################################################################################

check_user() {
    if [[ "${IAM}" != "${USER}" ]]; then
        log_error "This script must be run as ${USER}, not ${IAM}"
        exit 1
    fi
}

check_env_file() {
    if [ ! -f "${REPO_PATH}/.env" ]; then
        log_error ".env file not found"
        log_error "Please create .env from .env.prod.example and configure it"
        exit 1
    fi
    log_success "Environment file exists"
}

check_disk_space() {
    local available=$(df -BG "${REPO_PATH}" | awk 'NR==2 {print $4}' | sed 's/G//')
    local required=2

    if [ "${available}" -lt "${required}" ]; then
        log_error "Insufficient disk space. Available: ${available}GB, Required: ${required}GB"
        exit 1
    fi
    log_success "Disk space check passed (${available}GB available)"
}

check_database_connectivity() {
    log "Checking database connectivity..."

    if docker compose run --rm php php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; then
        log_success "Database connection successful"
    else
        log_error "Database connection failed"
        log_error "Please verify DATABASE_URL in .env file"
        exit 1
    fi
}

check_built_assets() {
    if [ ! -d "${REPO_PATH}/public/build" ] || [ -z "$(ls -A ${REPO_PATH}/public/build 2>/dev/null)" ]; then
        log_warning "Built assets not found in public/build/"
        log_warning "Make sure you ran 'npm run build' locally and committed the files"
        read -p "Continue anyway? (y/N) " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    else
        log_success "Built assets found"
    fi
}

################################################################################
# Backup Functions
################################################################################

create_backup() {
    log "Creating backup..."

    mkdir -p "${BACKUP_DIR}"

    local backup_file="${BACKUP_DIR}/backup-$(date +%Y%m%d-%H%M%S).tar.gz"

    # Create backup excluding large directories
    tar -czf "${backup_file}" \
        --exclude='var/cache' \
        --exclude='var/log' \
        --exclude='var/sessions' \
        --exclude='var/data' \
        --exclude='.git' \
        --exclude='node_modules' \
        --exclude='backups' \
        -C "${REPO_PATH}" . 2>/dev/null

    log_success "Backup created: ${backup_file}"

    # Keep only last 5 backups
    ls -t "${BACKUP_DIR}"/backup-*.tar.gz | tail -n +6 | xargs -r rm
    log "Old backups cleaned (keeping last 5)"
}

################################################################################
# Deployment Functions
################################################################################

pull_latest_code() {
    log "Pulling latest code from ${REPO_BRANCH}..."

    cd "${REPO_PATH}"

    git fetch origin "${REPO_BRANCH}"
    git checkout -f "${REPO_BRANCH}"
    git clean -fd
    git pull

    # Make update script executable
    chmod +x deploy/update.sh

    log_success "Code updated successfully"
}

setup_production_compose() {
    log "Setting up production compose configuration..."

    cd "${REPO_PATH}"

    # Copy production config to override file for automatic merging
    cp -f compose.prod.yml compose.override.yml
    log "Copied compose.prod.yml to compose.override.yml"

    log_success "Production compose configuration ready"
}

build_and_deploy() {
    log "Building and deploying containers..."

    cd "${REPO_PATH}"

    # Pull base images
    docker compose pull

    # Build with BuildKit
    DOCKER_BUILDKIT=1 docker compose build --pull --no-cache

    # Start containers
    docker compose up --remove-orphans -d

    log_success "Containers built and deployed"
}

run_migrations() {
    log "Running database migrations..."

    cd "${REPO_PATH}"

    docker compose exec -T php php bin/console doctrine:migrations:migrate \
        --allow-no-migration \
        --no-interaction

    log_success "Database migrations completed"
}

warm_cache() {
    log "Warming up cache..."

    cd "${REPO_PATH}"

    docker compose exec -T php php bin/console cache:clear --env=prod --no-interaction
    docker compose exec -T php php bin/console cache:warmup --env=prod --no-interaction

    log_success "Cache warmed"
}

perform_health_check() {
    log "Performing health check..."

    # Wait a few seconds for containers to fully start
    sleep 5

    # Check if containers are running
    if docker compose ps | grep -q "Up"; then
        log_success "Containers are running"
    else
        log_warning "Some containers may not be running properly"
    fi

    # Try to reach the application
    if curl -f -s http://localhost/ > /dev/null 2>&1; then
        log_success "Application is responding"
    else
        log_warning "Application health check failed (this may be normal if behind proxy)"
    fi

    # Check PHP-FPM
    if docker compose exec -T php php --version > /dev/null 2>&1; then
        log_success "PHP is responding"
    else
        log_error "PHP health check failed"
    fi
}

################################################################################
# Main Deployment Flow
################################################################################

main() {
    # Start total timer
    local timer_total=${SECONDS}

    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}CS2 Inventory Deployment${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo ""

    log "Starting deployment to ${PROJECT_NAME}"

    # User check
    check_user

    # Environment check
    check_env_file

    cd "${REPO_PATH}"

    # Pre-deployment checks
    echo -e "\n${BLUE}Running pre-deployment checks...${NC}"
    check_disk_space
    check_built_assets

    # Create backup
    echo -e "\n${BLUE}Creating backup...${NC}"
    local timer_backup=${SECONDS}
    create_backup
    local timer_backup=$((${SECONDS} - ${timer_backup}))

    # Pull latest code
    echo -e "\n${BLUE}Updating code...${NC}"
    local timer_git=${SECONDS}
    pull_latest_code
    local timer_git=$((${SECONDS} - ${timer_git}))

    # Setup production configuration
    setup_production_compose

    # Build and deploy
    echo -e "\n${BLUE}Building and deploying containers...${NC}"
    local timer_docker=${SECONDS}
    build_and_deploy
    local timer_docker=$((${SECONDS} - ${timer_docker}))

    # Database connectivity check (now that containers are up)
    check_database_connectivity

    # Run maintenance tasks
    echo -e "\n${BLUE}Running maintenance tasks...${NC}"
    local timer_maintenance=${SECONDS}
    run_migrations
    warm_cache
    local timer_maintenance=$((${SECONDS} - ${timer_maintenance}))

    # Health check
    echo -e "\n${BLUE}Performing health check...${NC}"
    perform_health_check

    # Calculate total time
    local timer_total=$((${SECONDS} - ${timer_total}))

    # Summary
    echo ""
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}Deployment Complete!${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo ""
    printf "Backup:      %4ss\n" ${timer_backup}
    printf "Git:         %4ss\n" ${timer_git}
    printf "Docker:      %4ss\n" ${timer_docker}
    printf "Maintenance: %4ss\n" ${timer_maintenance}
    printf "─────────────────\n"
    printf "Total:       %4ss\n" ${timer_total}
    echo ""

    log_success "Deployment completed in ${timer_total}s"

    echo -e "${YELLOW}Rollback instructions:${NC}"
    echo "If you need to rollback this deployment:"
    echo "  1. Extract backup: tar -xzf ${BACKUP_DIR}/backup-<timestamp>.tar.gz"
    echo "  2. Or: git reset --hard <previous-commit>"
    echo "  3. Setup override: cp compose.prod.yml compose.override.yml"
    echo "  4. Then: docker compose build --no-cache && docker compose up -d"
    echo ""
}

################################################################################
# Script Entry Point
################################################################################

# Check arguments
if [[ $# -eq 0 ]]; then
    usage
fi

if [[ "$1" != "-f" ]]; then
    echo "Update flag -f was not included. Nothing was run."
    usage
fi

# Run main deployment
main
