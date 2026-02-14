# AI Estimate Builder Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** A button in the estimate template builder triggers Claude Code on the dev server to automatically read blueprints/photos/notes and build out estimate areas, surfaces, and packages on the production server.

**Architecture:** Production app sends HTTP POST webhook to dev server (apexnegotiations.com). Dev server's artisan command spawns Claude Code CLI in a tmux session. Claude Code SSHes into production to read data and create estimate records. Callback webhook notifies production when done, broadcasting via Reverb for real-time UI updates.

**Tech Stack:** Laravel 12, Livewire 4, Claude Code CLI, tmux, SSH, Reverb broadcasting, pdfplumber (Python)

**Design Doc:** `docs/plans/2026-02-14-ai-estimate-builder-design.md`

---

## Task 1: Migration + Model for AI Build Requests

**Files:**
- Create: `database/migrations/2026_02_15_000001_create_ai_build_requests_table.php`
- Create: `app/Models/AiBuildRequest.php`
- Test: `tests/Feature/AiBuildRequestTest.php`

**Step 1: Create migration**

```bash
php artisan make:migration create_ai_build_requests_table --no-interaction
```

Migration content:

```php
Schema::create('ai_build_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
    $table->string('status')->default('pending'); // pending, building, completed, nothing_to_build, unreadable_blueprints, failed, partial
    $table->unsignedInteger('areas_created')->default(0);
    $table->unsignedInteger('surfaces_created')->default(0);
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->string('log_path')->nullable();
    $table->text('error_message')->nullable();
    $table->text('summary')->nullable(); // Human-readable summary of what was built
    $table->timestamp('callback_sent_at')->nullable();
    $table->timestamps();

    $table->index(['estimate_id', 'status']);
});
```

**Step 2: Create model**

```bash
php artisan make:class app/Models/AiBuildRequest --no-interaction
```

Model content:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiBuildRequest extends Model
{
    protected $fillable = [
        'estimate_id', 'status', 'areas_created', 'surfaces_created',
        'started_at', 'completed_at', 'log_path', 'error_message',
        'summary', 'callback_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'callback_sent_at' => 'datetime',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'building']);
    }

    public function markBuilding(): void
    {
        $this->update(['status' => 'building', 'started_at' => now()]);
    }

    public function markCompleted(int $areas, int $surfaces, string $summary): void
    {
        $this->update([
            'status' => 'completed',
            'areas_created' => $areas,
            'surfaces_created' => $surfaces,
            'summary' => $summary,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $status, string $message): void
    {
        $this->update([
            'status' => $status,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
```

**Step 3: Write test**

```php
<?php

use App\Models\AiBuildRequest;
use App\Models\Estimate;

it('creates an ai build request for an estimate', function () {
    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'pending',
    ]);

    expect($request->estimate->id)->toBe($estimate->id);
    expect($request->isActive())->toBeTrue();
});

it('tracks build lifecycle', function () {
    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'pending',
    ]);

    $request->markBuilding();
    expect($request->fresh()->status)->toBe('building');
    expect($request->fresh()->started_at)->not->toBeNull();

    $request->markCompleted(25, 89, 'Built 25 areas with 89 surfaces');
    expect($request->fresh()->status)->toBe('completed');
    expect($request->fresh()->areas_created)->toBe(25);
    expect($request->fresh()->surfaces_created)->toBe(89);
});

it('marks failures with status and message', function () {
    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'building',
    ]);

    $request->markFailed('nothing_to_build', 'No blueprints, photos, or notes found.');
    expect($request->fresh()->status)->toBe('nothing_to_build');
    expect($request->fresh()->isActive())->toBeFalse();
});
```

**Step 4: Run migration and tests**

```bash
php artisan migrate --no-interaction
php artisan test --compact tests/Feature/AiBuildRequestTest.php
```

**Step 5: Commit**

```bash
git add -A && git commit -m "feat: add ai_build_requests table and model for tracking AI estimate builds"
```

---

## Task 2: Config + Environment Variables

**Files:**
- Create: `config/ai-build.php`
- Modify: `.env.example`

**Step 1: Create config file**

```php
<?php

