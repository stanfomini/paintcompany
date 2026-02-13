# Production Deployment Playbook

## Goal
Mirror the dev server (`apexnegotiations.com`) to a new Digital Ocean droplet for production (`paintingmadeeasydenver.com`).

## Current Dev Server Specs
- **OS**: Ubuntu 24.04.1 LTS
- **CPU**: 8 cores
- **RAM**: 16 GB
- **Disk**: 309 GB (36 GB used)
- **PHP**: 8.3.11 with extensions: redis, imagick, gd, swoole, memcached, pcntl, imap, gmp, intl
- **MySQL**: 8.0.45
- **Redis**: 7.4.0
- **Node**: 22.21.0 / NPM 11.7.0
- **Nginx**: 1.26.2
- **Supervisor**: 4.2.5
- **Git repo**: `git@github.com:stanfomini/paintcompany.git`
- **Database**: `tool1`
- **Branch for production**: `main`

---

## PHASE 1: Create Server on Forge (Forge Dashboard)

### Step 1: Provision New Droplet
1. Go to **Forge Dashboard** → **Create Server**
2. Choose **Digital Ocean** as provider
3. Settings:
   - **Name**: `paintingmadeeasy-production`
   - **Region**: Pick closest to Denver (e.g., `SFO3` or `NYC3`)
   - **Size**: **8 GB RAM / 4 vCPU** minimum ($48/mo) — you can start smaller and scale up
     - If budget allows, match dev: **16 GB RAM / 8 vCPU**
   - **PHP Version**: `8.3`
   - **Database**: `MySQL 8.0`
   - **Post-Deployment**: Leave default
4. Click **Create Server** — takes ~5 minutes

### Step 2: Configure Server After Provisioning
Once the server is provisioned:

1. **SSH Keys**: Forge adds your key automatically. Verify you can SSH in.
2. **PHP Extensions**: Go to **Server → PHP** and install:
   - `php8.3-redis`
   - `php8.3-imagick`
   - `php8.3-gd`
   - `php8.3-imap`
   - `php8.3-gmp`
   - `php8.3-intl`
   - `php8.3-memcached`
   - `php8.3-swoole` (if you need it — mostly for Octane, not required)

   Most of these come pre-installed by Forge, but verify.

3. **Node.js**: Forge installs Node by default. Verify version matches (22.x).

---

## PHASE 2: Create Production Site

### Step 3: Add Main Site
1. Go to **Server → Sites → New Site**
2. Settings:
   - **Domain**: `paintingmadeeasydenver.com`
   - **Project Type**: General PHP / Laravel
   - **Web Directory**: `/public`
   - **PHP Version**: 8.3
   - **Create Database**: YES → name it `paintingmadeeasy_prod`

### Step 4: Connect Git Repository
1. On the site page, go to **Git Repository**
2. **Repository**: `stanfomini/paintcompany`
3. **Branch**: `main`
4. **Install Repository** — this clones the code

### Step 5: SSL Certificate
1. Go to **Site → SSL**
2. Click **Let's Encrypt**
3. Enter domain: `paintingmadeeasydenver.com`
4. Enable auto-renewal

### Step 6: Environment Variables
Go to **Site → Environment** and set:

```env
APP_NAME="Painting Made Easy Denver"
APP_ENV=production
APP_KEY=  # Will generate in Step 8
APP_DEBUG=false
APP_URL=https://paintingmadeeasydenver.com

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=paintingmadeeasy_prod
DB_USERNAME=forge
DB_PASSWORD=  # Forge generates this — check Server → Database

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis

REVERB_APP_ID=  # Generate new ones (see Phase 3)
REVERB_APP_KEY=  # Generate new ones
REVERB_APP_SECRET=  # Generate new ones
REVERB_HOST=ws.paintingmadeeasydenver.com
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=ws.paintingmadeeasydenver.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Email — UPDATE THESE for production domain
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.paintingmadeeasydenver.com  # Or your existing Mailgun domain
MAILGUN_SECRET=  # Your Mailgun API key
MAILGUN_ENDPOINT=api.mailgun.net
MAIL_FROM_ADDRESS="hello@paintingmadeeasydenver.com"
MAIL_FROM_NAME="${APP_NAME}"

# Stripe — LIVE KEYS
STRIPE_KEY=  # pk_live_...
STRIPE_SECRET=  # sk_live_...
STRIPE_WEBHOOK_SECRET=  # whsec_...

# Increase — PRODUCTION
INCREASE_CLIENT_ID=
INCREASE_CLIENT_SECRET=
INCREASE_WEBHOOK_SECRET=
INCREASE_ENVIRONMENT=production

# Photo Storage (Wasabi S3)
PHOTOS_DISK=photos
PHOTOS_DISK_DRIVER=s3
PHOTOS_AWS_ACCESS_KEY_ID=
PHOTOS_AWS_SECRET_ACCESS_KEY=
PHOTOS_AWS_DEFAULT_REGION=us-east-1
PHOTOS_AWS_BUCKET=paintingmadeeasy-photos
PHOTOS_AWS_ENDPOINT=https://s3.us-east-1.wasabisys.com
PHOTOS_AWS_USE_PATH_STYLE_ENDPOINT=true
PHOTO_MAX_SIZE_MB=15

# Python Service (optional — enable when ready)
PYTHON_ENABLED=false
PYTHON_SERVICE_URL=http://127.0.0.1:8000
PYTHON_SERVICE_TIMEOUT=60

VITE_APP_NAME="${APP_NAME}"
```

