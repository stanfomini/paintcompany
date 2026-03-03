<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Services\StudioAiService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

it('can be instantiated with a user', function () {
    $user = User::factory()->internal()->create();
    $service = new StudioAiService($user);

    expect($service)->toBeInstanceOf(StudioAiService::class);
});

it('has all 8 tools registered', function () {
    $user = User::factory()->internal()->create();
    $service = new StudioAiService($user);
    $tools = $service->getRegisteredTools();

    expect($tools)->toHaveCount(8)
        ->and($tools)->toContain('search_deals')
        ->and($tools)->toContain('get_deal_media')
        ->and($tools)->toContain('get_job_photos')
        ->and($tools)->toContain('get_estimate_photos')
        ->and($tools)->toContain('get_property_photos')
        ->and($tools)->toContain('create_video_project')
        ->and($tools)->toContain('add_clip_to_project')
        ->and($tools)->toContain('add_text_overlay');
});

it('builds tool definitions array with 8 Prism tools', function () {
    $user = User::factory()->internal()->create();
    $service = new StudioAiService($user);
    $tools = $service->getToolDefinitions();

    expect($tools)->toHaveCount(8);

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(\Prism\Prism\Tool::class)
            ->and($tool->name())->not->toBeEmpty()
            ->and($tool->description())->not->toBeEmpty();
    }
});

it('tool definitions have correct names', function () {
    $user = User::factory()->internal()->create();
    $service = new StudioAiService($user);
    $tools = $service->getToolDefinitions();

    $names = array_map(fn ($tool) => $tool->name(), $tools);

    expect($names)->toEqualCanonicalizing($service->getRegisteredTools());
});

it('returns assist edit response structure', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $service = new StudioAiService($user);

    $result = $service->assistEdit($project, 'Add captions to all clips');

    expect($result)->toBeArray()
        ->and($result['action'])->toBe('assist')
        ->and($result['instruction'])->toBe('Add captions to all clips')
        ->and($result['project_id'])->toBe($project->id)
        ->and($result['status'])->toBe('pending');
});

it('generates a video project from prompt using Prism fake', function () {
    $user = User::factory()->internal()->create();

    Prism::fake([
        new TextResponse(
            steps: collect([]),
            text: 'I created a video project for you.',
            finishReason: \Prism\Prism\Enums\FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(100, 200),
            meta: new Meta('fake-id', 'claude-sonnet-4-20250514'),
            messages: collect([]),
        ),
    ]);

    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'creation_mode' => 'ai_generated',
    ]);

    $service = new StudioAiService($user);
    $result = $service->generateFromPrompt('Create a before/after video for the Johnson deal');

    expect($result)->toBeInstanceOf(VideoProject::class)
        ->and($result->creation_mode)->toBe('ai_generated')
        ->and($result->user_id)->toBe($user->id);
});

it('runs agent and returns text response', function () {
    $user = User::factory()->internal()->create();

    $fakeResponse = new TextResponse(
        steps: collect([]),
        text: 'Here is the project I created.',
        finishReason: \Prism\Prism\Enums\FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(50, 100),
        meta: new Meta('fake-id', 'claude-sonnet-4-20250514'),
        messages: collect([]),
    );

    Prism::fake([$fakeResponse]);

    $service = new StudioAiService($user);
    $response = $service->runAgent('Make a TikTok from the Smith exterior job');

    expect($response)->toBeInstanceOf(TextResponse::class)
        ->and($response->text)->toBe('Here is the project I created.');
});
