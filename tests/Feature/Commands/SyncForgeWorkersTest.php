<?php

beforeEach(function () {
    config()->set('services.forge.token', 'test-token');
    config()->set('forge-workers.server_id', 123);
    config()->set('forge-workers.site_id', 456);
    config()->set('forge-workers.defaults', [
        'connection' => 'redis',
        'processes' => 1,
        'timeout' => 60,
        'sleep' => 10,
        'tries' => 3,
        'force' => false,
        'php_version' => 'php83',
    ]);
});

it('fails without a forge api token', function () {
    config()->set('services.forge.token', null);

    $this->artisan('forge:sync-workers')
        ->expectsOutputToContain('Forge API token required')
        ->assertExitCode(1);
});

it('fails without server and site ids', function () {
    config()->set('forge-workers.server_id', null);
    config()->set('forge-workers.site_id', null);

    $this->artisan('forge:sync-workers')
        ->expectsOutputToContain('Server ID and Site ID required')
        ->assertExitCode(1);
});

it('reports no workers when config is empty', function () {
    config()->set('forge-workers.workers', []);

    $this->artisan('forge:sync-workers', ['--dry-run' => true])
        ->expectsOutputToContain('No workers defined')
        ->assertExitCode(0);
});

it('loads worker config from config file', function () {
    $workers = config('forge-workers.workers');

    expect($workers)->toBeArray()
        ->and(collect($workers)->pluck('queue')->toArray())
        ->toContain('default')
        ->toContain('ai')
        ->toContain('notifications')
        ->toContain('slack');
});

it('has correct ai worker settings', function () {
    $workers = collect(config('forge-workers.workers'));
    $ai = $workers->firstWhere('queue', 'ai');

    expect($ai)->not->toBeNull()
        ->and($ai['processes'])->toBe(3)
        ->and($ai['timeout'])->toBe(310)
        ->and($ai['sleep'])->toBe(3);
});

it('has correct slack worker settings', function () {
    $workers = collect(config('forge-workers.workers'));
    $slack = $workers->firstWhere('queue', 'slack');

    expect($slack)->not->toBeNull()
        ->and($slack['processes'])->toBe(4)
        ->and($slack['sleep'])->toBe(8);
});

it('has correct defaults', function () {
    $defaults = config('forge-workers.defaults');

    expect($defaults['connection'])->toBe('redis')
        ->and($defaults['timeout'])->toBe(60)
        ->and($defaults['tries'])->toBe(3)
        ->and($defaults['php_version'])->toBe('php83');
});

it('accepts token server and site via options', function () {
    config()->set('forge-workers.workers', []);

    $this->artisan('forge:sync-workers', [
        '--token' => 'override-token',
        '--server' => '999',
        '--site' => '888',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('No workers defined')
        ->assertExitCode(0);
});

it('defines all expected queues', function () {
    $workers = collect(config('forge-workers.workers'));
    $queues = $workers->pluck('queue')->toArray();

    expect($queues)->toContain('default')
        ->toContain('ai')
        ->toContain('notifications')
        ->toContain('broadcasts')
        ->toContain('webhooks')
        ->toContain('slack')
        ->toContain('meilisearch')
        ->toContain('meilisearch_customer');
});

it('has server and site id config keys', function () {
    expect(config('forge-workers.server_id'))->toBe(123)
        ->and(config('forge-workers.site_id'))->toBe(456);
});
