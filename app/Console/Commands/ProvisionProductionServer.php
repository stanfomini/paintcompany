<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Forge\Forge;

class ProvisionProductionServer extends Command
{
    protected $signature = 'forge:provision
        {--token= : Forge API token (or set FORGE_API_TOKEN in .env)}
        {--domain=paintingmadeeasydenver.com : Production domain}
        {--region=sfo3 : DigitalOcean region (sfo3, nyc3, etc.)}
        {--size=s-4vcpu-8gb : DigitalOcean droplet size}
        {--server-name=paintingmadeeasy-prod : Server name in Forge}
        {--db-name=paintingmadeeasy_prod : Database name}
        {--branch=main : Git branch to deploy}
        {--skip-server : Skip server creation (use existing server)}
        {--server-id= : Existing server ID (with --skip-server)}
        {--dry-run : Show what would be done without executing}';

    protected $description = 'Provision a production server on Digital Ocean via Forge API with full app setup';

    private Forge $forge;

    private array $state = [];

    public function handle(): int
    {
        $token = $this->option('token') ?: config('services.forge.token');

        if (! $token) {
            $this->error('Forge API token required. Use --token=xxx or set FORGE_API_TOKEN in .env');

            return self::FAILURE;
        }

        $this->forge = new Forge($token);
        $domain = $this->option('domain');
        $wsDomain = 'ws.'.$domain;
        $dryRun = $this->option('dry-run');

        $this->info('');
        $this->info('========================================');
        $this->info('  Production Server Provisioning');
        $this->info('========================================');
        $this->info("  Domain:    {$domain}");
        $this->info("  WebSocket: {$wsDomain}");
        $this->info("  Region:    {$this->option('region')}");
        $this->info("  Size:      {$this->option('size')}");
        $this->info("  Database:  {$this->option('db-name')}");
        $this->info("  Branch:    {$this->option('branch')}");
        $this->info('========================================');
        $this->info('');

        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
            $this->info('');
        }

        if (! $dryRun && ! $this->confirm('Proceed with provisioning?')) {
            return self::SUCCESS;
        }

