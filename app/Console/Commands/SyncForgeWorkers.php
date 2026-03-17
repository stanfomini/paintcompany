<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Forge\Forge;
use Laravel\Forge\Resources\Worker;

class SyncForgeWorkers extends Command
{
    protected $signature = 'forge:sync-workers
        {--token= : Forge API token (or set FORGE_API_TOKEN in .env)}
        {--server= : Forge server ID (or set FORGE_SERVER_ID in .env)}
        {--site= : Forge site ID (or set FORGE_SITE_ID in .env)}
        {--prune : Remove workers on Forge that are not in config}
        {--dry-run : Show what would be done without executing}
        {--restart : Restart workers whose config has changed}';

    protected $description = 'Sync queue workers to Forge from config/forge-workers.php';

    public function handle(): int
    {
        $token = $this->option('token') ?: config('services.forge.token');
        $serverId = (int) ($this->option('server') ?: config('forge-workers.server_id'));
        $siteId = (int) ($this->option('site') ?: config('forge-workers.site_id'));
        $dryRun = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune');
        $restart = (bool) $this->option('restart');

        if (! $token) {
            $this->error('Forge API token required. Use --token=xxx or set FORGE_API_TOKEN in .env');

            return self::FAILURE;
        }

        if (! $serverId || ! $siteId) {
            $this->error('Server ID and Site ID required. Use --server/--site or set FORGE_SERVER_ID/FORGE_SITE_ID in .env');

            return self::FAILURE;
        }

        $forge = new Forge($token);
        $desiredWorkers = config('forge-workers.workers', []);
        $defaults = config('forge-workers.defaults', []);

        if (empty($desiredWorkers)) {
            $this->warn('No workers defined in config/forge-workers.php');

            return self::SUCCESS;
        }

        $this->info('');
        $this->info('Forge Queue Worker Sync');
        $this->info('======================');
        $this->info("Server: {$serverId} | Site: {$siteId}");
        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made');
        }
        $this->info('');

        // Fetch current workers from Forge
        $existingWorkers = $forge->workers($serverId, $siteId);
        $existingByQueue = collect($existingWorkers)->keyBy('queue');

        $created = 0;
        $skipped = 0;
        $restarted = 0;
        $matchedQueues = [];

        foreach ($desiredWorkers as $desired) {
            $queue = $desired['queue'];
            $matchedQueues[] = $queue;
            $merged = array_merge($defaults, $desired);

            if ($existingByQueue->has($queue)) {
                $existing = $existingByQueue->get($queue);

                // Check if config differs
                $differs = $this->workerDiffers($existing, $merged);

                if ($differs && $restart) {
                    if ($dryRun) {
                        $this->line("  <comment>Would recreate:</comment> {$queue} (config changed)");
                    } else {
                        $forge->deleteWorker($serverId, $siteId, $existing->id);
                        $forge->createWorker($serverId, $siteId, $this->buildWorkerPayload($merged), false);
                        $this->line("  <info>Recreated:</info> {$queue}");
                    }
                    $restarted++;
                } else {
                    $label = $differs ? ' (config differs — use --restart to update)' : '';
                    $this->line("  <fg=gray>Exists:</> {$queue}{$label}");
                    $skipped++;
                }
            } else {
                if ($dryRun) {
                    $this->line("  <info>Would create:</info> {$queue} ({$merged['processes']} processes, {$merged['timeout']}s timeout)");
                } else {
                    $forge->createWorker($serverId, $siteId, $this->buildWorkerPayload($merged), false);
                    $this->line("  <info>Created:</info> {$queue} ({$merged['processes']} processes, {$merged['timeout']}s timeout)");
                }
                $created++;
            }
        }

        // Prune workers not in config
        $pruned = 0;
        if ($prune) {
            foreach ($existingWorkers as $existing) {
                if (! in_array($existing->queue, $matchedQueues)) {
                    if ($dryRun) {
                        $this->line("  <fg=red>Would remove:</> {$existing->queue} (not in config)");
                    } else {
                        $forge->deleteWorker($serverId, $siteId, $existing->id);
                        $this->line("  <fg=red>Removed:</> {$existing->queue} (not in config)");
                    }
                    $pruned++;
                }
            }
        }

        $this->info('');
        $this->info("Done: {$created} created, {$skipped} unchanged, {$restarted} recreated".($prune ? ", {$pruned} pruned" : ''));

        return self::SUCCESS;
    }

    /**
     * Check if an existing Forge worker differs from the desired config.
     */
    protected function workerDiffers(Worker $existing, array $desired): bool
    {
        return $existing->timeout != ($desired['timeout'] ?? 60)
            || $existing->sleep != ($desired['sleep'] ?? 10)
            || $existing->tries != ($desired['tries'] ?? 3);
        // Note: processes (numprocs) is not exposed on the Worker resource,
        // so we can't compare it. Recreate manually if processes change.
    }

    /**
     * Build the payload for Forge createWorker API call.
     */
    protected function buildWorkerPayload(array $config): array
    {
        return [
            'connection' => $config['connection'] ?? 'redis',
            'queue' => $config['queue'],
            'processes' => $config['processes'] ?? 1,
            'timeout' => $config['timeout'] ?? 60,
            'sleep' => $config['sleep'] ?? 10,
            'tries' => $config['tries'] ?? 3,
            'php_version' => $config['php_version'] ?? 'php83',
            'daemon' => true,
            'force' => $config['force'] ?? false,
        ];
    }
}