return [
    'enabled' => env('AI_BUILD_ENABLED', false),
    'api_secret' => env('AI_BUILD_SECRET'),
    'dev_server_url' => env('AI_BUILD_DEV_URL', 'https://apexnegotiations.com'),
    'prod_server_url' => env('AI_BUILD_PROD_URL', 'https://paintingmadeeasydenver.com'),
    'ssh' => [
        'key' => env('AI_BUILD_SSH_KEY', '/home/forge/.ssh/id_rsa'),
        'user' => env('AI_BUILD_SSH_USER', 'forge'),
        'host' => env('AI_BUILD_SSH_HOST', 'paintingmadeeasydenver.com'),
        'prod_path' => env('AI_BUILD_PROD_PATH', '/home/forge/paintingmadeeasydenver.com'),
    ],
    'claude' => [
        'binary' => env('AI_BUILD_CLAUDE_BIN', '/home/forge/.local/bin/claude'),
        'working_dir' => env('AI_BUILD_WORKING_DIR', '/home/forge/apexnegotiations.com'),
    ],
    'timeout_minutes' => env('AI_BUILD_TIMEOUT', 10),
];
```

**Step 2: Add env vars to `.env.example`**

Add these lines:

```
AI_BUILD_ENABLED=false
AI_BUILD_SECRET=
AI_BUILD_DEV_URL=https://apexnegotiations.com
AI_BUILD_PROD_URL=https://paintingmadeeasydenver.com
AI_BUILD_SSH_KEY=/home/forge/.ssh/id_rsa
AI_BUILD_SSH_USER=forge
AI_BUILD_SSH_HOST=paintingmadeeasydenver.com
AI_BUILD_PROD_PATH=/home/forge/paintingmadeeasydenver.com
AI_BUILD_CLAUDE_BIN=/home/forge/.local/bin/claude
AI_BUILD_WORKING_DIR=/home/forge/apexnegotiations.com
AI_BUILD_TIMEOUT=10
```

**Step 3: Add actual env vars to `.env` on dev server**

Generate a secret:

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Add to `.env`:

```
AI_BUILD_ENABLED=true
AI_BUILD_SECRET=<generated-secret>
```

**Step 4: Commit**

```bash
git add config/ai-build.php .env.example && git commit -m "feat: add ai-build config for Claude Code automation pipeline"
```

---

## Task 3: Webhook Endpoint on Dev Server (Receives Trigger)

**Files:**
- Create: `app/Http/Controllers/Api/AiBuildWebhookController.php`
- Modify: `routes/web.php` (add webhook route alongside existing webhooks)
- Test: `tests/Feature/AiBuildWebhookTest.php`

**Step 1: Create controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiBuildRequest;
use App\Models\Estimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class AiBuildWebhookController extends Controller
{
    public function trigger(Request $request): JsonResponse
    {
        // Validate API secret
        if ($request->header('X-AI-Build-Secret') !== config('ai-build.api_secret')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! config('ai-build.enabled')) {
            return response()->json(['error' => 'AI Build is not enabled'], 503);
        }

        $validated = $request->validate([
            'estimate_id' => 'required|integer',
        ]);

        $estimateId = $validated['estimate_id'];

        // Check for active builds
        $activeRequest = AiBuildRequest::where('estimate_id', $estimateId)
            ->whereIn('status', ['pending', 'building'])
            ->first();

        if ($activeRequest) {
            return response()->json([
                'error' => 'A build is already in progress for this estimate',
                'request_id' => $activeRequest->id,
                'status' => $activeRequest->status,
            ], 409);
        }

        // Create build request
        $buildRequest = AiBuildRequest::create([
            'estimate_id' => $estimateId,
            'status' => 'pending',
        ]);

        // Dispatch artisan command in background
        Artisan::call('estimate:ai-build', [
            'estimateId' => $estimateId,
            'requestId' => $buildRequest->id,
        ]);

        Log::info("AI Build triggered for estimate #{$estimateId}", [
            'request_id' => $buildRequest->id,
        ]);

        return response()->json([
            'status' => 'building',
            'request_id' => $buildRequest->id,
        ]);
    }

    public function status(Request $request, int $requestId): JsonResponse
    {
        if ($request->header('X-AI-Build-Secret') !== config('ai-build.api_secret')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $buildRequest = AiBuildRequest::findOrFail($requestId);

        return response()->json([
            'status' => $buildRequest->status,
            'areas_created' => $buildRequest->areas_created,
            'surfaces_created' => $buildRequest->surfaces_created,
            'summary' => $buildRequest->summary,
            'error_message' => $buildRequest->error_message,
            'started_at' => $buildRequest->started_at?->toISOString(),
            'completed_at' => $buildRequest->completed_at?->toISOString(),
        ]);
    }
}
```

**Step 2: Add routes to `routes/web.php`**

Add alongside existing webhook routes (around line 300):

```php
// AI Build webhook (no auth - verified by API secret header)
Route::post('/webhooks/ai-build', [App\Http\Controllers\Api\AiBuildWebhookController::class, 'trigger'])
    ->name('webhooks.ai-build');
Route::get('/webhooks/ai-build/{requestId}', [App\Http\Controllers\Api\AiBuildWebhookController::class, 'status'])
    ->name('webhooks.ai-build.status');
```

