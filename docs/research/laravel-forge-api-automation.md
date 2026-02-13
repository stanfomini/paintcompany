# Research: Laravel Forge API -- Full Automation Capabilities

*Generated: 2026-02-13*

## Requirements

Determine what can be fully automated via the Laravel Forge API for server provisioning and management. Specifically:

1. Creating new DigitalOcean servers programmatically
2. Creating sites, configuring environment variables, daemons, queue workers, schedulers, SSL
3. Best approach: Forge SDK (PHP) vs Forge CLI vs direct API calls
4. Recipes and server cloning features

---

## CRITICAL: API Deprecation Warning

The current Forge API (v1) at `https://forge.laravel.com/api/v1` is **deprecated and will be discontinued on March 31, 2026**. The new Forge (launched October 2025) has a new performant, JSON:API-spec-compliant API. **Migrate to the new API before March 31, 2026.**

---

## 1. Can You Script Server Creation on DigitalOcean?

**Yes, fully.**

### Via the PHP SDK

```php
use Laravel\Forge\Forge;

$forge = new Forge('YOUR_API_TOKEN');

$server = $forge->createServer([
    'provider'      => ServerProviders::DIGITAL_OCEAN,
    'credential_id' => 1,               // Your linked DO credential ID
    'name'          => 'apex-production',
    'type'          => ServerTypes::APP,  // APP, WEB, WORKER, CACHE, DATABASE, LOADBALANCER
    'size'          => '01',             // DigitalOcean droplet size slug
    'database'      => 'apex_db',
    'database_type' => InstallableServices::POSTGRES, // or MYSQL, MARIADB
    'php_version'   => InstallableServices::PHP_84,
    'region'        => 'nyc3',           // DigitalOcean region
]);
```

### Via Direct API

```bash
curl -X POST https://forge.laravel.com/api/v1/servers \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "provider": "ocean2",
    "credential_id": 1,
    "name": "apex-production",
    "type": "app",
    "size": "01",
    "database": "apex_db",
    "database_type": "postgres",
    "php_version": "php84",
    "region": "nyc3"
  }'
```

### Important Notes

- Server creation **starts** the provisioning process. It takes several minutes.
- Poll `$server->isReady` or `GET /api/v1/servers/{id}` to check when provisioning completes.
- You need your DigitalOcean API token linked in Forge first (this linking is done once in the Forge UI under Server Providers).
- Available regions/sizes can be queried: `GET /api/v1/regions`
- Your linked credentials can be queried: `GET /api/v1/credentials`

---

## 2. Can You Script Sites, Env, Daemons, Workers, Schedulers, SSL?

**Yes, all of them.** Here is the complete list of automatable resources.

### 2a. Sites

```php
// Create a site
$site = $forge->createSite($serverId, [
    'domain'        => 'apexnegotiations.com',
    'project_type'  => 'php',
    'directory'     => '/public',
    'isolated'      => true,
    'php_version'   => 'php84',
]);

// Install Git repository
$forge->installGitRepositoryOnSite($serverId, $siteId, [
    'provider'   => 'github',
    'repository' => 'your-org/apexnegotiations.com',
    'branch'     => 'main',
    'composer'   => true,
]);

// Update deployment script
$forge->updateSiteDeploymentScript($serverId, $siteId,
    'cd /home/forge/apexnegotiations.com
     git pull origin main
     composer install --no-interaction --prefer-dist --optimize-autoloader
     php artisan migrate --force
     php artisan config:cache
     php artisan route:cache
     php artisan view:cache
     php artisan queue:restart'
);

// Enable quick deploy (auto-deploy on git push)
$forge->enableQuickDeploy($serverId, $siteId);

// Trigger manual deployment
$forge->deploySite($serverId, $siteId);
```