        try {
            // Step 1: Create or use existing server
            $serverId = $this->provisionServer($dryRun);
            if (! $serverId) {
                return self::FAILURE;
            }

            // Step 2: Create database
            $this->createDatabase($serverId, $dryRun);

            // Step 3: Create main site
            $mainSiteId = $this->createMainSite($serverId, $domain, $dryRun);

            // Step 4: Install git repo on main site
            $this->installGitRepo($serverId, $mainSiteId, $domain, $dryRun);

            // Step 5: Set deployment script
            $this->setDeploymentScript($serverId, $mainSiteId, $domain, $dryRun);

            // Step 6: Set environment variables
            $this->setEnvironment($serverId, $mainSiteId, $domain, $wsDomain, $dryRun);

            // Step 7: SSL for main site
            $this->obtainSSL($serverId, $mainSiteId, $domain, $dryRun);

            // Step 8: Create WebSocket site
            $wsSiteId = $this->createWebSocketSite($serverId, $wsDomain, $dryRun);

            // Step 9: Install git repo on WS site
            $this->installGitRepo($serverId, $wsSiteId, $wsDomain, $dryRun);

            // Step 10: Set WS environment
            $this->setWebSocketEnvironment($serverId, $wsSiteId, $wsDomain, $dryRun);

            // Step 11: SSL for WS site
            $this->obtainSSL($serverId, $wsSiteId, $wsDomain, $dryRun);

            // Step 12: Create Reverb daemon
            $this->createReverbDaemon($serverId, $wsDomain, $dryRun);

            // Step 13: Update WS nginx for WebSocket proxy
            $this->updateWebSocketNginx($serverId, $wsSiteId, $dryRun);

            // Step 14: Create queue workers
            $this->createQueueWorkers($serverId, $mainSiteId, $dryRun);

            // Step 15: Create scheduler
            $this->createScheduler($serverId, $domain, $dryRun);

            // Step 16: Create Python setup recipe
            $this->createPythonRecipe($serverId, $domain, $dryRun);

            // Step 17: Create seeder recipe
            $this->createSeederRecipe($serverId, $domain, $dryRun);

            // Step 18: Deploy main site
            $this->deploySite($serverId, $mainSiteId, $domain, $dryRun);

            // Print summary
            $this->printSummary($domain, $wsDomain, $serverId);

        } catch (\Exception $e) {
            $this->error('Provisioning failed: '.$e->getMessage());
            $this->error('State so far: '.json_encode($this->state, JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function provisionServer(bool $dryRun): ?int
    {
        if ($this->option('skip-server')) {
            $serverId = (int) $this->option('server-id');
            if (! $serverId) {
                $this->error('--server-id is required with --skip-server');

                return null;
            }
            $this->info("[1/18] Using existing server ID: {$serverId}");
            $this->state['server_id'] = $serverId;

            return $serverId;
        }

        $this->info('[1/18] Creating DigitalOcean server...');

        if ($dryRun) {
            $this->line("  Would create: {$this->option('server-name')} ({$this->option('size')}) in {$this->option('region')}");

            return 999999;
        }

        // Find DigitalOcean credential
        $credentials = $this->forge->credentials();
        $doCredential = null;
        foreach ($credentials as $cred) {
            if ($cred->type === 'ocean2') {
                $doCredential = $cred;
                break;
            }
        }

        if (! $doCredential) {
            $this->error('No DigitalOcean credential found in Forge. Link it at forge.laravel.com -> Server Providers.');

            return null;
        }

        $this->line("  Using DO credential: {$doCredential->name} (ID: {$doCredential->id})");

        $server = $this->forge->createServer([
            'provider' => 'ocean2',
            'credential_id' => $doCredential->id,
            'name' => $this->option('server-name'),
            'type' => 'app',
            'size' => $this->option('size'),
            'region' => $this->option('region'),
            'php_version' => 'php83',
            'database' => 'mysql8',
            'database_type' => 'mysql8',
            'node_balancer' => false,
        ], wait: true, timeout: 900);

        $this->state['server_id'] = $server->id;
        $this->state['sudo_password'] = $server->sudoPassword;
        $this->state['database_password'] = $server->databasePassword;
        $this->state['server_ip'] = $server->ipAddress;

        $this->info("  Server created! ID: {$server->id}, IP: {$server->ipAddress}");
        $this->warn('  SAVE THESE CREDENTIALS:');
        $this->warn("  Sudo password: {$server->sudoPassword}");
        $this->warn("  Database password: {$server->databasePassword}");

        return $server->id;
    }

    private function createDatabase(int $serverId, bool $dryRun): void
    {
        $dbName = $this->option('db-name');
        $this->info("[2/18] Creating database: {$dbName}");

        if ($dryRun) {
            $this->line("  Would create database: {$dbName}");

            return;
        }

        $this->forge->createDatabase($serverId, [
            'name' => $dbName,
        ]);

        $this->line('  Database created.');
    }

    private function createMainSite(int $serverId, string $domain, bool $dryRun): int
    {
        $this->info("[3/18] Creating main site: {$domain}");

        if ($dryRun) {
            $this->line("  Would create site: {$domain}");

            return 999999;
        }

        $site = $this->forge->createSite($serverId, [
            'domain' => $domain,
            'project_type' => 'php',
            'directory' => '/public',
            'php_version' => 'php83',
        ]);

        $this->state['main_site_id'] = $site->id;
        $this->line("  Site created! ID: {$site->id}");

        return $site->id;
    }

    private function installGitRepo(int $serverId, int $siteId, string $domain, bool $dryRun): void
    {
        $this->info("[4/18] Installing git repository on {$domain}...");

        if ($dryRun) {
            $this->line("  Would install stanfomini/paintcompany ({$this->option('branch')})");

            return;
        }

        $this->forge->installGitRepositoryOnSite($serverId, $siteId, [
            'provider' => 'github',
            'repository' => 'stanfomini/paintcompany',
            'branch' => $this->option('branch'),
            'composer' => true,
        ]);

        $this->line('  Git repo installed.');
    }

    private function setDeploymentScript(int $serverId, int $siteId, string $domain, bool $dryRun): void
    {
        $this->info('[5/18] Setting deployment script...');

        $script = <<<BASH
cd /home/forge/{$domain}

git pull origin \$FORGE_SITE_BRANCH

\$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci
npm run build

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service php8.3-fpm reload
) 9>/tmp/fpmlock

\$FORGE_PHP artisan migrate --force
\$FORGE_PHP artisan config:cache
\$FORGE_PHP artisan route:cache
\$FORGE_PHP artisan view:cache
\$FORGE_PHP artisan storage:link
\$FORGE_PHP artisan queue:restart

echo "Deployment completed at \$(date)"
BASH;

        if ($dryRun) {
            $this->line('  Would set deployment script (git pull, composer, npm, migrate, cache)');

            return;
        }

        $this->forge->updateSiteDeploymentScript($serverId, $siteId, $script);
        $this->line('  Deployment script configured.');
    }

    private function setEnvironment(int $serverId, int $siteId, string $domain, string $wsDomain, bool $dryRun): void
    {
        $this->info('[6/18] Setting environment variables...');

        $reverbId = random_int(100000, 999999);
        $reverbKey = bin2hex(random_bytes(10));
        $reverbSecret = bin2hex(random_bytes(10));
        $pythonApiKey = bin2hex(random_bytes(32));

        $this->state['reverb_app_id'] = $reverbId;
        $this->state['reverb_app_key'] = $reverbKey;
        $this->state['reverb_app_secret'] = $reverbSecret;
        $this->state['python_api_key'] = $pythonApiKey;

        $dbPassword = $this->state['database_password'] ?? 'REPLACE_WITH_DB_PASSWORD';
        $dbName = $this->option('db-name');

        $env = <<<ENV
APP_NAME="Painting Made Easy Denver"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://{$domain}

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
DB_DATABASE={$dbName}
DB_USERNAME=forge
DB_PASSWORD={$dbPassword}

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis

REVERB_APP_ID={$reverbId}
REVERB_APP_KEY={$reverbKey}
REVERB_APP_SECRET={$reverbSecret}
REVERB_HOST={$wsDomain}
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY={$reverbKey}
VITE_REVERB_HOST={$wsDomain}
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.{$domain}
MAILGUN_SECRET=REPLACE_WITH_MAILGUN_SECRET
MAILGUN_ENDPOINT=api.mailgun.net
MAIL_FROM_ADDRESS="hello@{$domain}"
MAIL_FROM_NAME="\${APP_NAME}"

STRIPE_KEY=REPLACE_WITH_STRIPE_LIVE_KEY
STRIPE_SECRET=REPLACE_WITH_STRIPE_LIVE_SECRET
STRIPE_WEBHOOK_SECRET=REPLACE_WITH_STRIPE_WEBHOOK_SECRET

INCREASE_CLIENT_ID=REPLACE_WITH_INCREASE_CLIENT_ID
INCREASE_CLIENT_SECRET=REPLACE_WITH_INCREASE_CLIENT_SECRET
INCREASE_WEBHOOK_SECRET=REPLACE_WITH_INCREASE_WEBHOOK_SECRET
INCREASE_ENVIRONMENT=production

PHOTOS_DISK=photos
PHOTOS_DISK_DRIVER=s3
PHOTOS_AWS_ACCESS_KEY_ID=REPLACE_WITH_WASABI_KEY
PHOTOS_AWS_SECRET_ACCESS_KEY=REPLACE_WITH_WASABI_SECRET
PHOTOS_AWS_DEFAULT_REGION=us-east-1
PHOTOS_AWS_BUCKET=paintingmadeeasy-photos
PHOTOS_AWS_ENDPOINT=https://s3.us-east-1.wasabisys.com
PHOTOS_AWS_USE_PATH_STYLE_ENDPOINT=true
PHOTO_MAX_SIZE_MB=15

PYTHON_ENABLED=true
PYTHON_SERVICE_URL=http://127.0.0.1:8000
PYTHON_SERVICE_TIMEOUT=60
PYTHON_RETRY_ATTEMPTS=3
PYTHON_API_KEY={$pythonApiKey}
ML_GPU_ENABLED=false

VITE_APP_NAME="\${APP_NAME}"
ENV;

        if ($dryRun) {
            $this->line('  Would set environment with:');
            $this->line("    REVERB_APP_ID={$reverbId}");
            $this->line("    REVERB_HOST={$wsDomain}");
            $this->line('    DB, Redis, Stripe, Mailgun, Wasabi, Python placeholders');

            return;
        }

        $this->forge->updateSiteEnvironmentFile($serverId, $siteId, $env);
        $this->line('  Environment configured.');
        $this->warn('  NOTE: You still need to replace STRIPE, MAILGUN, WASABI, and INCREASE values!');
    }

    private function obtainSSL(int $serverId, int $siteId, string $domain, bool $dryRun): void
    {
        $this->info("[7/18] Obtaining SSL certificate for {$domain}...");

        if ($dryRun) {
            $this->line("  Would obtain Let's Encrypt cert for {$domain}");

            return;
        }

        try {
            $this->forge->obtainLetsEncryptCertificate($serverId, $siteId, [
                'domains' => [$domain],
            ]);
            $this->line('  SSL certificate obtained.');
        } catch (\Exception $e) {
            $this->warn("  SSL failed (DNS may not be pointed yet): {$e->getMessage()}");
            $this->warn('  Run SSL manually from Forge dashboard after DNS propagation.');
        }
    }

    private function createWebSocketSite(int $serverId, string $wsDomain, bool $dryRun): int
    {
        $this->info("[8/18] Creating WebSocket site: {$wsDomain}");

        if ($dryRun) {
            $this->line("  Would create site: {$wsDomain}");

            return 999998;
        }

        $site = $this->forge->createSite($serverId, [
            'domain' => $wsDomain,
            'project_type' => 'php',
            'directory' => '/public',
            'php_version' => 'php83',
        ]);

        $this->state['ws_site_id'] = $site->id;
        $this->line("  WS site created! ID: {$site->id}");

        return $site->id;
    }

    private function setWebSocketEnvironment(int $serverId, int $siteId, string $wsDomain, bool $dryRun): void
    {
        $this->info('[10/18] Setting WebSocket environment...');

        $reverbId = $this->state['reverb_app_id'] ?? 'REPLACE';
        $reverbKey = $this->state['reverb_app_key'] ?? 'REPLACE';
        $reverbSecret = $this->state['reverb_app_secret'] ?? 'REPLACE';
        $dbPassword = $this->state['database_password'] ?? 'REPLACE_WITH_DB_PASSWORD';
        $dbName = $this->option('db-name');

        $env = <<<ENV
APP_NAME="PaintingMadeEasy WS"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://{$wsDomain}

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE={$dbName}
DB_USERNAME=forge
DB_PASSWORD={$dbPassword}

REVERB_APP_ID={$reverbId}
REVERB_APP_KEY={$reverbKey}
REVERB_APP_SECRET={$reverbSecret}
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http

CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
ENV;

        if ($dryRun) {
            $this->line('  Would set WS env with REVERB_HOST=0.0.0.0, port 8080');

            return;
        }

        $this->forge->updateSiteEnvironmentFile($serverId, $siteId, $env);
        $this->line('  WebSocket environment configured.');
    }

    private function createReverbDaemon(int $serverId, string $wsDomain, bool $dryRun): void
    {
        $this->info('[12/18] Creating Reverb daemon...');

        if ($dryRun) {
            $this->line('  Would create daemon: php8.3 artisan reverb:start --port=8080');

            return;
        }

        $this->forge->createDaemon($serverId, [
            'command' => 'php8.3 artisan reverb:start --no-interaction --port=8080',
            'user' => 'forge',
            'directory' => "/home/forge/{$wsDomain}",
        ]);

        $this->line('  Reverb daemon created.');
    }

    private function updateWebSocketNginx(int $serverId, int $siteId, bool $dryRun): void
    {
        $this->info('[13/18] Updating WS nginx config for WebSocket proxy...');

        if ($dryRun) {
            $this->line('  Would add /app and /apps proxy_pass to nginx config');

            return;
        }

        try {
            $nginx = $this->forge->siteNginxFile($serverId, $siteId);

            $wsProxy = <<<'NGINX'

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
NGINX;

            // Insert before the closing brace of the server block
            // Find the last occurrence of "location ~ /\.(?!well-known).* {" block end
            $insertBefore = '    location ~ /\.(?!well-known).* {';
            if (str_contains($nginx, $insertBefore)) {
                $nginx = str_replace($insertBefore, $wsProxy."\n\n".$insertBefore, $nginx);
            }

            $this->forge->updateSiteNginxFile($serverId, $siteId, $nginx);
            $this->line('  Nginx WebSocket proxy configured.');
        } catch (\Exception $e) {
            $this->warn("  Nginx update failed: {$e->getMessage()}");
            $this->warn('  You may need to add the WebSocket proxy manually in Forge.');
        }
    }

    private function createQueueWorkers(int $serverId, int $siteId, bool $dryRun): void
    {
        $this->info('[14/18] Creating queue workers...');

        $workers = config('forge-workers.workers', []);
        $defaults = config('forge-workers.defaults', []);

        if (empty($workers)) {
            $this->warn('  No workers defined in config/forge-workers.php');

            return;
        }

        foreach ($workers as $worker) {
            $merged = array_merge($defaults, $worker);

            if ($dryRun) {
                $this->line("  Would create worker: {$merged['queue']} ({$merged['processes']} processes)");

                continue;
            }

            $this->forge->createWorker($serverId, $siteId, [
                'connection' => $merged['connection'] ?? 'redis',
                'queue' => $merged['queue'],
                'processes' => $merged['processes'] ?? 1,
                'timeout' => $merged['timeout'] ?? 60,
                'sleep' => $merged['sleep'] ?? 10,
                'tries' => $merged['tries'] ?? 3,
                'php_version' => $merged['php_version'] ?? 'php83',
                'daemon' => true,
                'force' => $merged['force'] ?? false,
            ]);

            $this->line("  Worker created: {$merged['queue']}");
        }
    }

    private function createScheduler(int $serverId, string $domain, bool $dryRun): void
    {
        $this->info('[15/18] Creating scheduler (cron)...');

        if ($dryRun) {
            $this->line('  Would create minutely cron: schedule:run');

            return;
        }

        $this->forge->createJob($serverId, [
            'command' => "php8.3 /home/forge/{$domain}/artisan schedule:run",
            'user' => 'forge',
            'frequency' => 'minutely',
        ]);

        $this->line('  Scheduler configured (every minute).');
    }

    private function createPythonRecipe(int $serverId, string $domain, bool $dryRun): void
    {
        $this->info('[16/18] Creating Python service setup recipe...');

        $pythonApiKey = $this->state['python_api_key'] ?? 'REPLACE';

        $script = <<<BASH
#!/bin/bash
set -e

echo "=== Installing system dependencies ==="
sudo apt update
sudo apt install -y python3 python3-dev python3-venv build-essential \
    libssl-dev libffi-dev ffmpeg libsm6 libxext6 libxrender-dev libgomp1

echo "=== Setting up Python virtual environment ==="
cd /home/forge/{$domain}/python
python3 -m venv venv
source venv/bin/activate

echo "=== Installing Python packages ==="
pip install --upgrade pip
pip install -r requirements.txt

echo "=== Installing Playwright browser (for scraping) ==="
playwright install chromium || echo "Playwright chromium install skipped"

echo "=== Creating systemd service ==="
sudo tee /etc/systemd/system/apex-python.service > /dev/null << 'SYSTEMD'
[Unit]
Description=Apex Python ML Service
After=network.target

[Service]
User=forge
Group=forge
WorkingDirectory=/home/forge/{$domain}/python
Environment="PATH=/home/forge/{$domain}/python/venv/bin:/usr/bin"
Environment="PYTHON_API_KEY={$pythonApiKey}"
ExecStart=/home/forge/{$domain}/python/venv/bin/uvicorn api.main:app --host 127.0.0.1 --port 8000
Restart=always
RestartSec=5
StandardOutput=append:/var/log/apex-python.log
StandardError=append:/var/log/apex-python.log

[Install]
WantedBy=multi-user.target
SYSTEMD

echo "=== Enabling and starting Python service ==="
sudo systemctl daemon-reload
sudo systemctl enable apex-python
sudo systemctl start apex-python

echo "=== Waiting for service to start ==="
sleep 3
curl -s http://127.0.0.1:8000/health && echo "" && echo "Python service is running!" || echo "Python service failed to start - check logs with: sudo journalctl -u apex-python"

echo "=== Python setup complete ==="
BASH;

        if ($dryRun) {
            $this->line('  Would create recipe: Install Python service (apt packages, venv, systemd)');

            return;
        }

        $recipe = $this->forge->createRecipe([
            'name' => 'Setup Python Service - '.$domain,
            'user' => 'root',
            'script' => $script,
        ]);

        $this->state['python_recipe_id'] = $recipe->id;
        $this->line("  Python recipe created (ID: {$recipe->id}).");
        $this->warn('  Run it manually after first deploy: Forge -> Recipes -> Run on server');
        $this->warn("  Or run: php artisan forge:run-recipe --recipe-id={$recipe->id} --server-id={$serverId}");
    }

    private function createSeederRecipe(int $serverId, string $domain, bool $dryRun): void
    {
        $this->info('[17/18] Creating seeder recipe...');

        $script = <<<BASH
#!/bin/bash
set -e

cd /home/forge/{$domain}

echo "=== Generating app key ==="
php8.3 artisan key:generate --force

echo "=== Running migrations ==="
php8.3 artisan migrate --force

echo "=== Running production seeders ==="
php8.3 artisan db:seed --class=RolesAndPermissionsSeeder --force
php8.3 artisan db:seed --class=SubstrateSeeder --force
php8.3 artisan db:seed --class=PaintCatalogSeeder --force
php8.3 artisan db:seed --class=NestedAreaTemplateSeeder --force
php8.3 artisan db:seed --class=SpreadsheetProductionRateSeeder --force
php8.3 artisan db:seed --class=CompanyAllocationSeeder --force
php8.3 artisan db:seed --class=EstimateTemplateSeeder --force
php8.3 artisan db:seed --class=ServiceCategorySeeder --force
php8.3 artisan db:seed --class=BudgetCategorySeeder --force
php8.3 artisan db:seed --class=ChartOfAccountsSeeder --force
php8.3 artisan db:seed --class=BudgetAccountMappingSeeder --force
php8.3 artisan db:seed --class=MarketingSourceSeeder --force
php8.3 artisan db:seed --class=LegalDocumentsSeeder --force
php8.3 artisan db:seed --class=LeadSourceSeeder --force
php8.3 artisan db:seed --class=CrewContractSeeder --force

echo "=== Also generate WS site key ==="
cd /home/forge/ws.{$domain}
php8.3 artisan key:generate --force

echo "=== Creating storage link ==="
cd /home/forge/{$domain}
php8.3 artisan storage:link

echo "=== Caching config ==="
php8.3 artisan config:cache
php8.3 artisan route:cache
php8.3 artisan view:cache

echo "=== All seeders complete! ==="
echo "Now create your admin user with:"
echo "  cd /home/forge/{$domain}"
echo "  php8.3 artisan tinker"
echo "  > User::create(['name'=>'Your Name','email'=>'you@email.com','password'=>bcrypt('pass'),'type'=>'internal','email_verified_at'=>now()]);"
BASH;

        if ($dryRun) {
            $this->line('  Would create recipe: Run production seeders (16 seeders + key generation)');

            return;
        }

        $recipe = $this->forge->createRecipe([
            'name' => 'Run Production Seeders - '.$domain,
            'user' => 'forge',
            'script' => $script,
        ]);

        $this->state['seeder_recipe_id'] = $recipe->id;
        $this->line("  Seeder recipe created (ID: {$recipe->id}).");
        $this->warn('  Run after first deploy: Forge -> Recipes -> Run on server');
    }

    private function deploySite(int $serverId, int $siteId, string $domain, bool $dryRun): void
    {
        $this->info("[18/18] Deploying {$domain}...");

        if ($dryRun) {
            $this->line('  Would trigger deployment');

            return;
        }

        $this->forge->deploySite($serverId, $siteId, wait: false);
        $this->line('  Deployment triggered! Check Forge dashboard for progress.');
    }

    private function printSummary(string $domain, string $wsDomain, int $serverId): void
    {
        $serverIp = $this->state['server_ip'] ?? 'CHECK_FORGE_DASHBOARD';

        $this->info('');
        $this->info('=============================================');
        $this->info('  PROVISIONING COMPLETE!');
        $this->info('=============================================');
        $this->info('');
        $this->info('  Server IP:  '.$serverIp);
        $this->info('  Server ID:  '.$serverId);
        $this->info("  Main Site:  https://{$domain}");
        $this->info("  WebSocket:  https://{$wsDomain}");
        $this->info('');

        $this->warn('  NEXT STEPS:');
        $this->warn('');
        $this->warn('  1. Point DNS records to '.$serverIp.':');
        $this->warn("     A   @    -> {$serverIp}");
        $this->warn("     A   www  -> {$serverIp}");
        $this->warn("     A   ws   -> {$serverIp}");
        $this->warn('');
        $this->warn('  2. Wait for DNS propagation, then get SSL certs from Forge dashboard');
        $this->warn('');
        $this->warn('  3. Run the seeder recipe from Forge -> Recipes');
        $this->warn('     Recipe ID: '.($this->state['seeder_recipe_id'] ?? 'CHECK_FORGE'));
        $this->warn('');
        $this->warn('  4. Run the Python recipe from Forge -> Recipes');
        $this->warn('     Recipe ID: '.($this->state['python_recipe_id'] ?? 'CHECK_FORGE'));
        $this->warn('');
        $this->warn('  5. Update .env with real credentials:');
        $this->warn('     - STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET');
        $this->warn('     - MAILGUN_SECRET (and verify Mailgun domain)');
        $this->warn('     - INCREASE_CLIENT_ID, INCREASE_CLIENT_SECRET');
        $this->warn('     - PHOTOS_AWS_ACCESS_KEY_ID, PHOTOS_AWS_SECRET_ACCESS_KEY');
        $this->warn('');
        $this->warn('  6. Create your admin user (SSH into server):');
        $this->warn("     cd /home/forge/{$domain}");
        $this->warn('     php8.3 artisan tinker');
        $this->warn("     User::create(['name'=>'...', 'email'=>'...', 'password'=>bcrypt('...'), 'type'=>'internal', 'email_verified_at'=>now()]);");
        $this->warn('');

        if (isset($this->state['sudo_password'])) {
            $this->error('  SAVE THESE PASSWORDS (shown once):');
            $this->error('  Sudo:     '.$this->state['sudo_password']);
            $this->error('  Database: '.$this->state['database_password']);
        }

        $this->info('');
        $this->info('  State dump:');
        $this->line(json_encode($this->state, JSON_PRETTY_PRINT));
    }
}
