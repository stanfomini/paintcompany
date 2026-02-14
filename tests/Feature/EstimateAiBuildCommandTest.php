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

    // Use a temp directory so we don't overwrite the real prompt template
    $tempDir = sys_get_temp_dir().'/ai-prompts-test-'.uniqid();
    mkdir($tempDir, 0755, true);
    file_put_contents(
        "{$tempDir}/estimate-build.md",
        'Build estimate #{{ESTIMATE_ID}} via ssh -i {{SSH_KEY}} {{SSH_USER}}@{{SSH_HOST}} at {{PROD_PATH}}'
    );

    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'pending',
    ]);

    // Override the template path via a partial mock
    $command = new \App\Console\Commands\EstimateAiBuildCommand;
    $method = new \ReflectionMethod($command, 'buildPrompt');

    // Temporarily swap the storage path
    $originalPath = storage_path('app/ai-prompts/estimate-build.md');
    app()->instance('ai-build-test-prompt-dir', $tempDir);

    // We need to test buildPrompt, but it reads from storage_path. We'll use the real file.
    // Instead, just verify it reads the real template and replaces placeholders correctly.
    $prompt = $method->invoke($command, $estimate->id, $request->id);

    expect($prompt)->toContain("estimate #{$estimate->id}")
        ->toContain('forge@paintingmadeeasydenver.com')
        ->toContain('/home/forge/.ssh/id_rsa')
        ->not->toContain('{{ESTIMATE_ID}}')
        ->not->toContain('{{SSH_KEY}}')
        ->not->toContain('{{SSH_USER}}')
        ->not->toContain('{{SSH_HOST}}')
        ->not->toContain('{{PROD_PATH}}');

    // Cleanup temp directory
    @unlink("{$tempDir}/estimate-build.md");
    @rmdir($tempDir);
});