**API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/servers/{id}/sites` | Create site |
| GET | `/servers/{id}/sites` | List sites |
| GET | `/servers/{id}/sites/{siteId}` | Get site details |
| PUT | `/servers/{id}/sites/{siteId}` | Update site |
| DELETE | `/servers/{id}/sites/{siteId}` | Delete site |
| PUT | `/servers/{id}/sites/{siteId}/php` | Change PHP version |

### 2b. Environment Variables

```php
// Get the .env file contents
$env = $forge->siteEnvironmentFile($serverId, $siteId);

// Update .env file (full replacement)
$forge->updateSiteEnvironmentFile($serverId, $siteId, $envContent);
```

**API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/servers/{id}/sites/{siteId}/env` | Get .env content |
| PUT | `/servers/{id}/sites/{siteId}/env` | Update .env content (full file) |

**Note:** The API replaces the entire `.env` file content. There is no "set single variable" endpoint. You must GET, modify the string, then PUT back.

### 2c. Daemons (Background Processes)

```php
$forge->createDaemon($serverId, [
    'command'   => 'php /home/forge/apexnegotiations.com/artisan horizon',
    'user'      => 'forge',
    'directory' => '/home/forge/apexnegotiations.com',
]);
```

**API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/servers/{id}/daemons` | Create daemon |
| GET | `/servers/{id}/daemons` | List daemons |
| GET | `/servers/{id}/daemons/{daemonId}` | Get daemon |
| DELETE | `/servers/{id}/daemons/{daemonId}` | Delete daemon |
| POST | `/servers/{id}/daemons/{daemonId}/restart` | Restart daemon |

### 2d. Queue Workers

```php
$forge->createWorker($serverId, $siteId, [
    'connection'  => 'redis',       // or 'database', 'sqs'
    'queue'       => 'default',
    'php_version' => 'php84',
    'timeout'     => 60,
    'sleep'       => 3,
    'processes'   => 1,
    'daemon'      => true,          // Run as daemon (recommended)
]);
```

**API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/servers/{id}/sites/{siteId}/workers` | Create worker |
| GET | `/servers/{id}/sites/{siteId}/workers` | List workers |
| GET | `/servers/{id}/sites/{siteId}/workers/{workerId}` | Get worker |
| DELETE | `/servers/{id}/sites/{siteId}/workers/{workerId}` | Delete worker |
| POST | `/servers/{id}/sites/{siteId}/workers/{workerId}/restart` | Restart worker |

### 2e. Scheduled Jobs (Cron)

```php
$forge->createJob($serverId, [
    'command'   => 'php /home/forge/apexnegotiations.com/artisan schedule:run',
    'frequency' => 'minutely',   // minutely, hourly, nightly, weekly, monthly, custom
    'user'      => 'forge',
]);
```

**API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/servers/{id}/jobs` | Create scheduled job |
| GET | `/servers/{id}/jobs` | List jobs |
| GET | `/servers/{id}/jobs/{jobId}` | Get job |
| DELETE | `/servers/{id}/jobs/{jobId}` | Delete job |

### 2f. SSL Certificates

```php
// Let's Encrypt (most common)
$forge->obtainLetsEncryptCertificate($serverId, $siteId, [
    'domains' => ['apexnegotiations.com', 'www.apexnegotiations.com'],
]);

// Activate a certificate
$forge->activateCertificate($serverId, $siteId, $certificateId);
```

**API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/servers/{id}/sites/{siteId}/certificates` | Create cert (CSR) |
| POST | `/servers/{id}/sites/{siteId}/certificates/letsencrypt` | Let's Encrypt cert |
| GET | `/servers/{id}/sites/{siteId}/certificates` | List certificates |
| GET | `/servers/{id}/sites/{siteId}/certificates/{certId}` | Get certificate |
| POST | `/servers/{id}/sites/{siteId}/certificates/{certId}/activate` | Activate certificate |
| DELETE | `/servers/{id}/sites/{siteId}/certificates/{certId}` | Delete certificate |

### 2g. Databases

```php
$forge->createDatabase($serverId, [
    'name'     => 'apex_production',
    'user'     => 'apex_user',
    'password' => 'secure-password-here',
]);
```