**Step 3: Write test**

```php
<?php

use App\Models\AiBuildRequest;
use App\Models\Estimate;

beforeEach(function () {
    config(['ai-build.enabled' => true]);
    config(['ai-build.api_secret' => 'test-secret-123']);
});

it('rejects requests without valid api secret', function () {
    $this->postJson('/webhooks/ai-build', ['estimate_id' => 1])
        ->assertUnauthorized();

    $this->postJson('/webhooks/ai-build', ['estimate_id' => 1], [
        'X-AI-Build-Secret' => 'wrong-secret',
    ])->assertUnauthorized();
});

it('rejects requests when ai build is disabled', function () {
    config(['ai-build.enabled' => false]);

    $this->postJson('/webhooks/ai-build', ['estimate_id' => 1], [
        'X-AI-Build-Secret' => 'test-secret-123',
    ])->assertStatus(503);
});

it('creates a build request and returns building status', function () {
    $estimate = Estimate::factory()->create();

    // Mock Artisan::call to prevent actual command execution
    Artisan::shouldReceive('call')
        ->once()
        ->with('estimate:ai-build', \Mockery::type('array'));

    $response = $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], [
        'X-AI-Build-Secret' => 'test-secret-123',
    ]);

    $response->assertOk()
        ->assertJson(['status' => 'building']);

    expect(AiBuildRequest::where('estimate_id', $estimate->id)->exists())->toBeTrue();
});

it('prevents duplicate active builds', function () {
    $estimate = Estimate::factory()->create();
    AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'building',
    ]);

    $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], [
        'X-AI-Build-Secret' => 'test-secret-123',
    ])->assertStatus(409);
});

it('returns build request status', function () {
    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'completed',
        'areas_created' => 25,
        'surfaces_created' => 89,
        'summary' => 'Built 25 areas',
    ]);

    $this->getJson("/webhooks/ai-build/{$request->id}", [
        'X-AI-Build-Secret' => 'test-secret-123',
    ])->assertOk()
        ->assertJson([
            'status' => 'completed',
            'areas_created' => 25,
            'surfaces_created' => 89,
        ]);
});
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/AiBuildWebhookTest.php
```

**Step 5: Commit**

```bash
git add -A && git commit -m "feat: add AI build webhook endpoint with secret validation and status tracking"
```

---

## Task 4: Callback Endpoint on Production (Receives Completion)

**Files:**
- Create: `app/Http/Controllers/Api/AiBuildCallbackController.php`
- Create: `app/Events/AiBuildCompleted.php`
- Modify: `routes/web.php` (add callback route)
- Modify: `routes/channels.php` (add estimate.ai-build channel if needed)
- Test: `tests/Feature/AiBuildCallbackTest.php`

**Step 1: Create broadcast event**

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AiBuildCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $estimateId,
        public string $status,
        public int $areasCreated = 0,
        public int $surfacesCreated = 0,
        public ?string $summary = null,
        public ?string $errorMessage = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('estimate.'.$this->estimateId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.build.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'estimate_id' => $this->estimateId,
            'status' => $this->status,
            'areas_created' => $this->areasCreated,
            'surfaces_created' => $this->surfacesCreated,
            'summary' => $this->summary,
            'error_message' => $this->errorMessage,
        ];
    }
}
```

**Step 2: Create callback controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Events\AiBuildCompleted;
use App\Http\Controllers\Controller;
use App\Models\AiBuildRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiBuildCallbackController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if ($request->header('X-AI-Build-Secret') !== config('ai-build.api_secret')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'estimate_id' => 'required|integer',
            'request_id' => 'required|integer',
            'status' => 'required|string|in:completed,nothing_to_build,unreadable_blueprints,failed,partial',
            'areas_created' => 'integer',
            'surfaces_created' => 'integer',
            'summary' => 'nullable|string',
            'error_message' => 'nullable|string',
        ]);

        // Update build request record
        $buildRequest = AiBuildRequest::find($validated['request_id']);
        if ($buildRequest) {
            if (in_array($validated['status'], ['completed', 'partial'])) {
                $buildRequest->markCompleted(
                    $validated['areas_created'] ?? 0,
                    $validated['surfaces_created'] ?? 0,
                    $validated['summary'] ?? '',
                );
            } else {
                $buildRequest->markFailed(
                    $validated['status'],
                    $validated['error_message'] ?? 'Unknown error',
                );
            }
            $buildRequest->update(['callback_sent_at' => now()]);
        }

        // Broadcast to connected clients via Reverb
        AiBuildCompleted::dispatch(
            estimateId: $validated['estimate_id'],
            status: $validated['status'],
            areasCreated: $validated['areas_created'] ?? 0,
            surfacesCreated: $validated['surfaces_created'] ?? 0,
            summary: $validated['summary'] ?? null,
            errorMessage: $validated['error_message'] ?? null,
        );

        Log::info("AI Build callback received for estimate #{$validated['estimate_id']}", $validated);

        return response()->json(['status' => 'ok']);
    }
}
```

