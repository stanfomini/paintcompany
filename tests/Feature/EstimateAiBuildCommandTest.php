<?php

use App\Models\AiBuildRequest;
use App\Models\Estimate;

it('builds the prompt with correct placeholders', function () {
    config([
        'ai-build.api_secret' => 'test-secret',
        'ai-build.ssh.key' => '/home/forge/.ssh/id_rsa',
        'ai-build.ssh.user' => 'forge',
        'ai-build.ssh.host' => 'paintingmadeeasydenver.com',
        'ai-build.ssh.prod_path' => '/home/forge/paintingmadeeasydenver.com',
        'ai-build.prod_server_url' => 'https://paintingmadeeasydenver.com',
    ]);

    // Create the prompt template for testing
    $promptDir = storage_path('app/ai-prompts');
    if (! is_dir($promptDir)) {
        mkdir($promptDir, 0755, true);
    }
    file_put_contents(
        "{$promptDir}/estimate-build.md",
        'Build estimate #{{ESTIMATE_ID}} via ssh -i {{SSH_KEY}} {{SSH_USER}}@{{SSH_HOST}} at {{PROD_PATH}}'
    );

    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'pending',
    ]);

    // Use reflection to test buildPrompt
    $command = new \App\Console\Commands\EstimateAiBuildCommand;
    $method = new \ReflectionMethod($command, 'buildPrompt');
    $prompt = $method->invoke($command, $estimate->id, $request->id);

    expect($prompt)->toContain("Build estimate #{$estimate->id}")
        ->toContain('forge@paintingmadeeasydenver.com')
        ->toContain('/home/forge/.ssh/id_rsa')
        ->not->toContain('{{ESTIMATE_ID}}');
});