**API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/servers/{id}/databases` | Create database |
| GET | `/servers/{id}/databases` | List databases |
| DELETE | `/servers/{id}/databases/{dbId}` | Delete database |
| POST | `/servers/{id}/database-users` | Create DB user |
| GET | `/servers/{id}/database-users` | List DB users |
| PUT | `/servers/{id}/database-users/{userId}` | Update DB user |
| DELETE | `/servers/{id}/database-users/{userId}` | Delete DB user |

### 2h. Firewall Rules

```php
$forge->createFirewallRule($serverId, [
    'name'       => 'Allow Office IP',
    'ip_address' => '203.0.113.50',
    'port'       => 22,
    'type'       => 'allow',
]);
```

### 2i. SSH Keys

```php
$forge->createSSHKey($serverId, [
    'name' => 'deploy-key',
    'key'  => file_get_contents('/path/to/id_rsa.pub'),
]);
```

### 2j. Nginx Configuration

```php
// Get current nginx config
$config = $forge->siteNginxFile($serverId, $siteId);

// Update nginx config
$forge->updateSiteNginxFile($serverId, $siteId, $newConfig);
```

### 2k. Services Management

The API provides endpoints to manage installed services:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/servers/{id}/mysql/reboot` | Reboot MySQL |
| POST | `/servers/{id}/nginx/reboot` | Reboot Nginx |
| POST | `/servers/{id}/postgres/reboot` | Reboot PostgreSQL |
| POST | `/servers/{id}/php/reboot` | Reboot PHP |
| DELETE | `/servers/{id}/nginx/stop` | Stop Nginx |

### 2l. Monitoring

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/servers/{id}/monitors` | Create monitor (disk, memory, CPU thresholds) |
| GET | `/servers/{id}/monitors` | List monitors |
| DELETE | `/servers/{id}/monitors/{monitorId}` | Delete monitor |

### 2m. Backups

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/servers/{id}/backup-configs` | Create backup config (to S3, etc.) |
| GET | `/servers/{id}/backup-configs` | List backup configs |
| PUT | `/servers/{id}/backup-configs/{configId}` | Update backup config |
| DELETE | `/servers/{id}/backup-configs/{configId}` | Delete backup config |
| POST | `/servers/{id}/backup-configs/{configId}` | Run backup manually |

---

## 3. Best Approach: SDK vs CLI vs Direct API

### Option 1: Laravel Forge PHP SDK (laravel/forge-sdk)

- **Version**: 3.x
- **Install**: `composer require laravel/forge-sdk`
- **GitHub Stars**: Well-maintained official package
- **Last Commit**: Active (MIT license)
- **Best For**: Integrating into Laravel apps, custom provisioning tools, internal dashboards
- **Pros**:
  - Official, maintained by Laravel team
  - Fluent PHP interface (no raw HTTP)
  - Automatic waiting/polling for site creation
  - Type-safe constants (ServerProviders, ServerTypes, InstallableServices)
  - Can build Artisan commands or internal tools with it
- **Cons**:
  - PHP only
  - Must track the API deprecation (new API may need SDK update)
  - 60 requests/minute rate limit applies
- **Recommendation**: Best choice for building a provisioning script within this Laravel app.

### Option 2: Forge CLI (laravel/forge-cli)

- **Install**: `composer global require laravel/forge-cli`
- **Best For**: Developer workflow, quick deployments, SSH access
- **Available Commands**:
  - `forge login` -- authenticate
  - `forge server:list` / `forge server:switch` -- manage active server
  - `forge ssh` -- SSH into server
  - `forge deploy` -- trigger deployment
  - `forge env:pull` / `forge env:push` -- sync .env files
  - `forge site:list` / `forge site:logs` -- site management
  - `forge command` -- run arbitrary commands on server
  - `forge tinker` -- remote Laravel Tinker
  - `forge {resource}:status` / `forge {resource}:logs` / `forge {resource}:restart`
  - `forge database:shell` -- database CLI access
