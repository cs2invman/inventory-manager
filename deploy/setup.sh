#!/bin/bash
set -e

################################################################################
# CS2 Inventory Management System - Production Server Setup Script
################################################################################
#
# This script sets up a fresh Ubuntu 22.04 LTS server for running the CS2
# Inventory Management System in production.
#
# What this script installs:
#   - Docker Engine (latest stable)
#   - Docker Compose v2.40.3+
#   - Git for repository management
#   - Required system dependencies
#
# Prerequisites:
#   - Fresh Ubuntu 22.04 LTS server
#   - Root access
#   - At least 2GB RAM, 20GB disk space
#
# Usage:
#   sudo ./setup.sh
#
# After running this script, you need to:
#   1. Reboot the server (required for Docker group to take effect)
#   2. Set up deploy SSH key for git repository access
#   3. Clone the repository as the ubuntu user
#   4. Configure .env file with production settings
#   5. Set up external MySQL database connection
#
################################################################################

readonly DOCKER_COMPOSE_VERSION="v2.40.3"
readonly APP_USER="ubuntu"
readonly APP_DIR="/home/${APP_USER}/cs2inventory"

# Color codes for output
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly NC='\033[0m' # No Color

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}CS2 Inventory Production Server Setup${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Error: Please run as root${NC}"
    echo "Usage: sudo ./setup.sh"
    exit 1
fi

# Check Ubuntu version
if ! grep -q "22.04" /etc/os-release; then
    echo -e "${YELLOW}Warning: This script is designed for Ubuntu 22.04 LTS${NC}"
    echo -e "${YELLOW}Your version: $(lsb_release -d | cut -f2)${NC}"
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

echo -e "${GREEN}[1/6] Updating system packages...${NC}"
apt update
apt upgrade -y

echo -e "${GREEN}[2/6] Installing system dependencies...${NC}"
apt install -y \
    apt-transport-https \
    ca-certificates \
    curl \
    software-properties-common \
    git \
    vim \
    htop \
    ufw

echo -e "${GREEN}[3/6] Installing Docker Engine...${NC}"
# Add Docker's official GPG key
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

# Add Docker repository
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  tee /etc/apt/sources.list.d/docker.list > /dev/null

# Install Docker
apt update
apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

echo -e "${GREEN}[4/6] Installing Docker Compose standalone...${NC}"
# Add user to docker group
usermod -aG docker ${APP_USER}

# Install Docker Compose standalone (for compatibility)
mkdir -p /home/${APP_USER}/.docker/cli-plugins/
curl -SL "https://github.com/docker/compose/releases/download/${DOCKER_COMPOSE_VERSION}/docker-compose-$(uname -s)-$(uname -m)" \
    -o /home/${APP_USER}/.docker/cli-plugins/docker-compose
chmod +x /home/${APP_USER}/.docker/cli-plugins/docker-compose
chown -R ${APP_USER}:${APP_USER} /home/${APP_USER}/.docker/

echo -e "${GREEN}[5/6] Creating application directory structure...${NC}"
# Create application directory
mkdir -p ${APP_DIR}
chown ${APP_USER}:${APP_USER} ${APP_DIR}

# Create .env template if it doesn't exist
if [ ! -f "${APP_DIR}/.env" ]; then
    cat > ${APP_DIR}/.env.template << 'EOF'
# CS2 Inventory Production Environment Configuration
# Copy this file to .env and fill in your actual values

# Application
APP_ENV=prod
APP_SECRET=CHANGE_ME_TO_RANDOM_32_CHAR_STRING

# Database (External MySQL)
DATABASE_URL="mysql://USERNAME:PASSWORD@HOSTNAME:3306/cs2inventory?serverVersion=8.0&charset=utf8mb4"

# Steam API
STEAM_WEB_API_KEY=YOUR_STEAM_API_KEY_HERE
STEAM_WEB_API_BASE_URL=https://www.steamwebapi.com/steam/api
STEAM_ITEMS_STORAGE_PATH=var/data/steam-items

# Lock mechanism
LOCK_DSN=flock

# Application URL
DEFAULT_URI=https://cs2invman.gms.tools

# Nginx port (80 for production)
NGINX_PORT=80
EOF
    chown ${APP_USER}:${APP_USER} ${APP_DIR}/.env.template
    echo -e "${YELLOW}Created .env.template in ${APP_DIR}${NC}"
fi

echo -e "${GREEN}[6/6] Configuring firewall...${NC}"
# Configure UFW (Uncomplicated Firewall)
ufw --force enable
ufw default deny incoming
ufw default allow outgoing
ufw allow ssh
ufw allow 80/tcp   # HTTP
ufw allow 443/tcp  # HTTPS (if needed)

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Setup Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "${YELLOW}Docker version:${NC}"
docker --version
echo ""
echo -e "${YELLOW}Docker Compose version:${NC}"
docker compose version
echo ""
echo -e "${GREEN}Next steps:${NC}"
echo "1. ${YELLOW}REBOOT THE SERVER${NC} (required for Docker group to take effect)"
echo "   Run: sudo shutdown -r now"
echo ""
echo "2. After reboot, set up deploy SSH key as ${APP_USER}:"
echo "   ssh-keygen -t ed25519 -C 'cs2inventory-deploy'"
echo "   cat ~/.ssh/id_ed25519.pub"
echo "   # Add the public key to your git repository's deploy keys"
echo ""
echo "3. Clone the repository:"
echo "   cd ~"
echo "   git clone <YOUR_REPOSITORY_URL> cs2inventory"
echo ""
echo "4. Configure production environment:"
echo "   cd cs2inventory"
echo "   cp .env.prod.example .env"
echo "   nano .env  # Fill in your production values"
echo ""
echo "5. Set up external MySQL database (required)"
echo "   # See CLAUDE.md for database setup instructions"
echo ""
echo "6. Run initial deployment:"
echo "   cd ~/cs2inventory"
echo "   ./deploy/update.sh -f"
echo ""
echo -e "${RED}IMPORTANT: Reboot now before proceeding!${NC}"
echo -e "${YELLOW}Run: sudo shutdown -r now${NC}"
echo ""
