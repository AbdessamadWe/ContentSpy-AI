#!/bin/bash
set -e

echo "🚀 Starting ContentSpy AI deployment..."

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Check if we're in the right directory
if [ ! -f "docker/docker-compose.prod.yml" ]; then
    echo -e "${RED}Error: This script must be run from the project root directory${NC}"
    exit 1
fi

# Prompt for confirmation
read -p "Continue with deployment? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Deployment cancelled."
    exit 0
fi

echo -e "${YELLOW}Pulling latest changes...${NC}"
git pull origin main || echo "Git pull failed, continuing with local code..."

echo -e "${YELLOW}Installing PHP dependencies...${NC}"
cd backend
composer install --no-dev --optimize-autoloader

echo -e "${YELLOW}Running database migrations...${NC}"
php artisan migrate --force

echo -e "${YELLOW}Clearing and caching configuration...${NC}"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo -e "${YELLOW}Building frontend...${NC}"
cd ../frontend
npm install
npm run build
cd ..

echo -e "${YELLOW}Building and starting Docker containers...${NC}"
cd docker
docker-compose -f docker-compose.prod.yml build --parallel
docker-compose -f docker-compose.prod.yml up -d

echo -e "${YELLOW}Restarting Laravel Horizon...${NC}"
docker exec contentspy-horizon php artisan horizon:terminate

echo -e "${YELLOW}Scheduling Laravel...${NC}"
docker exec contentspy-app php artisan schedule:work &

echo -e "${GREEN}✅ Deployment complete!${NC}"
echo ""
echo "Services running:"
echo "  - Web: https://contentspy.ai"
echo "  - Horizon: https://contentspy.ai/horizon"
echo "  - Soketi: wss://contentspy.ai:6001"
echo ""
echo "Useful commands:"
echo "  - docker-compose -f docker-compose.prod.yml logs -f"
echo "  - docker exec contentspy-horizon php artisan horizon:work"
echo "  - docker exec contentspy-app php artisan tinker"