**Step 3: Add callback route to `routes/web.php`**

```php
// AI Build callback (receives completion from dev server)
Route::post('/webhooks/ai-build-callback', [App\Http\Controllers\Api\AiBuildCallbackController::class, 'handle'])
    ->name('webhooks.ai-build-callback');
```

**Step 4: Write test**

```php
<?php

use App\Events\AiBuildCompleted;
use App\Models\AiBuildRequest;
use App\Models\Estimate;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config(['ai-build.api_secret' => 'test-secret-123']);
});

it('rejects callback without valid secret', function () {
    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => 1,
        'request_id' => 1,
        'status' => 'completed',
    ])->assertUnauthorized();
});

it('handles completed build callback and broadcasts event', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'building',
    ]);

    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $request->id,
        'status' => 'completed',
        'areas_created' => 25,
        'surfaces_created' => 89,
        'summary' => 'Built 25 areas with 89 surfaces from blueprints.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    expect($request->fresh()->status)->toBe('completed');
    expect($request->fresh()->areas_created)->toBe(25);

    Event::assertDispatched(AiBuildCompleted::class, function ($event) use ($estimate) {
        return $event->estimateId === $estimate->id
            && $event->status === 'completed'
            && $event->areasCreated === 25;
    });
});

it('handles failure callback', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'building',
    ]);

    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $request->id,
        'status' => 'nothing_to_build',
        'error_message' => 'No blueprints, photos, or notes found.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    expect($request->fresh()->status)->toBe('nothing_to_build');
    Event::assertDispatched(AiBuildCompleted::class);
});
```

**Step 5: Run tests**

```bash
php artisan test --compact tests/Feature/AiBuildCallbackTest.php
```

**Step 6: Commit**

```bash
git add -A && git commit -m "feat: add AI build callback endpoint with Reverb broadcasting"
```

---

## Task 5: Claude Code Prompt Template

**Files:**
- Create: `storage/app/ai-prompts/estimate-build.md`

**Step 1: Create the prompt template**

This is the prompt that gets sent to Claude Code. It contains placeholders that the artisan command fills in.

