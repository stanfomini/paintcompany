<?php

use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\PythonBridgeService;
use App\Services\StudioAiService;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

it('handles caption instruction as shortcut', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(false);
    });

    $service = new StudioAiService($user);
    $result = $service->assistEdit($project, 'Add captions to this video');

    expect($result['action'])->toBe('captions_added')
        ->and($result['count'])->toBe(0)
        ->and($result['message'])->toBe('No audio clips found to transcribe.');
});

it('handles subtitle instruction as caption shortcut', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(false);
    });

    $service = new StudioAiService($user);
    $result = $service->assistEdit($project, 'Add subtitles please');

    expect($result['action'])->toBe('captions_added');
});

it('is case insensitive for caption shortcut', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(false);
    });

    $service = new StudioAiService($user);
    $result = $service->assistEdit($project, 'GENERATE CAPTIONS');

    expect($result['action'])->toBe('captions_added');
});

it('uses Claude for non-shortcut instructions', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    Prism::fake([
        new TextResponse(
            steps: collect([]),
            text: '{"action": "suggestion", "message": "Try adding a fade transition between clips."}',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(50, 100),
            meta: new Meta('fake-id', 'claude-haiku-4-5-20251001'),
            messages: collect([]),
        ),
    ]);

    $service = new StudioAiService($user);
    $result = $service->assistEdit($project, 'Make it more dynamic');

    expect($result['action'])->toBe('suggestion')
        ->and($result['message'])->toBe('Try adding a fade transition between clips.');
});

it('parses add_text action from Claude', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    Prism::fake([
        new TextResponse(
            steps: collect([]),
            text: '{"action": "add_text", "content": "Call us today!", "position": "bottom-center"}',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(50, 100),
            meta: new Meta('fake-id', 'claude-haiku-4-5-20251001'),
            messages: collect([]),
        ),
    ]);

    $service = new StudioAiService($user);
    $result = $service->assistEdit($project, 'Add a call to action');

    expect($result['action'])->toBe('add_text')
        ->and($result['content'])->toBe('Call us today!')
        ->and($result['position'])->toBe('bottom-center');
});

it('returns suggestion when Claude response is not valid JSON', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    Prism::fake([
        new TextResponse(
            steps: collect([]),
            text: 'I think you should try a different approach.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(50, 100),
            meta: new Meta('fake-id', 'claude-haiku-4-5-20251001'),
            messages: collect([]),
        ),
    ]);

    $service = new StudioAiService($user);
    $result = $service->assistEdit($project, 'Help me improve this');

    expect($result['action'])->toBe('suggestion')
        ->and($result['message'])->toBe('I think you should try a different approach.');
});

it('returns suggestion when JSON has no action key', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    Prism::fake([
        new TextResponse(
            steps: collect([]),
            text: '{"some_key": "some_value"}',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(50, 100),
            meta: new Meta('fake-id', 'claude-haiku-4-5-20251001'),
            messages: collect([]),
        ),
    ]);

    $service = new StudioAiService($user);
    $result = $service->assistEdit($project, 'Do something');

    expect($result['action'])->toBe('suggestion')
        ->and($result['message'])->toBe('{"some_key": "some_value"}');
});

it('handles empty Claude response as suggestion', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    // Prism::fake with no responses returns default empty TextResponse
    Prism::fake();

    $service = new StudioAiService($user);
    $result = $service->assistEdit($project, 'Do something complex');

    expect($result['action'])->toBe('suggestion')
        ->and($result['message'])->toBe('');
});

it('builds project context with scene and clip info', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'title' => 'My Test Video',
        'aspect_ratio' => '9:16',
    ]);
    $scene = $project->scenes()->create(['sort_order' => 0, 'name' => 'Opening', 'layout' => 'solo']);
    $photo = PropertyPhoto::factory()->create(['caption' => 'Kitchen wall']);
    $scene->clips()->create([
        'slot' => 'main',
        'source_type' => PropertyPhoto::class,
        'source_id' => $photo->id,
        'sort_order' => 0,
    ]);
    $scene->overlays()->create([
        'type' => 'text',
        'content' => 'Apex Painting',
        'sort_order' => 0,
    ]);

    $service = new StudioAiService($user);
    $reflection = new ReflectionMethod($service, 'buildProjectContext');
    $context = $reflection->invoke($service, $project);

    expect($context)->toContain('My Test Video')
        ->and($context)->toContain('9:16')
        ->and($context)->toContain('Opening')
        ->and($context)->toContain('PropertyPhoto')
        ->and($context)->toContain('Kitchen wall')
        ->and($context)->toContain('Clips: 1')
        ->and($context)->toContain('Overlays: 1')
        ->and($context)->toContain('[text] Apex Painting');
});