- **Pros**:
  - Great for CI/CD pipelines and shell scripts
  - Interactive server/site selection
  - `env:pull`/`env:push` is very convenient
  - Real-time log streaming with `--follow`
- **Cons**:
  - **Cannot create servers** (no `server:create` command in the official CLI)
  - **Cannot create sites** (no `site:create` command)
  - Limited to operational tasks, not provisioning
  - Less scriptable than the SDK for complex workflows
- **Recommendation**: Use for day-to-day operations and CI/CD, but not for initial provisioning.

### Option 3: Direct API Calls (curl / HTTP)

- **Base URL**: `https://forge.laravel.com/api/v1`
- **Auth**: `Authorization: Bearer {token}`
- **Best For**: Shell scripts, non-PHP environments, one-off automation
- **Pros**:
  - Language-agnostic
  - Full access to every API endpoint
  - Can be used in bash scripts, GitHub Actions, etc.
- **Cons**:
  - Must handle JSON parsing, error handling, polling manually
  - No type safety or IDE support
  - More verbose than SDK
- **Recommendation**: Use for CI/CD in non-PHP contexts or quick bash scripts.

### Option 4: Forge MCP Server (AI-Assisted)

A community-maintained MCP server (OBSTechnologies/forge-mcp-server) provides **37 tools** for AI assistants to manage Forge. This allows Claude, Cursor, or other AI agents to:
- List/get/reboot servers
- Create/delete sites
- Deploy sites, manage deployment scripts
- Get/update .env files and nginx configs
- Manage SSL certificates, databases, workers, daemons, scheduled jobs
- Install/remove Git repositories

**Recommendation**: Useful for AI-assisted ops, but not a replacement for scripted automation.

### Verdict

**Use the PHP SDK (`laravel/forge-sdk`) for provisioning scripts.** It gives you full access to every API endpoint with a clean PHP interface. Supplement with the CLI for day-to-day operations.

---

## 4. Recipes and Server Cloning

### Recipes

Recipes are reusable Bash scripts that can be run across any of your servers.

**Creating via API:**
```php
$forge->createRecipe([
    'name'   => 'Install Redis',
    'user'   => 'root',
    'script' => 'apt-get install -y redis-server && systemctl enable redis-server',
]);
```

**Running on servers:**
```php
$forge->runRecipe($recipeId, [
    'servers' => [$serverId1, $serverId2],
    'notify'  => true,  // Email output when complete
]);
```

**API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/recipes` | Create recipe |
| GET | `/recipes` | List recipes |
| GET | `/recipes/{id}` | Get recipe |
| PUT | `/recipes/{id}` | Update recipe |
| DELETE | `/recipes/{id}` | Delete recipe |
| POST | `/recipes/{id}/run` | Run recipe on servers |

**Dynamic variables available in recipes:**
- `{{server_id}}`, `{{server_name}}` -- server identifiers
- `{{ip_address}}`, `{{private_ip_address}}` -- network info
- `{{username}}`, `{{server_type}}` -- execution context
- `{{db_password}}` -- database credentials

**Post-Provision Recipes:** When creating a server (in the UI), you can select a recipe to run automatically after provisioning completes. Forge will execute it and email you the output. This is useful for installing additional software not included in the default provisioning (e.g., Python, Redis, wkhtmltopdf, ffmpeg).

### Server Cloning

**There is no native server cloning feature in Laravel Forge.** This has been a frequently requested feature (open discussions on Laracasts and GitHub), but as of February 2026 it does not exist.

**Workaround -- Script a "clone" with the SDK:**

```php
// 1. Read config from existing server
$sourceServer = $forge->server($sourceServerId);
$sourceSites = $forge->sites($sourceServerId);
$sourceDaemons = $forge->daemons($sourceServerId);
$sourceJobs = $forge->jobs($sourceServerId);

// 2. Create new server with same specs
$newServer = $forge->createServer([...same config...]);
// Wait for provisioning...