```markdown
You are an AI estimate builder for a painting company. Your job is to read blueprints, photos, and notes for estimate #{{ESTIMATE_ID}} and build out all the areas, surfaces, and packages.

## Connection Details

SSH into production: `ssh -i {{SSH_KEY}} {{SSH_USER}}@{{SSH_HOST}}`
Production app path: `{{PROD_PATH}}`
Callback URL: `{{CALLBACK_URL}}`
Callback secret: `{{API_SECRET}}`
Build request ID: `{{REQUEST_ID}}`

## Instructions

Use the blueprint-estimator skill (`/home/forge/apexnegotiations.com/.claude/skills/blueprint-estimator/SKILL.md`) as your guide. Follow ALL phases.

### Phase 0: Triage - Check What's Available

SSH into production and query what materials exist:

```bash
ssh -i {{SSH_KEY}} {{SSH_USER}}@{{SSH_HOST}} "cd {{PROD_PATH}} && php artisan tinker --execute=\"
\\\$estimate = App\\\\Models\\\\Estimate::find({{ESTIMATE_ID}});
\\\$deal = \\\$estimate->deal;
\\\$property = \\\$deal->property;
\\\$photos = App\\\\Models\\\\PropertyPhoto::where('property_id', \\\$property->id);
\\\$pdfs = \\\$photos->clone()->where('mime_type', 'application/pdf')->count();
\\\$images = \\\$photos->clone()->where('mime_type', 'like', 'image/%')->count();
\\\$videos = \\\$photos->clone()->where('mime_type', 'like', 'video/%')->count();
echo json_encode([
    'estimate_id' => \\\$estimate->id,
    'status' => \\\$estimate->status,
    'notes' => \\\$estimate->notes,
    'customer_notes' => \\\$estimate->customer_notes,
    'project_type' => \\\$estimate->project_type,
    'scope_type' => \\\$estimate->scope_type,
    'address' => \\\$property->address,
    'city' => \\\$property->city,
    'state' => \\\$property->state,
    'year_built' => \\\$property->year_built,
    'square_footage' => \\\$property->square_footage,
    'customer_name' => \\\$deal->customer?->name,
    'pdf_count' => \\\$pdfs,
    'image_count' => \\\$images,
    'video_count' => \\\$videos,
    'has_notes' => !empty(\\\$estimate->notes) || !empty(\\\$estimate->customer_notes),
    'existing_areas' => \\\$estimate->areas()->count(),
]);
\""
```

**Decision logic:**
- If `pdf_count == 0 AND image_count == 0 AND video_count == 0 AND has_notes == false`: Send callback with status `nothing_to_build` and exit.
- If `existing_areas > 0`: The estimate already has areas. Send callback with status `failed` and message "Estimate already has areas built. Clear them first to rebuild." and exit.
- If PDFs exist: Try to extract text. If extraction fails completely: Send callback with status `unreadable_blueprints` and exit.
- Otherwise: Proceed with whatever materials are available.

### Phase 1: Gather Data

Follow the blueprint-estimator skill Phase 1. For each step, SSH into production to query data.

To download a PDF for local processing:
```bash
scp -i {{SSH_KEY}} {{SSH_USER}}@{{SSH_HOST}}:{{PROD_PATH}}/storage/app/public/{file_path} /tmp/blueprint-{{ESTIMATE_ID}}.pdf
```

### Phase 2-7: Build the Estimate

Follow the blueprint-estimator skill Phases 2-7. All database writes go through SSH + artisan tinker on production.

### Phase 8: Generate Packages (Save Draft equivalent)

After all areas and surfaces are created, generate packages:

```bash
ssh -i {{SSH_KEY}} {{SSH_USER}}@{{SSH_HOST}} "cd {{PROD_PATH}} && php artisan tinker --execute=\"
\\\$estimate = App\\\\Models\\\\Estimate::find({{ESTIMATE_ID}});
\\\$generator = app(App\\\\Services\\\\EstimatePackageGeneratorService::class);
\\\$generator->regenerateFromTemplates(\\\$estimate);
\\\$estimate->calculateTotals();
echo 'Packages generated. Total: \$' . number_format(\\\$estimate->total, 2);
\""
```

### Phase 9: Send Callback

When done (or on any failure), send the callback:

```bash
curl -X POST "{{CALLBACK_URL}}" \
  -H "Content-Type: application/json" \
  -H "X-AI-Build-Secret: {{API_SECRET}}" \
  -d '{
    "estimate_id": {{ESTIMATE_ID}},
    "request_id": {{REQUEST_ID}},
    "status": "completed",
    "areas_created": <count>,
    "surfaces_created": <count>,
    "summary": "Built X areas with Y surfaces from blueprints."
  }'
```

For failures, use the appropriate status: `nothing_to_build`, `unreadable_blueprints`, `failed`, or `partial`.

**IMPORTANT**: Always send the callback, even on failure. The production app is waiting for it.
```

**Step 2: Commit**

```bash
git add storage/app/ai-prompts/estimate-build.md && git commit -m "feat: add Claude Code prompt template for AI estimate builder"
```

---

## Task 6: Artisan Command (Spawns Claude Code)

**Files:**
- Create: `app/Console/Commands/EstimateAiBuildCommand.php`
- Test: `tests/Feature/EstimateAiBuildCommandTest.php`

**Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\AiBuildRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class EstimateAiBuildCommand extends Command
{
    protected $signature = 'estimate:ai-build {estimateId} {requestId}';

    protected $description = 'Spawn Claude Code to automatically build an estimate from blueprints and photos';

    public function handle(): int
    {
        $estimateId = $this->argument('estimateId');
        $requestId = $this->argument('requestId');

        $buildRequest = AiBuildRequest::find($requestId);
        if (! $buildRequest) {
            $this->error("Build request #{$requestId} not found.");
            return self::FAILURE;
        }

        $buildRequest->markBuilding();

        // Generate the prompt from template
        $prompt = $this->buildPrompt($estimateId, $requestId);

        // Create log directory
        $logDir = storage_path('logs/ai-builds');
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = "{$logDir}/{$estimateId}-" . now()->format('Y-m-d-His') . '.log';
        $buildRequest->update(['log_path' => $logFile]);

        $claudeBin = config('ai-build.claude.binary');
        $workingDir = config('ai-build.claude.working_dir');
        $timeout = config('ai-build.timeout_minutes') * 60;
        $tmuxSession = "ai-build-{$estimateId}";

        // Kill any existing tmux session for this estimate
        Process::run("tmux kill-session -t {$tmuxSession} 2>/dev/null");

        // Write prompt to temp file (avoids shell escaping issues)
        $promptFile = "/tmp/ai-build-prompt-{$estimateId}.md";
        file_put_contents($promptFile, $prompt);

        // Spawn Claude Code in a tmux session
        $command = sprintf(
            'tmux new-session -d -s %s "cd %s && %s --dangerously-skip-permissions -p %s 2>&1 | tee %s; sleep 5"',
            escapeshellarg($tmuxSession),
            escapeshellarg($workingDir),
            escapeshellarg($claudeBin),
            escapeshellarg("$(cat {$promptFile})"),
            escapeshellarg($logFile),
        );

        $this->info("Starting AI build for estimate #{$estimateId}...");
        $this->info("tmux session: {$tmuxSession}");
        $this->info("Log file: {$logFile}");
        $this->info("Attach with: tmux attach -t {$tmuxSession}");

        Log::info("AI Build starting for estimate #{$estimateId}", [
            'request_id' => $requestId,
            'tmux_session' => $tmuxSession,
            'log_file' => $logFile,
        ]);

        Process::run($command);

        return self::SUCCESS;
    }

    protected function buildPrompt(int $estimateId, int $requestId): string
    {
        $templatePath = storage_path('app/ai-prompts/estimate-build.md');

        if (! file_exists($templatePath)) {
            throw new \RuntimeException("Prompt template not found at {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        $callbackUrl = config('ai-build.prod_server_url') . '/webhooks/ai-build-callback';

        return str_replace([
            '{{ESTIMATE_ID}}',
            '{{REQUEST_ID}}',
            '{{SSH_KEY}}',
            '{{SSH_USER}}',
            '{{SSH_HOST}}',
            '{{PROD_PATH}}',
            '{{CALLBACK_URL}}',
            '{{API_SECRET}}',
        ], [
            $estimateId,
            $requestId,
            config('ai-build.ssh.key'),
            config('ai-build.ssh.user'),
            config('ai-build.ssh.host'),
            config('ai-build.ssh.prod_path'),
            $callbackUrl,
            config('ai-build.api_secret'),
        ], $template);
    }
}
```

**Step 2: Write test**

```php
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
    $command = new \App\Console\Commands\EstimateAiBuildCommand();
    $method = new \ReflectionMethod($command, 'buildPrompt');
    $prompt = $method->invoke($command, $estimate->id, $request->id);

    expect($prompt)->toContain("Build estimate #{$estimate->id}")
        ->toContain('forge@paintingmadeeasydenver.com')
        ->toContain('/home/forge/.ssh/id_rsa')
        ->not->toContain('{{ESTIMATE_ID}}');
});
```

**Step 3: Run test**

```bash
php artisan test --compact tests/Feature/EstimateAiBuildCommandTest.php
```

**Step 4: Commit**

```bash
git add -A && git commit -m "feat: add estimate:ai-build artisan command that spawns Claude Code in tmux"
```

---

## Task 7: AI Build Button in Template Builder UI

**Files:**
- Modify: `resources/views/components/estimates/⚡template-builder.blade.php`
- Test: `tests/Feature/Livewire/Estimates/TemplateBuilderAiBuildTest.php`

**Step 1: Add properties and method to the Livewire component PHP section**

Add these properties after the existing public properties (around line 80):

```php
public bool $aiBuildInProgress = false;
public ?string $aiBuildStatus = null;
public ?string $aiBuildMessage = null;
```

Add this method before `saveAsDraft()` (around line 2525):

```php
public function triggerAiBuild(): void
{
    if (! config('ai-build.enabled')) {
        $this->aiBuildMessage = 'AI Build is not currently available.';
        return;
    }

    if (! $this->estimate) {
        return;
    }

    // Check if estimate already has areas
    if ($this->estimate->areas()->count() > 0) {
        $this->aiBuildMessage = 'This estimate already has areas. Clear them before using AI Build.';
        return;
    }

    $this->aiBuildInProgress = true;
    $this->aiBuildStatus = 'sending';
    $this->aiBuildMessage = null;

    try {
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'X-AI-Build-Secret' => config('ai-build.api_secret'),
        ])->post(config('ai-build.dev_server_url') . '/webhooks/ai-build', [
            'estimate_id' => $this->estimate->id,
        ]);

        if ($response->successful()) {
            $this->aiBuildStatus = 'building';
            $this->aiBuildMessage = 'AI is building your estimate. This may take a few minutes...';
        } else {
            $this->aiBuildInProgress = false;
            $this->aiBuildStatus = 'failed';
            $this->aiBuildMessage = $response->json('error', 'Failed to start AI build.');
        }
    } catch (\Exception $e) {
        $this->aiBuildInProgress = false;
        $this->aiBuildStatus = 'failed';
        $this->aiBuildMessage = 'Could not connect to AI build service.';
    }
}
```

Add a listener for the Reverb broadcast. Add to `getListeners()` if it exists, or add this method:

```php
#[\Livewire\Attributes\On('echo-private:estimate.{estimate.id},ai.build.completed')]
public function onAiBuildCompleted(array $data): void
{
    $this->aiBuildInProgress = false;
    $this->aiBuildStatus = $data['status'];

    $this->aiBuildMessage = match ($data['status']) {
        'completed' => "AI built {$data['areas_created']} areas with {$data['surfaces_created']} surfaces! Refreshing...",
        'partial' => "AI built {$data['areas_created']} areas but had some issues. Please review.",
        'nothing_to_build' => 'No blueprints, photos, or notes found. Upload materials first.',
        'unreadable_blueprints' => "Couldn't extract text from the blueprints. They may need manual entry.",
        'failed' => $data['error_message'] ?? 'AI build encountered an error.',
        default => 'Build finished with unknown status.',
    };

    if (in_array($data['status'], ['completed', 'partial'])) {
        // Reload the page to show new areas
        $this->redirect(route('estimates.edit', $this->estimate), navigate: true);
    }
}
```

**Step 2: Add AI Build button to the template**

Add before the "Save as Draft" button in the desktop actions section (around line 3472):

```blade
{{-- AI Build Button --}}
@if (config('ai-build.enabled') && $isEditing && count($areas) === 0)
    <div class="mb-4 p-3 bg-gradient-to-r from-purple-50 to-blue-50 dark:from-purple-950/30 dark:to-blue-950/30 rounded-lg border border-purple-200 dark:border-purple-800">
        @if ($aiBuildInProgress)
            <div class="flex items-center gap-2 text-sm text-purple-700 dark:text-purple-300">
                <flux:icon name="arrow-path" class="w-4 h-4 animate-spin" />
                <span>{{ $aiBuildMessage ?? 'AI is working...' }}</span>
            </div>
        @elseif ($aiBuildMessage)
            <div class="text-sm mb-2 {{ $aiBuildStatus === 'failed' || str_contains($aiBuildStatus ?? '', 'nothing') ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                {{ $aiBuildMessage }}
            </div>
            <flux:button wire:click="triggerAiBuild" size="sm" class="w-full" variant="primary">
                <flux:icon name="sparkles" class="w-4 h-4 mr-1" />
                {{ __('Retry AI Build') }}
            </flux:button>
        @else
            <flux:button wire:click="triggerAiBuild" wire:loading.attr="disabled" size="sm" class="w-full" variant="primary">
                <flux:icon name="sparkles" class="w-4 h-4 mr-1" />
                <span wire:loading.remove wire:target="triggerAiBuild">{{ __('AI Build from Blueprints') }}</span>
                <span wire:loading wire:target="triggerAiBuild">{{ __('Starting...') }}</span>
            </flux:button>
            <p class="text-xs text-purple-600 dark:text-purple-400 mt-1 text-center">
                {{ __('Reads blueprints & photos to auto-build estimate') }}
            </p>
        @endif
    </div>
