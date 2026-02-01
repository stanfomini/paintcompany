---
name: forge-deploy
description: Laravel Forge + Digital Ocean deployment procedures. Server provisioning, deployment scripts, SSL, queues.
---

# Forge Deployment Skill

## Deployment Script

```bash
cd /home/forge/{{ site_domain }}

git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci
npm run build

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan storage:link
$FORGE_PHP artisan queue:restart

echo "Deployment completed!"
```

## Environment Configuration

```env
APP_NAME="Your App"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=your-db-host
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=your-redis-host
REDIS_PASSWORD=your-redis-password
```

## Queue Configuration

In Forge → Site → Queue:
- **Connection**: redis
- **Queue**: default
- **Processes**: 2-4
- **Timeout**: 60
- **Sleep**: 3
- **Tries**: 3

## Scheduler

In Forge → Site → Scheduler:
```
* * * * * cd /home/forge/{{ site }} && php artisan schedule:run >> /dev/null 2>&1
```

## SSL Setup

1. Go to Site → SSL
2. Click "Obtain Let's Encrypt Certificate"
3. Enable "Auto-Renew"