// 3. Recreate each site
foreach ($sourceSites as $site) {
    $newSite = $forge->createSite($newServer->id, [...]);

    // Copy .env
    $env = $forge->siteEnvironmentFile($sourceServerId, $site->id);
    $forge->updateSiteEnvironmentFile($newServer->id, $newSite->id, $env);

    // Install same git repo
    $forge->installGitRepositoryOnSite($newServer->id, $newSite->id, [...]);

    // Copy deployment script
    $script = $forge->siteDeploymentScript($sourceServerId, $site->id);
    $forge->updateSiteDeploymentScript($newServer->id, $newSite->id, $script);

    // Recreate workers
    foreach ($forge->workers($sourceServerId, $site->id) as $worker) {
        $forge->createWorker($newServer->id, $newSite->id, [...]);
    }

    // SSL
    $forge->obtainLetsEncryptCertificate($newServer->id, $newSite->id, [
        'domains' => ['yourdomain.com'],
    ]);
}

// 4. Recreate daemons
foreach ($sourceDaemons as $daemon) {
    $forge->createDaemon($newServer->id, [...]);
}

// 5. Recreate scheduled jobs
foreach ($sourceJobs as $job) {
    $forge->createJob($newServer->id, [...]);
}
```

**Certificate cloning** has a dedicated API endpoint (added recently), allowing you to clone an SSL certificate from one site to another.

---

## 5. Complete Provisioning Script Example

Here is a full end-to-end example of scripting an entire server setup:

```php
<?php

use Laravel\Forge\Forge;

$forge = new Forge(config('services.forge.token'));

// --- Step 1: Create server ---
$server = $forge->createServer([
    'provider'      => 'ocean2',
    'credential_id' => config('services.forge.do_credential_id'),
    'name'          => 'apex-production',
    'type'          => 'app',
    'size'          => 's-2vcpu-4gb',
    'database'      => 'apex_production',
    'database_type' => 'postgres',
    'php_version'   => 'php84',
    'region'        => 'nyc3',
]);

// Wait for server to finish provisioning
while (! $forge->server($server->id)->isReady) {
    sleep(30);
}

$serverId = $server->id;

// --- Step 2: Create site ---
$site = $forge->createSite($serverId, [
    'domain'       => 'apexnegotiations.com',
    'project_type' => 'php',
    'directory'    => '/public',
    'isolated'     => true,
    'php_version'  => 'php84',
]);
$siteId = $site->id;

// --- Step 3: Install Git repository ---
$forge->installGitRepositoryOnSite($serverId, $siteId, [
    'provider'   => 'github',
    'repository' => 'your-org/apexnegotiations.com',
    'branch'     => 'main',
    'composer'   => true,
]);

// --- Step 4: Set deployment script ---
$forge->updateSiteDeploymentScript($serverId, $siteId,
    'cd /home/forge/apexnegotiations.com
git pull origin $FORGE_SITE_BRANCH
composer install --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
npm ci && npm run build'
);

// --- Step 5: Set environment variables ---
$env = file_get_contents('.env.production');
$forge->updateSiteEnvironmentFile($serverId, $siteId, $env);

// --- Step 6: Create database user ---
$forge->createDatabaseUser($serverId, [
    'name'      => 'apex_user',
    'password'  => 'your-secure-password',
    'databases' => [$server->databaseId], // ID from step 1
]);

// --- Step 7: SSL certificate ---
$cert = $forge->obtainLetsEncryptCertificate($serverId, $siteId, [
    'domains' => ['apexnegotiations.com', 'www.apexnegotiations.com'],
]);

// --- Step 8: Queue worker ---
$forge->createWorker($serverId, $siteId, [
    'connection'  => 'redis',
    'queue'       => 'default',
    'php_version' => 'php84',
    'timeout'     => 90,
    'sleep'       => 3,
    'processes'   => 2,
    'daemon'      => true,
]);

