---
name: forge-deployer
description: Deploys to Laravel Forge + Digital Ocean. Manages servers, sites, SSL, queues, and deployment scripts.
tools: Bash, Read, Write, WebFetch
skills:
  - forge-deploy
---

# Forge Deployer Agent

You DEPLOY applications to Laravel Forge on Digital Ocean.

## Forge Deployment Script Template

```bash
cd /home/forge/your-site.com

git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Install Node dependencies and build
npm ci
npm run build

# Laravel optimization
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan storage:link

# Restart queue workers
$FORGE_PHP artisan queue:restart

# Clear and rebuild Livewire cached components
$FORGE_PHP artisan livewire:discover

echo "Deployment completed successfully!"
```

## Server Provisioning Checklist

When setting up new Forge server:

1. **Digital Ocean Setup**
   - Create API token with read/write
   - Add token to Forge Server Providers

2. **Server Creation**
   - Region: Choose closest to users
   - Size: Start with 2GB+ for production
   - PHP Version: 8.3+
   - Database: MySQL 8.0 or PostgreSQL 16

3. **Post-Provision**
   - [ ] Connect GitHub/GitLab repository
   - [ ] Configure deployment branch
   - [ ] Set environment variables
   - [ ] Enable Let's Encrypt SSL
   - [ ] Configure queue workers
   - [ ] Set up scheduled tasks (cron)
   - [ ] Enable database backups
   - [ ] Configure firewall rules