@endif
```

**Step 3: Write test**

```php
<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config(['ai-build.enabled' => true]);
    config(['ai-build.api_secret' => 'test-secret']);
    config(['ai-build.dev_server_url' => 'https://apexnegotiations.com']);
});

it('shows AI build button when estimate has no areas', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->for($deal)->create();

    $this->actingAs($user)
        ->get(route('estimates.edit', $estimate))
        ->assertSee('AI Build from Blueprints');
});

it('hides AI build button when estimate has areas', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->for($deal)->create();
    $estimate->areas()->create([
        'name' => 'Test Area',
        'scope_type' => 'interior',
    ]);

    $this->actingAs($user)
        ->get(route('estimates.edit', $estimate))
        ->assertDontSee('AI Build from Blueprints');
});

it('sends webhook to dev server when AI build is triggered', function () {
    Http::fake([
        'apexnegotiations.com/*' => Http::response(['status' => 'building', 'request_id' => 1]),
    ]);

    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->for($deal)->create();

    Livewire::actingAs($user)
        ->test('estimates.template-builder', ['estimate' => $estimate])
        ->call('triggerAiBuild')
        ->assertSet('aiBuildInProgress', true)
        ->assertSet('aiBuildStatus', 'building');

    Http::assertSent(function ($request) use ($estimate) {
        return $request->url() === 'https://apexnegotiations.com/webhooks/ai-build'
            && $request['estimate_id'] === $estimate->id;
    });
});
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/TemplateBuilderAiBuildTest.php
```

**Step 5: Commit**

```bash
git add -A && git commit -m "feat: add AI Build button to estimate template builder with Reverb status updates"
```

---

## Task 8: End-to-End Integration Test

**Files:**
- Test: `tests/Feature/AiBuildIntegrationTest.php`

**Step 1: Write integration test**

Test the full flow: trigger -> callback -> broadcast, without actually spawning Claude Code.

```php
<?php