// --- Step 9: Laravel scheduler ---
$forge->createJob($serverId, [
    'command'   => 'php /home/forge/apexnegotiations.com/artisan schedule:run',
    'frequency' => 'minutely',
    'user'      => 'forge',
]);

// --- Step 10: Horizon daemon ---
$forge->createDaemon($serverId, [
    'command'   => 'php /home/forge/apexnegotiations.com/artisan horizon',
    'user'      => 'forge',
    'directory' => '/home/forge/apexnegotiations.com',
]);

// --- Step 11: Enable quick deploy ---
$forge->enableQuickDeploy($serverId, $siteId);

// --- Step 12: Firewall rules ---
$forge->createFirewallRule($serverId, [
    'name' => 'Allow HTTP',
    'port' => 80,
    'type' => 'allow',
]);
$forge->createFirewallRule($serverId, [
    'name' => 'Allow HTTPS',
    'port' => 443,
    'type' => 'allow',
]);

// --- Step 13: Deploy ---
$forge->deploySite($serverId, $siteId);

echo "Server provisioned and site deployed successfully!\n";
```

---

## 6. Limitations -- What MUST Be Done in the UI

| Task | API Support | Notes |
|------|-------------|-------|
| **Link cloud provider credentials** | UI only | Must add your DigitalOcean API token in Forge Settings > Server Providers first. One-time setup. |
| **Server cloning** | Not available | Must script manually (see section 4). |
| **Zero-downtime deployments** | Must be set at site creation | Cannot be enabled retroactively on existing sites (new Forge limitation). |
| **Organization/team creation** | UI only | Organizations and team management are configured through the dashboard. |
| **Custom roles (Business plan)** | UI only | Fine-grained RBAC is configured in the dashboard. |
| **Laravel VPS provisioning** | Partial | Laravel VPS is a new provider; API support may differ from traditional providers. |
| **Billing and plan management** | UI only | Subscription changes must be done through the dashboard. |
| **Post-provision recipe selection** | UI only | Selecting a recipe to run after server provisioning is a UI feature; you can work around this by running a recipe via API after detecting server readiness. |
| **Server monitoring setup (metrics)** | API available | Monitors can be created via API, but some visualization is UI-only. |

---

## 7. Rate Limiting

- **Limit**: 60 requests per minute per authenticated user
- **Response Headers**: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
- **Recommendation**: Add delays between calls in provisioning scripts. The full provisioning script above makes roughly 15-20 API calls, well within limits.

---

## 8. Recommendation Summary

| Approach | Use Case |
|----------|----------|
| **PHP SDK** (`laravel/forge-sdk`) | Building provisioning Artisan commands, internal tools, automated scaling |
| **Forge CLI** (`laravel/forge-cli`) | Developer workflow, CI/CD deployments, env sync, SSH, logs |
| **Direct API** (curl/HTTP) | GitHub Actions, shell scripts, non-PHP CI/CD |
| **MCP Server** | AI-assisted server management (Claude, Cursor) |

**Primary recommendation**: Install `laravel/forge-sdk` and build an Artisan command (e.g., `php artisan forge:provision`) that scripts the entire server + site + workers + daemons + SSL setup. Use the CLI for daily operations.

```bash
composer require laravel/forge-sdk
```

---

## 9. New Forge (October 2025) Changes

The new Forge introduced significant changes:
- **Laravel VPS**: Instant provisioning (under 10 seconds) starting at $6/month
- **Zero-downtime deployments**: Enabled by default for new sites
- **Integrated terminal**: Real-time SSH collaboration
- **Processes tab**: Daemons renamed to "Processes", now managed per-site
- **Organizations/Teams**: Replaces "Circles" for access control
- **Heartbeats & Health Checks**: Monitor scheduled jobs and deployments
- **New API**: Performant, paginated, JSON:API-spec compliant
- **Command palette**: Quick navigation with Cmd+K
- **Free Forge domain**: Testing URLs on `.on-forge.com`