### Step 7: Deployment Script
Go to **Site → Deployments → Deployment Script** and set:

```bash
cd /home/forge/paintingmadeeasydenver.com

git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci
npm run build

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service php8.3-fpm reload
) 9>/tmp/fpmlock

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan storage:link
$FORGE_PHP artisan queue:restart

echo "Deployment completed at $(date)"
```

### Step 8: First Deployment (SSH into server)
SSH into the new server and run:

```bash
cd /home/forge/paintingmadeeasydenver.com

# Generate app key
php artisan key:generate

# Run migrations (NOT migrate:fresh!)
php artisan migrate --force

# Run all production seeders
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=SubstrateSeeder --force
php artisan db:seed --class=PaintCatalogSeeder --force
php artisan db:seed --class=NestedAreaTemplateSeeder --force
php artisan db:seed --class=SpreadsheetProductionRateSeeder --force
php artisan db:seed --class=CompanyAllocationSeeder --force
php artisan db:seed --class=EstimateTemplateSeeder --force
php artisan db:seed --class=ServiceCategorySeeder --force
php artisan db:seed --class=BudgetCategorySeeder --force
php artisan db:seed --class=ChartOfAccountsSeeder --force
php artisan db:seed --class=BudgetAccountMappingSeeder --force
php artisan db:seed --class=MarketingSourceSeeder --force
php artisan db:seed --class=LegalDocumentsSeeder --force
php artisan db:seed --class=LeadSourceSeeder --force
php artisan db:seed --class=CrewContractSeeder --force
php artisan db:seed --class=OwnerUserSeeder --force

# Create storage link
php artisan storage:link

# Cache everything
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**DO NOT run `DatabaseSeeder`** — that creates test users with `admin@example.com`. Use `OwnerUserSeeder` to create your real owner account (you may need to create/update this seeder with your real email).

---

## PHASE 3: WebSocket Site (Reverb)

### Step 9: Create WebSocket Subdomain Site
1. **DNS**: Add an A record for `ws.paintingmadeeasydenver.com` → new server IP
2. On Forge: **Server → Sites → New Site**
   - **Domain**: `ws.paintingmadeeasydenver.com`
   - **Project Type**: General PHP / Laravel
   - **Web Directory**: `/public`
3. **Git Repository**: Same repo `stanfomini/paintcompany`, branch `main`
4. **SSL**: Let's Encrypt for `ws.paintingmadeeasydenver.com`

### Step 10: Generate Reverb Credentials
SSH into server:
```bash
# Generate random credentials
php -r "echo 'REVERB_APP_ID=' . random_int(100000, 999999) . PHP_EOL;"
php -r "echo 'REVERB_APP_KEY=' . bin2hex(random_bytes(10)) . PHP_EOL;"
php -r "echo 'REVERB_APP_SECRET=' . bin2hex(random_bytes(10)) . PHP_EOL;"
```
Use these values in BOTH the main site AND the WebSocket site `.env` files.

### Step 11: WebSocket Site Environment
Set the `.env` on the `ws.paintingmadeeasydenver.com` site to match the main site's Reverb credentials. The WS site only needs:
```env
APP_NAME="PaintingMadeEasy WS"
APP_ENV=production
APP_KEY=  # Same as main site
APP_DEBUG=false
APP_URL=https://ws.paintingmadeeasydenver.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=paintingmadeeasy_prod
DB_USERNAME=forge
DB_PASSWORD=  # Same as main site

REVERB_APP_ID=  # Same as main site
REVERB_APP_KEY=  # Same as main site
REVERB_APP_SECRET=  # Same as main site
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http
```

### Step 12: Reverb Daemon
Go to **Server → Daemons → New Daemon**:
- **Command**: `php8.3 artisan reverb:start --no-interaction --port=8080`
- **Directory**: `/home/forge/ws.paintingmadeeasydenver.com`
- **User**: `forge`
- **Processes**: 1

### Step 13: Nginx Proxy for WebSocket
On the `ws.paintingmadeeasydenver.com` site, go to **Nginx Configuration** and add inside the `server {}` block (before the closing brace):

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400;
    proxy_send_timeout 86400;
}

location /apps {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400;
    proxy_send_timeout 86400;
}
```

---

## PHASE 4: Queue Workers

### Step 14: Create Queue Workers
On Forge: **Server → Sites → paintingmadeeasydenver.com → Queue**

Create these workers (matching your dev setup):

| Queue Name | Connection | Processes | Sleep | Timeout | Tries |
|------------|-----------|-----------|-------|---------|-------|
| `default` | redis | 1 | 10 | 60 | 3 |
| `notifications` | redis | 1 | 10 | 60 | 3 |
| `broadcasts` | redis | 1 | 10 | 60 | 3 |
| `webhooks` | redis | 1 | 10 | 60 | 3 |
| `slack` | redis | 4 | 8 | 60 | 3 |
| `meilisearch` | redis | 1 | 10 | 60 | 3 |
| `meilisearch_customer` | redis | 1 | 10 | 60 | 3 |