use App\Events\AiBuildCompleted;
use App\Models\AiBuildRequest;
use App\Models\Estimate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config(['ai-build.enabled' => true]);
    config(['ai-build.api_secret' => 'test-secret-123']);
});

it('completes full trigger-to-callback flow', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();

    // 1. Trigger the build
    Artisan::shouldReceive('call')->once();

    $triggerResponse = $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], ['X-AI-Build-Secret' => 'test-secret-123']);

    $triggerResponse->assertOk();
    $requestId = $triggerResponse->json('request_id');

    // 2. Verify build request was created
    $buildRequest = AiBuildRequest::find($requestId);
    expect($buildRequest)->not->toBeNull();
    expect($buildRequest->status)->toBe('pending');

    // 3. Simulate callback from Claude Code
    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $requestId,
        'status' => 'completed',
        'areas_created' => 15,
        'surfaces_created' => 60,
        'summary' => 'Built 15 areas with 60 surfaces from blueprint PDF.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    // 4. Verify build request updated
    expect($buildRequest->fresh()->status)->toBe('completed');
    expect($buildRequest->fresh()->areas_created)->toBe(15);

    // 5. Verify broadcast sent
    Event::assertDispatched(AiBuildCompleted::class, function ($event) use ($estimate) {
        return $event->estimateId === $estimate->id
            && $event->status === 'completed';
    });
});

it('handles nothing-to-build gracefully', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();
    Artisan::shouldReceive('call')->once();

    $triggerResponse = $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], ['X-AI-Build-Secret' => 'test-secret-123']);

    $requestId = $triggerResponse->json('request_id');

    // Simulate failure callback
    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $requestId,
        'status' => 'nothing_to_build',
        'error_message' => 'No blueprints, photos, or notes found.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    $buildRequest = AiBuildRequest::find($requestId);
    expect($buildRequest->status)->toBe('nothing_to_build');
    expect($buildRequest->error_message)->toContain('No blueprints');

    Event::assertDispatched(AiBuildCompleted::class, function ($event) {
        return $event->status === 'nothing_to_build';
    });
});
```

**Step 2: Run tests**

```bash
php artisan test --compact tests/Feature/AiBuildIntegrationTest.php
```

**Step 3: Run full test suite**

```bash
php artisan test --compact
```

**Step 4: Commit**

```bash
git add -A && git commit -m "test: add end-to-end integration tests for AI estimate builder pipeline"
```

---

## Task 9: Deploy and Configure Production

**Step 1: Set env vars on dev server `.env`**

```bash
# Generate secret
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Add to dev server `.env`:
```
AI_BUILD_ENABLED=true
AI_BUILD_SECRET=<generated-secret>
```

**Step 2: Push to repo and deploy via Forge**

```bash
git push origin feature/ai-estimate-builder
```

Deploy to production via Laravel Forge.

**Step 3: Set env vars on production server**

SSH into production and add same env vars:
```
AI_BUILD_ENABLED=true
AI_BUILD_SECRET=<same-secret-as-dev>
```

**Step 4: Run migration on production**

```bash
ssh -i /home/forge/.ssh/id_rsa forge@paintingmadeeasydenver.com "cd /home/forge/paintingmadeeasydenver.com && php artisan migrate --no-interaction"
```

**Step 5: Test the full pipeline**

1. Go to an estimate on production with blueprints uploaded
2. Click "AI Build from Blueprints"
3. On dev server, verify tmux session: `tmux list-sessions`
4. Attach to watch: `tmux attach -t ai-build-{estimateId}`
5. Wait for completion notification in the browser
6. Review built areas in the template builder

---

## Summary

| Task | Description | Key Files |
|------|-------------|-----------|
| 1 | Migration + Model | `ai_build_requests` table, `AiBuildRequest` model |
| 2 | Config + Env vars | `config/ai-build.php` |
| 3 | Webhook endpoint (dev receives) | `AiBuildWebhookController` |
| 4 | Callback endpoint (prod receives) | `AiBuildCallbackController`, `AiBuildCompleted` event |
| 5 | Claude Code prompt template | `storage/app/ai-prompts/estimate-build.md` |
| 6 | Artisan command (spawns Claude) | `EstimateAiBuildCommand` |
| 7 | UI button + Reverb listener | `⚡template-builder.blade.php` |
| 8 | Integration tests | End-to-end flow tests |
| 9 | Deploy + configure | Env vars, migration, manual test |