---

## PHASE 5: Scheduler (Cron)

### Step 15: Enable Scheduler
On Forge: **Server → Scheduler → New Scheduled Job**:
- **Command**: `php8.3 /home/forge/paintingmadeeasydenver.com/artisan schedule:run`
- **User**: `forge`
- **Frequency**: Every Minute

This handles both:
- `crew:check-compliance` (daily at 7am)
- `meetings:send-reminders` (every 5 minutes)

---

## PHASE 6: DNS Configuration

### Step 16: Set Up DNS Records
At your domain registrar for `paintingmadeeasydenver.com`:

| Type | Name | Value | TTL |
|------|------|-------|-----|
| A | `@` | `<new-server-ip>` | 300 |
| A | `www` | `<new-server-ip>` | 300 |
| A | `ws` | `<new-server-ip>` | 300 |
| CNAME | `mg` | Points to Mailgun (if using subdomain for email) | 300 |
| TXT | `mg` | Mailgun verification | 300 |

---

## PHASE 7: Code-Server (Optional)

### Step 17: Install Code-Server
SSH into the new production server:

```bash
# Install code-server
curl -fsSL https://code-server.dev/install.sh | sh

# Configure
mkdir -p ~/.config/code-server
cat > ~/.config/code-server/config.yaml << 'EOF'
bind-addr: 127.0.0.1:8081
auth: password
password: YOUR_SECURE_PASSWORD
cert: false
EOF

# Enable and start
sudo systemctl enable --now code-server@forge
```

Then add the same nginx proxy block as your dev server to one of your sites (or create a subdomain like `code.paintingmadeeasydenver.com`).

---

## PHASE 8: Post-Deployment Checklist

### Step 18: Verify Everything

```bash
# SSH into new server, then:
cd /home/forge/paintingmadeeasydenver.com

# Check app is responding
curl -s -o /dev/null -w "%{http_code}" https://paintingmadeeasydenver.com/up

# Check database connection
php artisan tinker --execute="echo DB::connection()->getDatabaseName();"

# Check Redis
php artisan tinker --execute="echo Cache::store('redis')->put('test', 'ok', 60) ? 'Redis OK' : 'Redis FAIL';"

# Check queue workers are running
sudo supervisorctl status

# Check WebSocket is responding
curl -s -o /dev/null -w "%{http_code}" https://ws.paintingmadeeasydenver.com

# Check scheduler is running
php artisan schedule:list

# Send a test email
php artisan tinker --execute="Mail::raw('Production server is live!', function(\$m) { \$m->to('your@email.com')->subject('Prod Test'); });"
```

### Step 19: Security Hardening
- [ ] `APP_ENV=production` and `APP_DEBUG=false` confirmed
- [ ] Database password is unique (not shared with dev)
- [ ] Reverb credentials are unique (not shared with dev)
- [ ] Stripe is using LIVE keys (not test)
- [ ] Mailgun domain verified for production domain
- [ ] SSL certificates active on all sites
- [ ] `.env` file permissions: `chmod 600 .env`
- [ ] Firewall: Only ports 80, 443, 22 open (Forge handles this)

### Step 20: Create Your Owner Account
You'll need a production seeder or tinker command to create your real admin user:

```bash
php artisan tinker --execute="
\$user = App\Models\User::create([
    'name' => 'Your Name',
    'email' => 'your@email.com',
    'password' => bcrypt('your-secure-password'),
    'type' => 'internal',
    'email_verified_at' => now(),
]);
echo 'Created user: ' . \$user->id;
"
```

---

## Quick Reference: Dev vs Production

| Setting | Dev (apexnegotiations.com) | Prod (paintingmadeeasydenver.com) |
|---------|---------------------------|-----------------------------------|
| APP_ENV | local | production |
| APP_DEBUG | true | false |
| Database | tool1 | paintingmadeeasy_prod |
| Reverb Host | ws.test.scriptandautomations.com | ws.paintingmadeeasydenver.com |
| Stripe | Test keys | Live keys |
| Increase | sandbox | production |
| Queue | database | redis |
| Cache | redis | redis |
| Session | redis | redis |
| Git Branch | feature/* | main |

---

## Estimated Timeline
- Server provisioning: 5 min
- Site creation + SSL: 10 min
- Environment setup: 15 min
- First deploy + seeders: 10 min
- WebSocket setup: 15 min
- Queue workers: 5 min
- DNS propagation: 5 min - 48 hours
- Verification: 10 min
- **Total hands-on: ~1 hour** (plus DNS propagation wait)

## Estimated Monthly Cost
- Digital Ocean 8GB/4vCPU droplet: ~$48/mo
- Domain: ~$12/year
- Mailgun: Free tier (up to 1,000 emails/mo) or ~$35/mo
- Wasabi storage: ~$7/mo
- **Total: ~$55-90/mo**
