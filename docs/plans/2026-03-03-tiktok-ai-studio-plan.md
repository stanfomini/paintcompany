# TikTok-Style AI Studio — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Rebuild the video studio as a TikTok-style editor with AI prompt integration, multi-segment recording, deal-centric media browsing, and smart templates.

**Architecture:** Livewire 4 SFC components with Alpine.js for real-time interactions. Laravel AI SDK with Claude for prompt-based generation and editing assists. Existing VideoProject data model extended with creation_mode, aspect_ratio, ai_prompt fields. Python service pipeline reused for video processing and Whisper transcription.

**Tech Stack:** Laravel 12, Livewire 4, Alpine.js, Laravel AI SDK (Prism), Claude Sonnet/Haiku, MediaRecorder API, Canvas API, Tailwind CSS 4, Pest 4

**Design Doc:** `docs/plans/2026-03-03-tiktok-ai-studio-design.md`

---

## Phase 1: Foundation

### Task 1: Database Migration — Extend VideoProject

**Files:**
- Create: `database/migrations/2026_03_03_100000_add_studio_fields_to_video_projects_table.php`

**Step 1: Create the migration**

```bash
php artisan make:migration add_studio_fields_to_video_projects_table --no-interaction
```

**Step 2: Write the migration**

```php
public function up(): void
{
    Schema::table('video_projects', function (Blueprint $table) {
        $table->string('creation_mode')->default('classic')->after('status');
        $table->string('aspect_ratio')->default('16:9')->after('creation_mode');
        $table->text('ai_prompt')->nullable()->after('aspect_ratio');
        $table->foreignId('template_id')->nullable()->after('ai_prompt')
            ->constrained('studio_templates')->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('video_projects', function (Blueprint $table) {
        $table->dropForeign(['template_id']);
        $table->dropColumn(['creation_mode', 'aspect_ratio', 'ai_prompt', 'template_id']);
    });
}
```

**Step 3: Run the migration**

```bash
php artisan migrate
```

Note: The `template_id` foreign key references `studio_templates` which is created in Task 2. Run Task 2's migration first OR remove the foreign key constraint and add it later. Safest approach: create both migrations with timestamps that ensure studio_templates is created first.

**Step 4: Update VideoProject model**

Modify: `app/Models/VideoProject.php`

Add to `$fillable`:
```php
'creation_mode', 'aspect_ratio', 'ai_prompt', 'template_id'
```

Add to `casts()`:
```php
'creation_mode' => 'string',
'aspect_ratio' => 'string',
```

Add relationship:
```php
public function template(): BelongsTo
{
    return $this->belongsTo(StudioTemplate::class, 'template_id');
}
```

Add helper methods:
```php
public function isQuickMode(): bool { return $this->creation_mode === 'quick'; }
public function isAiGenerated(): bool { return $this->creation_mode === 'ai_generated'; }
public function isTemplateMode(): bool { return $this->creation_mode === 'template'; }
```

**Step 5: Commit**

```bash
git add -A && git commit -m "feat(studio): add creation_mode, aspect_ratio, ai_prompt fields to video_projects"
```

---

### Task 2: StudioTemplate Model & Migration

**Files:**
- Create: `database/migrations/2026_03_03_099000_create_studio_templates_table.php`
- Create: `app/Models/StudioTemplate.php`
- Create: `database/factories/StudioTemplateFactory.php`
- Create: `database/seeders/StudioTemplateSeeder.php`
- Test: `tests/Feature/Studio/StudioTemplateTest.php`

**Step 1: Create the migration (timestamp before Task 1's migration)**

```bash
php artisan make:migration create_studio_templates_table --no-interaction
```

Rename file to ensure it runs before the video_projects migration.

```php
public function up(): void
{
    Schema::create('studio_templates', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('category'); // before_after, walkthrough, testimonial, estimate_tour, team_highlight, promo
        $table->string('thumbnail_path')->nullable();
        $table->json('structure_json'); // skeleton: segments, auto-fill targets, overlay positions
        $table->text('system_prompt')->nullable(); // AI instructions for filling this template
        $table->boolean('is_active')->default(true);
        $table->integer('sort_order')->default(0);
        $table->timestamps();
    });
}
```

**Step 2: Create the model**

```bash
php artisan make:model StudioTemplate --factory --no-interaction
```

```php
// app/Models/StudioTemplate.php
class StudioTemplate extends Model
{
    protected $fillable = [
        'name', 'description', 'category', 'thumbnail_path',
        'structure_json', 'system_prompt', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'structure_json' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function videoProjects(): HasMany
    {
        return $this->hasMany(VideoProject::class, 'template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
```

**Step 3: Create the factory**

```php
// database/factories/StudioTemplateFactory.php
class StudioTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement([
                'before_after', 'walkthrough', 'testimonial',
                'estimate_tour', 'team_highlight', 'promo',
            ]),
            'structure_json' => [
                'segments' => [
                    ['type' => 'media', 'label' => 'Opening shot', 'auto_fill' => 'first_photo'],
                    ['type' => 'recording', 'label' => 'Your commentary'],
                    ['type' => 'media', 'label' => 'Closing shot', 'auto_fill' => 'last_photo'],
                ],
                'overlays' => [
                    ['type' => 'text', 'position' => 'bottom', 'auto_fill' => 'customer_name'],
                ],
            ],
            'system_prompt' => 'Create a professional painting company video.',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function beforeAfter(): static
    {
        return $this->state(fn () => ['category' => 'before_after']);
    }
}
```

**Step 4: Create the seeder with default templates**

```php
// database/seeders/StudioTemplateSeeder.php
class StudioTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Before & After',
                'description' => 'Show the transformation with before and after photos from a job',
                'category' => 'before_after',
                'sort_order' => 1,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'media', 'label' => 'Before photo', 'auto_fill' => 'before_photos', 'duration_ms' => 3000],
                        ['type' => 'recording', 'label' => 'Your commentary', 'duration_ms' => null],
                        ['type' => 'media', 'label' => 'After photo', 'auto_fill' => 'after_photos', 'duration_ms' => 3000],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'content' => 'Before', 'position' => 'top-left', 'segment_index' => 0],
                        ['type' => 'text', 'content' => 'After', 'position' => 'top-left', 'segment_index' => 2],
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'bottom'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create a before/after transformation video. Pull before-category photos first, then after-category photos. Add text overlays labeling each section.',
            ],
            [
                'name' => 'Job Walkthrough',
                'description' => 'Walk through a completed job with property photos and your narration',
                'category' => 'walkthrough',
                'sort_order' => 2,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'recording', 'label' => 'Intro (you talking)', 'duration_ms' => null],
                        ['type' => 'media', 'label' => 'Property photos', 'auto_fill' => 'all_photos', 'duration_ms' => 2500],
                        ['type' => 'recording', 'label' => 'Outro (call to action)', 'duration_ms' => null],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'auto_fill' => 'customer_name', 'position' => 'bottom'],
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'top-right'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create a job walkthrough video. Show the property from multiple angles. Include the customer name and company branding.',
            ],
            [
                'name' => 'Customer Testimonial',
                'description' => 'Feature a customer review with job photos in the background',
                'category' => 'testimonial',
                'sort_order' => 3,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'recording', 'label' => 'Customer speaking', 'duration_ms' => null, 'pip_background' => 'after_photos'],
                        ['type' => 'media', 'label' => 'Final result photos', 'auto_fill' => 'after_photos', 'duration_ms' => 3000],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'auto_fill' => 'customer_name', 'position' => 'bottom'],
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'top-right'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create a testimonial video. The customer speaks on camera with job photos playing behind them in picture-in-picture mode.',
            ],
            [
                'name' => 'Quick Estimate Tour',
                'description' => 'Walk a prospect through estimate photos with your sales pitch',
                'category' => 'estimate_tour',
                'sort_order' => 4,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'recording', 'label' => 'Intro (greet customer)', 'duration_ms' => null],
                        ['type' => 'media', 'label' => 'Estimate photos', 'auto_fill' => 'estimate_photos', 'duration_ms' => 3000],
                        ['type' => 'recording', 'label' => 'Walkthrough narration', 'duration_ms' => null, 'pip_background' => 'estimate_photos'],
                        ['type' => 'recording', 'label' => 'Close (call to action)', 'duration_ms' => null],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'auto_fill' => 'estimate_total', 'position' => 'bottom'],
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'top-right'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create an estimate walkthrough video. Show the property photos from the estimate, narrate the scope of work, and present the price.',
            ],
            [
                'name' => 'Team Highlight',
                'description' => 'Showcase your crew and recent completed work',
                'category' => 'team_highlight',
                'sort_order' => 5,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'recording', 'label' => 'Introduce your team', 'duration_ms' => null],
                        ['type' => 'media', 'label' => 'Recent job photos', 'auto_fill' => 'recent_completions', 'duration_ms' => 2500],
                        ['type' => 'recording', 'label' => 'Why choose us', 'duration_ms' => null],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'bottom'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create a team highlight video. Feature the painting crew and showcase recent completed jobs with before/after comparisons.',
            ],
            [
                'name' => 'Quick Promo',
                'description' => 'Fast promotional clip for social media',
                'category' => 'promo',
                'sort_order' => 6,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'media', 'label' => 'Hero shot', 'auto_fill' => 'best_photo', 'duration_ms' => 2000],
                        ['type' => 'recording', 'label' => 'Your pitch (15 sec max)', 'duration_ms' => null],
                        ['type' => 'media', 'label' => 'Portfolio shots', 'auto_fill' => 'recent_completions', 'duration_ms' => 2000],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'center'],
                        ['type' => 'text', 'auto_fill' => 'company_phone', 'position' => 'bottom'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create a short promotional video for social media. Keep it under 30 seconds. Feature the best work and include contact information.',
            ],
        ];

        foreach ($templates as $template) {
            StudioTemplate::updateOrCreate(
                ['name' => $template['name']],
                $template
            );
        }
    }
}
```

**Step 5: Write the test**

```php
// tests/Feature/Studio/StudioTemplateTest.php
it('creates a studio template', function () {
    $template = StudioTemplate::factory()->beforeAfter()->create();
    expect($template->category)->toBe('before_after');
    expect($template->is_active)->toBeTrue();
    expect($template->structure_json)->toBeArray();
});

it('seeds default templates', function () {
    $this->artisan('db:seed', ['--class' => 'StudioTemplateSeeder']);
    expect(StudioTemplate::count())->toBe(6);
    expect(StudioTemplate::where('category', 'before_after')->exists())->toBeTrue();
});

it('scopes to active templates', function () {
    StudioTemplate::factory()->create(['is_active' => true]);
    StudioTemplate::factory()->create(['is_active' => false]);
    expect(StudioTemplate::active()->count())->toBe(1);
});
```

**Step 6: Run migration, seeder, and tests**

```bash
php artisan migrate
php artisan db:seed --class=StudioTemplateSeeder
php artisan test --compact tests/Feature/Studio/StudioTemplateTest.php
```

**Step 7: Commit**

```bash
git add -A && git commit -m "feat(studio): add StudioTemplate model with 6 default templates"
```

---

### Task 3: Studio Feed Component (⚡studio-feed.blade.php)

**Files:**
- Create: `resources/views/components/studio/⚡studio-feed.blade.php`
- Modify: `routes/web.php` (update /studio route)
- Test: `tests/Feature/Studio/StudioFeedTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Studio/StudioFeedTest.php
use App\Models\User;
use App\Models\Deal;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\VideoRecording;
use Livewire\Livewire;

it('renders the studio feed for internal users', function () {
    $user = User::factory()->internal()->create();
    $this->actingAs($user)
        ->get('/studio')
        ->assertOk()
        ->assertSeeLivewire('studio.studio-feed');
});

it('shows deals with media', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create(['company_id' => $user->company_id]);
    $property = Property::factory()->create();
    $deal->update(['property_id' => $property->id]);
    PropertyPhoto::factory()->create(['property_id' => $property->id]);

    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->assertSee($deal->title);
});

it('shows media hub videos', function () {
    $user = User::factory()->internal()->create();
    $video = VideoRecording::factory()->create([
        'user_id' => $user->id,
        'status' => 'ready',
        'available_in_hub' => true,
    ]);

    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->set('activeTab', 'media_hub')
        ->assertSee($video->title);
});

it('filters deals by search', function () {
    $user = User::factory()->internal()->create();
    $deal1 = Deal::factory()->create(['company_id' => $user->company_id, 'title' => 'Johnson Kitchen']);
    $deal2 = Deal::factory()->create(['company_id' => $user->company_id, 'title' => 'Smith Exterior']);

    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->set('search', 'Johnson')
        ->assertSee('Johnson Kitchen')
        ->assertDontSee('Smith Exterior');
});

it('shows my video projects', function () {
    $user = User::factory()->internal()->create();
    $project = \App\Models\VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->set('activeTab', 'my_videos')
        ->assertSee($project->title);
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Studio/StudioFeedTest.php
```

Expected: FAIL (component not found)

**Step 3: Create the studio feed component**

Create `resources/views/components/studio/⚡studio-feed.blade.php`:

```blade
<?php

use App\Models\Deal;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app', ['title' => 'Studio'])] class extends Component {
    use WithPagination;

    #[Url]
    public string $activeTab = 'deals';

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedActiveTab(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    #[Computed]
    public function deals()
    {
        return Deal::query()
            ->where('company_id', auth()->user()->company_id)
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->with(['property.photos', 'estimates.photos', 'job.videoRecordings'])
            ->withCount([
                'estimates as photo_count' => function ($q) {
                    $q->whereHas('photos');
                },
            ])
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function myVideos()
    {
        return VideoProject::query()
            ->where('user_id', auth()->id())
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function myRecordings()
    {
        return VideoRecording::query()
            ->where('user_id', auth()->id())
            ->where('status', 'ready')
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function hubVideos()
    {
        return VideoRecording::query()
            ->where('status', 'ready')
            ->where('available_in_hub', true)
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(20);
    }

    public function createBlank(): void
    {
        $project = VideoProject::create([
            'user_id' => auth()->id(),
            'title' => 'Untitled Video',
            'status' => 'draft',
            'creation_mode' => 'quick',
            'aspect_ratio' => '9:16',
        ]);

        $this->redirect(route('studio.create', $project), navigate: true);
    }
};
?>

<div class="min-h-screen bg-gray-50 dark:bg-zinc-900">
    {{-- Top Bar --}}
    <div class="sticky top-0 z-20 bg-white dark:bg-zinc-800 border-b border-gray-200 dark:border-zinc-700 px-4 py-3">
        <div class="max-w-7xl mx-auto flex items-center justify-between gap-4">
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">Studio</h1>
            <div class="flex items-center gap-3">
                <flux:button wire:click="createBlank" variant="primary" icon="plus">
                    Create
                </flux:button>
            </div>
        </div>
    </div>

    {{-- Filter Tabs --}}
    <div class="sticky top-[57px] z-10 bg-white dark:bg-zinc-800 border-b border-gray-200 dark:border-zinc-700 px-4">
        <div class="max-w-7xl mx-auto flex items-center gap-2 overflow-x-auto py-2">
            @foreach (['deals' => 'Deals', 'my_videos' => 'My Videos', 'recordings' => 'Recordings', 'media_hub' => 'Media Hub'] as $tab => $label)
                <button
                    wire:click="$set('activeTab', '{{ $tab }}')"
                    class="px-4 py-1.5 rounded-full text-sm font-medium whitespace-nowrap transition-colors {{ $activeTab === $tab ? 'bg-black text-white dark:bg-white dark:text-black' : 'bg-gray-100 text-gray-700 dark:bg-zinc-700 dark:text-zinc-300 hover:bg-gray-200 dark:hover:bg-zinc-600' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Search --}}
    <div class="max-w-7xl mx-auto px-4 pt-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search..." icon="magnifying-glass" />
    </div>

    {{-- Content --}}
    <div class="max-w-7xl mx-auto px-4 py-4">
        @if ($activeTab === 'deals')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($this->deals as $deal)
                    <div wire:key="deal-{{ $deal->id }}" class="bg-white dark:bg-zinc-800 rounded-xl border border-gray-200 dark:border-zinc-700 overflow-hidden hover:shadow-md transition-shadow">
                        {{-- Thumbnail strip --}}
                        <div class="h-32 bg-gray-100 dark:bg-zinc-700 flex items-center gap-0.5 overflow-hidden">
                            @php
                                $photos = collect();
                                if ($deal->property) $photos = $photos->merge($deal->property->photos->take(4));
                                if ($deal->estimates->count()) {
                                    foreach ($deal->estimates as $est) {
                                        $photos = $photos->merge($est->photos->take(2));
                                    }
                                }
                                $photos = $photos->take(4);
                            @endphp
                            @forelse ($photos as $photo)
                                <img src="{{ $photo->url }}" alt="" class="h-full flex-1 object-cover min-w-0" />
                            @empty
                                <div class="flex-1 flex items-center justify-center text-gray-400 dark:text-zinc-500">
                                    <flux:icon name="photo" class="size-8" />
                                </div>
                            @endforelse
                        </div>
                        <div class="p-3">
                            <h3 class="font-semibold text-gray-900 dark:text-white truncate">{{ $deal->title }}</h3>
                            <p class="text-sm text-gray-500 dark:text-zinc-400 mt-1">
                                {{ $photos->count() }} photos
                                @if ($deal->job?->videoRecordings->count())
                                    &middot; {{ $deal->job->videoRecordings->count() }} videos
                                @endif
                            </p>
                            <div class="mt-2 flex gap-2">
                                <a href="{{ route('studio.create-from-deal', $deal) }}"
                                   class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                    Create video &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full text-center py-12 text-gray-500 dark:text-zinc-400">
                        <flux:icon name="film" class="size-12 mx-auto mb-3 opacity-50" />
                        <p>No deals found. Create deals in your CRM to see them here.</p>
                    </div>
                @endforelse
            </div>
            <div class="mt-4">{{ $this->deals->links() }}</div>

        @elseif ($activeTab === 'my_videos')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($this->myVideos as $project)
                    <div wire:key="project-{{ $project->id }}" class="bg-white dark:bg-zinc-800 rounded-xl border border-gray-200 dark:border-zinc-700 overflow-hidden hover:shadow-md transition-shadow">
                        <div class="h-32 bg-gray-100 dark:bg-zinc-700 flex items-center justify-center">
                            @if ($project->thumbnail_path)
                                <img src="{{ $project->thumbnail_path }}" alt="" class="h-full w-full object-cover" />
                            @else
                                <flux:icon name="film" class="size-8 text-gray-400 dark:text-zinc-500" />
                            @endif
                        </div>
                        <div class="p-3">
                            <h3 class="font-semibold text-gray-900 dark:text-white truncate">{{ $project->title }}</h3>
                            <p class="text-sm text-gray-500 dark:text-zinc-400 mt-1">
                                {{ ucfirst($project->status) }} &middot; {{ ucfirst(str_replace('_', ' ', $project->creation_mode)) }}
                            </p>
                            <a href="{{ route('studio.create', $project) }}"
                               class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline mt-2 inline-block">
                                Edit &rarr;
                            </a>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full text-center py-12 text-gray-500 dark:text-zinc-400">
                        <p>No video projects yet. Tap Create to start!</p>
                    </div>
                @endforelse
            </div>
            <div class="mt-4">{{ $this->myVideos->links() }}</div>

        @elseif ($activeTab === 'recordings')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($this->myRecordings as $recording)
                    <div wire:key="rec-{{ $recording->id }}" class="bg-white dark:bg-zinc-800 rounded-xl border border-gray-200 dark:border-zinc-700 overflow-hidden hover:shadow-md transition-shadow">
                        <div class="h-32 bg-gray-100 dark:bg-zinc-700 flex items-center justify-center">
                            @if ($recording->getThumbnailUrl())
                                <img src="{{ $recording->getThumbnailUrl() }}" alt="" class="h-full w-full object-cover" />
                            @else
                                <flux:icon name="video-camera" class="size-8 text-gray-400 dark:text-zinc-500" />
                            @endif
                        </div>
                        <div class="p-3">
                            <h3 class="font-semibold text-gray-900 dark:text-white truncate">{{ $recording->title ?: 'Untitled' }}</h3>
                            <p class="text-sm text-gray-500 dark:text-zinc-400 mt-1">
                                {{ $recording->formatted_duration ?? 'Processing' }}
                            </p>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full text-center py-12 text-gray-500 dark:text-zinc-400">
                        <p>No recordings yet.</p>
                    </div>
                @endforelse
            </div>
            <div class="mt-4">{{ $this->myRecordings->links() }}</div>

        @elseif ($activeTab === 'media_hub')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($this->hubVideos as $video)
                    <div wire:key="hub-{{ $video->id }}" class="bg-white dark:bg-zinc-800 rounded-xl border border-gray-200 dark:border-zinc-700 overflow-hidden hover:shadow-md transition-shadow">
                        <div class="h-32 bg-gray-100 dark:bg-zinc-700 flex items-center justify-center">
                            @if ($video->getThumbnailUrl())
                                <img src="{{ $video->getThumbnailUrl() }}" alt="" class="h-full w-full object-cover" />
                            @else
                                <flux:icon name="video-camera" class="size-8 text-gray-400 dark:text-zinc-500" />
                            @endif
                        </div>
                        <div class="p-3">
                            <h3 class="font-semibold text-gray-900 dark:text-white truncate">{{ $video->title }}</h3>
                            <p class="text-sm text-gray-500 dark:text-zinc-400 mt-1">
                                {{ $video->formatted_duration ?? '' }}
                            </p>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full text-center py-12 text-gray-500 dark:text-zinc-400">
                        <p>No media hub videos.</p>
                    </div>
                @endforelse
            </div>
            <div class="mt-4">{{ $this->hubVideos->links() }}</div>
        @endif
    </div>
</div>
```

**Step 4: Update routes**

In `routes/web.php`, update the /studio route:

```php
// Replace:
Route::livewire('/studio', 'studio.project-list');
// With:
Route::livewire('/studio', 'studio.studio-feed')->name('studio.feed');
Route::livewire('/studio/create/{videoProject?}', 'studio.quick-creator')->name('studio.create');
Route::get('/studio/create-from-deal/{deal}', function (Deal $deal) {
    $project = VideoProject::create([
        'user_id' => auth()->id(),
        'title' => $deal->title . ' Video',
        'status' => 'draft',
        'creation_mode' => 'quick',
        'aspect_ratio' => '9:16',
        'metadata' => ['deal_id' => $deal->id],
    ]);
    return redirect()->route('studio.create', $project);
})->name('studio.create-from-deal');
```

**Step 5: Run tests**

```bash
php artisan test --compact tests/Feature/Studio/StudioFeedTest.php
```

**Step 6: Commit**

```bash
git add -A && git commit -m "feat(studio): add studio feed with deal browser, my videos, and media hub tabs"
```

---

### Task 4: Media Browser Modal (⚡media-browser.blade.php)

**Files:**
- Create: `resources/views/components/studio/⚡media-browser.blade.php`
- Test: `tests/Feature/Studio/MediaBrowserTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Studio/MediaBrowserTest.php
use App\Models\User;
use App\Models\Deal;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\VideoRecording;
use Livewire\Livewire;

it('renders the media browser', function () {
    $user = User::factory()->internal()->create();
    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->assertOk();
});

it('shows deal photos when a deal is selected', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create(['company_id' => $user->company_id]);
    $property = Property::factory()->create();
    $deal->update(['property_id' => $property->id]);
    $photo = PropertyPhoto::factory()->create(['property_id' => $property->id, 'caption' => 'Kitchen wall']);

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->call('selectDeal', $deal->id)
        ->assertSee('Kitchen wall');
});

it('dispatches media-selected event when a photo is chosen', function () {
    $user = User::factory()->internal()->create();
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->call('selectMedia', 'property-photo', $photo->id)
        ->assertDispatched('media-selected');
});

it('shows media hub videos', function () {
    $user = User::factory()->internal()->create();
    $video = VideoRecording::factory()->create([
        'user_id' => $user->id,
        'status' => 'ready',
        'available_in_hub' => true,
        'title' => 'Hub Promo Video',
    ]);

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->set('browserTab', 'hub')
        ->assertSee('Hub Promo Video');
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Studio/MediaBrowserTest.php
```

**Step 3: Create the media browser component**

Create `resources/views/components/studio/⚡media-browser.blade.php` — a modal component that browses deals → photos/videos, plus a media hub tab. This replaces the old `⚡media-picker.blade.php`.

Key features:
- `browserTab`: `deals` | `hub` | `recordings`
- `selectedDealId`: When a deal is tapped, load its photos/videos
- `selectMedia(type, id)`: Dispatches `media-selected` event with type and id
- Back button to go from deal detail back to deal list

The component should follow the same Livewire SFC pattern as the studio feed. Use `#[Computed]` for deal list, deal media, hub videos.

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Studio/MediaBrowserTest.php
```

**Step 5: Commit**

```bash
git add -A && git commit -m "feat(studio): add deal-centric media browser modal"
```

---

### Task 5: Basic Quick Creator Shell (⚡quick-creator.blade.php)

**Files:**
- Create: `resources/views/components/studio/⚡quick-creator.blade.php`
- Test: `tests/Feature/Studio/QuickCreatorTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Studio/QuickCreatorTest.php
use App\Models\User;
use App\Models\VideoProject;
use App\Models\PropertyPhoto;
use Livewire\Livewire;

it('renders the quick creator for a project', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'creation_mode' => 'quick']);

    $this->actingAs($user)
        ->get(route('studio.create', $project))
        ->assertOk()
        ->assertSeeLivewire('studio.quick-creator');
});

it('adds a photo clip to the project', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id)
        ->assertCount('clips', 1);
});

it('reorders clips', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo1 = PropertyPhoto::factory()->create();
    $photo2 = PropertyPhoto::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo1->id)
        ->call('addClip', 'property-photo', $photo2->id);

    // Should have 2 clips
    expect($project->fresh()->scenes()->first()->clips()->count())->toBe(2);
});

it('removes a clip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id)
        ->call('removeClip', 0)
        ->assertCount('clips', 0);
});

it('updates project title', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'title' => 'Old Title']);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->set('title', 'New Title')
        ->call('saveTitle');

    expect($project->fresh()->title)->toBe('New Title');
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Studio/QuickCreatorTest.php
```

**Step 3: Create the quick creator component**

Create `resources/views/components/studio/⚡quick-creator.blade.php`:

This is the core TikTok-style editor. For Phase 1, it needs:
- Accept a `VideoProject` parameter
- Maintain a single "quick" scene (auto-created) with clips
- `addClip(type, id)` — adds a VideoProjectClip to the scene
- `removeClip(index)` — removes clip by index
- `reorderClips(orderedIds)` — reorders clips via Alpine drag-and-drop
- Preview area (video element or photo slideshow)
- Clip strip at bottom (horizontal scroll of thumbnails)
- `[+]` button to open media browser modal
- Title editing

**Layout:**
```
┌─────────────────────────┐
│  ← Back    Title    Done│
├─────────────────────────┤
│                         │
│    VIDEO PREVIEW        │
│    (9:16 aspect ratio)  │
│                         │
├─────────────────────────┤
│ [clip][clip][clip][+]   │  ← Clip strip
├─────────────────────────┤
│ (tools bar - Phase 3)   │
└─────────────────────────┘
```

The component creates a default scene on mount if the project has none. Clips are added to this scene. The preview shows clips in order as a slideshow (photos) or video sequence.

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Studio/QuickCreatorTest.php
```

**Step 5: Commit**

```bash
git add -A && git commit -m "feat(studio): add quick creator with clip management and preview"
```

---

## Phase 2: Record & Stitch

### Task 6: Multi-Segment Recording in Quick Creator

**Files:**
- Modify: `resources/views/components/studio/⚡quick-creator.blade.php`
- Test: `tests/Feature/Studio/QuickCreatorRecordingTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Studio/QuickCreatorRecordingTest.php
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Livewire\Livewire;

it('links a recording to the project as a clip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->create(['user_id' => $user->id, 'status' => 'ready']);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addRecordedClip', $recording->id)
        ->assertCount('clips', 1);

    $clip = $project->fresh()->scenes()->first()->clips()->first();
    expect($clip->source_type)->toBe(VideoRecording::class);
    expect($clip->source_id)->toBe($recording->id);
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Studio/QuickCreatorRecordingTest.php
```

**Step 3: Add recording functionality to quick creator**

Add Alpine.js recording state to the quick creator component. This follows the same pattern as `⚡public-recorder.blade.php`:

- `recordingState`: idle | previewing | recording | uploading
- `startCamera()` — getUserMedia with front/rear camera toggle
- `startRecording()` — MediaRecorder, collect chunks
- `stopRecording()` — stop, create blob, upload to `/api/video-recordings`
- `addRecordedClip(recordingId)` — Livewire method that adds the recording as a clip
- Support multiple record-stop-record cycles (each creates a new clip in the strip)
- Camera preview shows in the main preview area during recording mode

**Key implementation details:**
- Reuse the MediaRecorder pattern from `⚡public-recorder.blade.php` (line ~180-280)
- Upload via existing `/api/video-recordings` endpoint (chunked or single-shot)
- On upload complete, call Livewire `addRecordedClip(recordingId)` to add to clip strip
- Multiple segments = multiple clips in the strip, auto-stitched in preview

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Studio/QuickCreatorRecordingTest.php
```

**Step 5: Commit**

```bash
git add -A && git commit -m "feat(studio): add multi-segment recording to quick creator"
```

---

### Task 7: Picture-in-Picture Recording Mode

**Files:**
- Modify: `resources/views/components/studio/⚡quick-creator.blade.php`
- Test: `tests/Feature/Studio/QuickCreatorPipTest.php`

**Step 1: Write test for PIP state management**

```php
// tests/Feature/Studio/QuickCreatorPipTest.php
use App\Models\User;
use App\Models\VideoProject;
use App\Models\PropertyPhoto;
use Livewire\Livewire;

it('sets a clip as PIP background', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id)
        ->call('setClipAsPipBackground', 0)
        ->assertSet('pipBackgroundIndex', 0);
});
```

**Step 2: Implement PIP mode**

Add to quick creator:
- `pipBackgroundIndex` — which clip is the background during recording (nullable)
- `setClipAsPipBackground(index)` — sets a clip as the background
- In Alpine.js: when recording with PIP active, show the background clip's media full-screen and camera feed in a small corner overlay
- Uses Canvas API to composite the two feeds during recording OR records separately and composites server-side

**Implementation approach:**
- During recording, show background media (photo or video) behind the camera feed via CSS positioning (camera in bottom-right corner, 25% size)
- The recorded video captures just the camera feed
- The background clip is a separate clip in the scene with `slot: 'background'`
- Final render composites them (Python FFmpeg overlay)

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/QuickCreatorPipTest.php
git add -A && git commit -m "feat(studio): add picture-in-picture recording mode"
```

---

## Phase 3: Quick Tools

### Task 8: Text Overlays

**Files:**
- Modify: `resources/views/components/studio/⚡quick-creator.blade.php`
- Test: `tests/Feature/Studio/QuickCreatorOverlaysTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Studio/QuickCreatorOverlaysTest.php
use App\Models\User;
use App\Models\VideoProject;
use Livewire\Livewire;

it('adds a text overlay to a scene', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addTextOverlay', 'Hello World', 'center')
        ->assertCount('overlays', 1);

    $overlay = $project->scenes()->first()->overlays()->first();
    expect($overlay->type)->toBe('text');
    expect($overlay->content)->toBe('Hello World');
});

it('removes a text overlay', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addTextOverlay', 'Hello', 'center')
        ->call('removeOverlay', 0)
        ->assertCount('overlays', 0);
});
```

**Step 2: Implement text overlays**

Add to quick creator:
- Text tool button in the tool bar
- `addTextOverlay(content, position)` — creates VideoProjectOverlay
- Positions: top-left, top-center, top-right, center, bottom-left, bottom-center, bottom-right
- Alpine modal for entering text, choosing font size, color
- Preview renders overlays positioned on the preview area

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/QuickCreatorOverlaysTest.php
git add -A && git commit -m "feat(studio): add text overlay tool to quick creator"
```

---

### Task 9: Transitions Between Clips

**Files:**
- Modify: `resources/views/components/studio/⚡quick-creator.blade.php`
- Test: `tests/Feature/Studio/QuickCreatorTransitionsTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Studio/QuickCreatorTransitionsTest.php
use App\Models\User;
use App\Models\VideoProject;
use App\Models\PropertyPhoto;
use Livewire\Livewire;

it('sets transition between clips', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', PropertyPhoto::factory()->create()->id)
        ->call('addClip', 'property-photo', PropertyPhoto::factory()->create()->id)
        ->call('setTransition', 1, 'fade', 500);

    // Second scene should have the transition
    $scene = $project->scenes()->orderBy('sort_order')->skip(1)->first();
    expect($scene->transition_type)->toBe('fade');
    expect($scene->transition_duration_ms)->toBe(500);
});
```

**Step 2: Implement transitions**

Add transition options between clips in the clip strip:
- Tap between two clips → shows transition picker
- Options: cut (none), fade, slide-left, slide-right, zoom
- Sets `transition_type` and `transition_duration_ms` on the scene
- Preview uses CSS transitions to simulate the effect

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/QuickCreatorTransitionsTest.php
git add -A && git commit -m "feat(studio): add transitions between clips"
```

---

### Task 10: Speed Control and Aspect Ratio Toggle

**Files:**
- Modify: `resources/views/components/studio/⚡quick-creator.blade.php`
- Test: `tests/Feature/Studio/QuickCreatorToolsTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Studio/QuickCreatorToolsTest.php
use App\Models\User;
use App\Models\VideoProject;
use Livewire\Livewire;

it('toggles aspect ratio', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'aspect_ratio' => '9:16',
    ]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('setAspectRatio', '16:9');

    expect($project->fresh()->aspect_ratio)->toBe('16:9');
});

it('sets clip speed multiplier', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = \App\Models\PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id)
        ->call('setClipSpeed', 0, 2.0);

    // Speed stored in clip metadata or dedicated field
    $clip = $project->scenes()->first()->clips()->first();
    expect($clip->metadata['speed'] ?? null)->toBe(2.0);
});
```

**Step 2: Implement speed and aspect ratio**

- Aspect ratio toggle: 9:16 / 16:9 / 1:1 buttons, updates `video_project.aspect_ratio`
- Preview area adjusts dimensions accordingly
- Speed control: 0.5x, 1x, 1.5x, 2x buttons per clip
- Store speed in clip metadata JSON or add a `speed` column

**Note:** May need a small migration to add `metadata` JSON column to `video_project_clips` if it doesn't exist. Check the existing schema.

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/QuickCreatorToolsTest.php
git add -A && git commit -m "feat(studio): add speed control and aspect ratio toggle"
```

---

## Phase 4: AI Layer

### Task 11: Install Laravel AI SDK

**Files:**
- Modify: `composer.json`
- Create: `config/ai.php` (published by package)

**Step 1: Install the package**

```bash
composer require laravel/ai
```

**Step 2: Publish config**

```bash
php artisan vendor:publish --provider="Laravel\AI\AIServiceProvider" --no-interaction
```

**Step 3: Configure environment**

Add to `.env`:
```env
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=your-key-here
```

**Step 4: Verify installation**

```bash
php artisan tinker --execute="echo class_exists(\Laravel\AI\AI::class) ? 'OK' : 'FAIL';"
```

**Step 5: Commit**

```bash
git add -A && git commit -m "chore: install Laravel AI SDK"
```

---

### Task 12: AI Tool Definitions

**Files:**
- Create: `app/AI/Tools/SearchDeals.php`
- Create: `app/AI/Tools/GetDealMedia.php`
- Create: `app/AI/Tools/GetJobPhotos.php`
- Create: `app/AI/Tools/GetEstimatePhotos.php`
- Create: `app/AI/Tools/GetPropertyPhotos.php`
- Create: `app/AI/Tools/CreateVideoProject.php`
- Create: `app/AI/Tools/AddClipToProject.php`
- Create: `app/AI/Tools/AddTextOverlay.php`
- Test: `tests/Feature/Studio/AiToolsTest.php`

**Step 1: Write failing tests**

```php
// tests/Feature/Studio/AiToolsTest.php
use App\AI\Tools\SearchDeals;
use App\AI\Tools\GetDealMedia;
use App\Models\Deal;
use App\Models\User;
use App\Models\Property;
use App\Models\PropertyPhoto;

it('searches deals by name', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create([
        'company_id' => $user->company_id,
        'title' => 'Johnson Kitchen Remodel',
    ]);

    $tool = new SearchDeals($user);
    $result = $tool->handle(query: 'Johnson');

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Johnson Kitchen Remodel');
});

it('gets media for a deal', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create(['company_id' => $user->company_id]);
    $property = Property::factory()->create();
    $deal->update(['property_id' => $property->id]);
    PropertyPhoto::factory()->count(3)->create(['property_id' => $property->id]);

    $tool = new GetDealMedia($user);
    $result = $tool->handle(dealId: $deal->id);

    expect($result['photos'])->toHaveCount(3);
});
```

**Step 2: Implement AI tools**

Each tool is a PHP class following the Laravel AI SDK / Prism tool pattern. Check the docs (`search-docs` tool with queries: `['tool definition', 'function calling', 'agent tools']`) for the exact class structure required by the SDK.

General pattern:
```php
// app/AI/Tools/SearchDeals.php
class SearchDeals
{
    public function __construct(private User $user) {}

    public function handle(string $query, ?string $status = null): array
    {
        return Deal::query()
            ->where('company_id', $this->user->company_id)
            ->where('title', 'like', "%{$query}%")
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('customer:id,name')
            ->limit(10)
            ->get()
            ->map(fn ($deal) => [
                'id' => $deal->id,
                'title' => $deal->title,
                'customer' => $deal->customer?->name,
                'status' => $deal->status,
                'has_job' => $deal->job()->exists(),
                'has_estimates' => $deal->estimates()->exists(),
            ])
            ->toArray();
    }
}
```

Implement all 8 tools with the same pattern. Each wraps an Eloquent query and returns structured data the AI can reason about.

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/AiToolsTest.php
git add -A && git commit -m "feat(studio): add AI tool definitions for deal/media queries"
```

---

### Task 13: Studio AI Service

**Files:**
- Create: `app/Services/StudioAiService.php`
- Test: `tests/Feature/Studio/StudioAiServiceTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Studio/StudioAiServiceTest.php
use App\Services\StudioAiService;
use App\Models\User;
use App\Models\Deal;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\VideoProject;

it('generates a video project from a prompt', function () {
    // This test mocks the AI SDK to avoid real API calls
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create([
        'company_id' => $user->company_id,
        'title' => 'Johnson Kitchen',
    ]);
    $property = Property::factory()->create();
    $deal->update(['property_id' => $property->id]);
    PropertyPhoto::factory()->count(3)->create([
        'property_id' => $property->id,
        'category' => 'after',
    ]);

    // Mock the AI SDK to return a predetermined tool call sequence
    // The exact mock pattern depends on Laravel AI SDK's testing utilities
    // Use search-docs to find the testing approach

    $service = app(StudioAiService::class);
    // Test that the service can be instantiated and has the right tools registered
    expect($service->getRegisteredTools())->toContain('search_deals');
    expect($service->getRegisteredTools())->toContain('get_deal_media');
    expect($service->getRegisteredTools())->toContain('create_video_project');
});
```

**Step 2: Implement the service**

```php
// app/Services/StudioAiService.php
class StudioAiService
{
    public function __construct(private User $user) {}

    public function generateFromPrompt(string $prompt): VideoProject
    {
        // Use Laravel AI SDK to create an agent with tools
        // The agent has access to: SearchDeals, GetDealMedia, GetJobPhotos,
        // GetEstimatePhotos, GetPropertyPhotos, CreateVideoProject,
        // AddClipToProject, AddTextOverlay

        // System prompt instructs the AI to:
        // 1. Interpret the user's request
        // 2. Search for relevant deals/media
        // 3. Create a VideoProject with appropriate clips and overlays
        // 4. Return the project ID

        // Check Laravel AI SDK docs for exact agent pattern
    }

    public function assistEdit(VideoProject $project, string $instruction): array
    {
        // AI assist: "add captions", "trim dead air", etc.
        // Returns array of suggested changes or applies them directly
    }

    public function getRegisteredTools(): array
    {
        return [
            'search_deals', 'get_deal_media', 'get_job_photos',
            'get_estimate_photos', 'get_property_photos',
            'create_video_project', 'add_clip_to_project', 'add_text_overlay',
        ];
    }
}
```

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/StudioAiServiceTest.php
git add -A && git commit -m "feat(studio): add StudioAiService with tool-calling agent"
```

---

### Task 14: AI Prompt Bar Component (⚡ai-prompt-bar.blade.php)

**Files:**
- Create: `resources/views/components/studio/⚡ai-prompt-bar.blade.php`
- Modify: `resources/views/components/studio/⚡quick-creator.blade.php` (embed prompt bar)
- Modify: `resources/views/components/studio/⚡studio-feed.blade.php` (embed prompt bar)
- Test: `tests/Feature/Studio/AiPromptBarTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Studio/AiPromptBarTest.php
use App\Models\User;
use App\Models\VideoProject;
use Livewire\Livewire;

it('renders the AI prompt bar', function () {
    $user = User::factory()->internal()->create();
    Livewire::actingAs($user)
        ->test('studio.ai-prompt-bar')
        ->assertOk()
        ->assertSee('Ask AI');
});

it('accepts a prompt and shows loading state', function () {
    $user = User::factory()->internal()->create();

    // Mock StudioAiService to avoid real API calls
    Livewire::actingAs($user)
        ->test('studio.ai-prompt-bar')
        ->set('prompt', 'Make a before/after video of the Johnson kitchen')
        ->call('generate')
        ->assertSet('isProcessing', true);
});
```

**Step 2: Implement the prompt bar**

```blade
<?php
use App\Services\StudioAiService;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $prompt = '';
    public bool $isProcessing = false;
    public ?string $statusMessage = null;
    public ?int $generatedProjectId = null;

    public function generate(): void
    {
        $this->validate(['prompt' => 'required|min:3']);
        $this->isProcessing = true;
        $this->statusMessage = 'AI is working...';

        try {
            $service = new StudioAiService(auth()->user());
            $project = $service->generateFromPrompt($this->prompt);
            $this->generatedProjectId = $project->id;
            $this->statusMessage = 'Video project created!';
            $this->dispatch('ai-project-created', projectId: $project->id);
        } catch (\Exception $e) {
            $this->statusMessage = 'Something went wrong: ' . $e->getMessage();
        } finally {
            $this->isProcessing = false;
        }
    }
};
?>

<div>
    <div class="flex items-center gap-2">
        <div class="flex-1 relative">
            <flux:input
                wire:model="prompt"
                wire:keydown.enter="generate"
                placeholder="Ask AI to create a video..."
                icon="sparkles"
                :disabled="$isProcessing"
            />
        </div>
        <flux:button wire:click="generate" :disabled="$isProcessing" variant="primary" icon="sparkles">
            {{ $isProcessing ? 'Working...' : 'Ask AI' }}
        </flux:button>
    </div>

    @if ($statusMessage)
        <div class="mt-2 text-sm {{ $generatedProjectId ? 'text-green-600' : 'text-gray-500' }}">
            {{ $statusMessage }}
            @if ($generatedProjectId)
                <a href="{{ route('studio.create', $generatedProjectId) }}" class="underline font-medium">
                    Open in editor &rarr;
                </a>
            @endif
        </div>
    @endif
</div>
```

**Step 3: Embed in studio feed and quick creator**

Add `<livewire:studio.ai-prompt-bar />` to both the studio feed (top bar area) and quick creator (above the preview).

**Step 4: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/AiPromptBarTest.php
git add -A && git commit -m "feat(studio): add AI prompt bar with Claude-powered generation"
```

---

## Phase 5: Smart Templates

### Task 15: Template Picker Component (⚡template-picker.blade.php)

**Files:**
- Create: `resources/views/components/studio/⚡template-picker.blade.php`
- Test: `tests/Feature/Studio/TemplatePickerTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Studio/TemplatePickerTest.php
use App\Models\User;
use App\Models\StudioTemplate;
use Livewire\Livewire;

it('renders active templates', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->create(['name' => 'Before & After']);

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->assertSee('Before & After');
});

it('hides inactive templates', function () {
    $user = User::factory()->internal()->create();
    StudioTemplate::factory()->inactive()->create(['name' => 'Hidden Template']);

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->assertDontSee('Hidden Template');
});

it('creates a project from a template', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->beforeAfter()->create();

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->call('useTemplate', $template->id);

    $project = \App\Models\VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project->creation_mode)->toBe('template');
    expect($project->template_id)->toBe($template->id);
});
```

**Step 2: Implement template picker**

- Grid of template cards showing thumbnail, name, description
- Tap a template → creates a VideoProject with `creation_mode: template`, links template_id
- Auto-creates scenes based on `structure_json` segments
- Redirects to quick creator with pre-built scene structure

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/TemplatePickerTest.php
git add -A && git commit -m "feat(studio): add template picker with auto-scaffolded projects"
```

---

### Task 16: Template Auto-Fill with AI

**Files:**
- Modify: `app/Services/StudioAiService.php` (add `fillTemplate` method)
- Test: `tests/Feature/Studio/TemplateAutoFillTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Studio/TemplateAutoFillTest.php
use App\Models\User;
use App\Models\Deal;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\StudioTemplate;
use App\Models\VideoProject;
use App\Services\StudioAiService;

it('fills a before/after template with deal photos', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create(['company_id' => $user->company_id]);
    $property = Property::factory()->create();
    $deal->update(['property_id' => $property->id]);

    PropertyPhoto::factory()->create(['property_id' => $property->id, 'category' => 'before']);
    PropertyPhoto::factory()->create(['property_id' => $property->id, 'category' => 'after']);

    $template = StudioTemplate::factory()->beforeAfter()->create();
    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'creation_mode' => 'template',
        'metadata' => ['deal_id' => $deal->id],
    ]);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    // Should have auto-filled clips from deal photos
    $clips = $project->scenes()->with('clips')->get()->flatMap->clips;
    expect($clips->count())->toBeGreaterThan(0);
});
```

**Step 2: Implement template auto-fill**

Add `fillTemplate(VideoProject $project)` to StudioAiService:
- Reads `structure_json` from the template
- For each segment with `auto_fill`, queries the appropriate data:
  - `before_photos` → PropertyPhoto::where('category', 'before')
  - `after_photos` → PropertyPhoto::where('category', 'after')
  - `all_photos` → all property photos
  - `estimate_photos` → estimate photos
  - `recent_completions` → recent completed job photos
- Creates VideoProjectClips for each auto-filled media item
- Creates VideoProjectOverlays for auto-fill text (company_name from CompanySetting, customer_name from deal)

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/TemplateAutoFillTest.php
git add -A && git commit -m "feat(studio): add AI template auto-fill from system data"
```

---

## Phase 6: AI Assists

### Task 17: Auto-Captions via Whisper

**Files:**
- Modify: `app/Services/StudioAiService.php` (add `generateCaptions` method)
- Modify: `resources/views/components/studio/⚡quick-creator.blade.php` (add captions button)
- Test: `tests/Feature/Studio/AutoCaptionsTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Studio/AutoCaptionsTest.php
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use App\Services\StudioAiService;

it('generates captions for a project with audio clips', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = $project->scenes()->create([
        'sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo',
    ]);
    $recording = VideoRecording::factory()->create([
        'user_id' => $user->id,
        'status' => 'ready',
        'video_path' => 'video-recordings/videos/test.mp4',
    ]);
    $scene->clips()->create([
        'slot' => 'main',
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
        'sort_order' => 0,
    ]);

    // Mock PythonBridgeService::transcribeAudio to return segments
    $this->mock(\App\Services\PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('transcribeAudio')->andReturn([
            'text' => 'Hello, welcome to our walkthrough.',
            'segments' => [
                ['start' => 0.0, 'end' => 1.5, 'text' => 'Hello,'],
                ['start' => 1.5, 'end' => 3.0, 'text' => 'welcome to our walkthrough.'],
            ],
        ]);
    });

    $service = new StudioAiService($user);
    $captions = $service->generateCaptions($project);

    expect($captions)->toHaveCount(2);
    expect($captions[0]['text'])->toBe('Hello,');
});
```

**Step 2: Implement auto-captions**

Add `generateCaptions(VideoProject $project)` to StudioAiService:
1. Iterate through project clips that are VideoRecordings
2. Get audio URL for each recording
3. Call `PythonBridgeService::transcribeAudio($audioUrl)` (existing Whisper endpoint)
4. Parse the timestamped segments returned
5. Create VideoProjectOverlays of type 'caption' positioned at the correct timestamps
6. Return the generated captions array

Add "Auto-captions" button to quick creator tool bar that calls this via Livewire.

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/AutoCaptionsTest.php
git add -A && git commit -m "feat(studio): add auto-captions via Whisper transcription"
```

---

### Task 18: AI Edit Assists (Trim Dead Air, Pacing Suggestions)

**Files:**
- Modify: `app/Services/StudioAiService.php` (add `assistEdit` method)
- Test: `tests/Feature/Studio/AiEditAssistTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Studio/AiEditAssistTest.php
use App\Models\User;
use App\Models\VideoProject;
use App\Services\StudioAiService;

it('processes an edit assist request', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    // Mock the AI SDK
    $service = new StudioAiService($user);
    $result = $service->assistEdit($project, 'Add captions to this video');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('action');
});
```

**Step 2: Implement AI edit assists**

The `assistEdit` method uses Claude to interpret editing instructions and map them to concrete actions:
- "Add captions" → calls `generateCaptions()`
- "Trim the dead air" → analyzes audio, suggests trim points
- "Make it shorter" → suggests clips to remove or trim
- "Add transitions" → suggests transition types between clips
- "Speed up the middle" → suggests speed changes

The AI uses tool calling to modify the project directly or return suggestions for user approval.

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/AiEditAssistTest.php
git add -A && git commit -m "feat(studio): add AI edit assists for trim, pacing, and suggestions"
```

---

## Phase 7: Polish & Export

### Task 19: Export and Publish Flow

**Files:**
- Modify: `resources/views/components/studio/⚡quick-creator.blade.php` (add Done/Export flow)
- Test: `tests/Feature/Studio/QuickCreatorExportTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Studio/QuickCreatorExportTest.php
use App\Models\User;
use App\Models\VideoProject;
use App\Models\PropertyPhoto;
use Livewire\Livewire;

it('renders the project for export', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);
    $project->scenes->first()->clips()->create([
        'slot' => 'main',
        'source_type' => PropertyPhoto::class,
        'source_id' => PropertyPhoto::factory()->create()->id,
        'sort_order' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('startRender')
        ->assertSet('isRendering', true);

    expect($project->fresh()->status)->toBe('rendering');
});

it('publishes to media hub', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'status' => 'rendered',
    ]);
    $recording = \App\Models\VideoRecording::factory()->create([
        'user_id' => $user->id,
        'status' => 'ready',
    ]);
    $project->update(['video_recording_id' => $recording->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('publishToHub');

    expect($recording->fresh()->available_in_hub)->toBeTrue();
});
```

**Step 2: Implement export flow**

Add to quick creator:
- **"Done" button** → opens export modal with options:
  - Render video (triggers Remotion or FFmpeg pipeline)
  - Download (once rendered)
  - Publish to Media Hub (sets `available_in_hub = true`)
  - Copy share link (uses existing share_token)
- **Render flow:**
  1. Set project `status: rendering`
  2. Create a RemotionBuildRequest or use Python FFmpeg pipeline
  3. Poll for completion
  4. On complete, show download button and publish toggle

**Step 3: Run tests and commit**

```bash
php artisan test --compact tests/Feature/Studio/QuickCreatorExportTest.php
git add -A && git commit -m "feat(studio): add export flow with render, download, and media hub publish"
```

---

### Task 20: Final Integration & Cleanup

**Files:**
- Modify: `routes/web.php` (clean up old routes, ensure new ones work)
- Modify: `resources/views/layouts/app.blade.php` (update nav links if needed)
- Remove: Old broken editor references (mark as deprecated, don't delete yet)
- Test: `tests/Feature/Studio/StudioIntegrationTest.php`

**Step 1: Write integration test**

```php
// tests/Feature/Studio/StudioIntegrationTest.php
use App\Models\User;
use App\Models\Deal;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\VideoProject;

it('completes the full studio workflow: feed → create → add clips → export', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create(['company_id' => $user->company_id]);
    $property = Property::factory()->create();
    $deal->update(['property_id' => $property->id]);
    PropertyPhoto::factory()->count(3)->create(['property_id' => $property->id]);

    // 1. Visit studio feed
    $this->actingAs($user)->get('/studio')->assertOk();

    // 2. Create project from deal
    $response = $this->actingAs($user)->get(route('studio.create-from-deal', $deal));
    $project = VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project)->not->toBeNull();
    expect($project->creation_mode)->toBe('quick');

    // 3. Open quick creator
    $this->actingAs($user)
        ->get(route('studio.create', $project))
        ->assertOk();
});

it('navigates between all studio pages without errors', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)->get('/studio')->assertOk();
    // Old routes should redirect or still work
});
```

**Step 2: Clean up routes and navigation**

- Ensure `/studio` points to the new feed
- Ensure `/studio/create/{project}` points to quick creator
- Keep `/studio/live/{session?}` for live streaming (untouched)
- Keep share/guest/recording routes (untouched)
- Update app layout nav if it references old studio routes

**Step 3: Run full test suite**

```bash
php artisan test --compact tests/Feature/Studio/
```

**Step 4: Final commit**

```bash
git add -A && git commit -m "feat(studio): integrate all studio components and clean up routes"
```

---

## Parallel Execution Strategy

These task groups can run **simultaneously with agent teams**:

| Agent Team | Tasks | Focus |
|-----------|-------|-------|
| **Team A: Data Layer** | Tasks 1-2 | Migrations, models, seeders |
| **Team B: UI Components** | Tasks 3-5 | Studio feed, media browser, quick creator shell |
| **Team C: Recording** | Tasks 6-7 | Multi-segment recording, PIP (depends on Task 5) |
| **Team D: Quick Tools** | Tasks 8-10 | Text overlays, transitions, speed/aspect (depends on Task 5) |
| **Team E: AI Backend** | Tasks 11-14 | Laravel AI SDK, tools, service, prompt bar |
| **Team F: Templates** | Tasks 15-16 | Template picker, auto-fill (depends on Tasks 2, 12) |
| **Team G: AI Assists** | Tasks 17-18 | Auto-captions, edit assists (depends on Task 13) |
| **Team H: Polish** | Tasks 19-20 | Export, integration, cleanup (depends on all above) |

**Dependency graph:**
```
Tasks 1-2 (Data) ──────────────────────────────────────────→ Task 20
Tasks 3-5 (UI) ────→ Tasks 6-7 (Recording) ───────────────→ Task 20
                ────→ Tasks 8-10 (Quick Tools) ────────────→ Task 20
Tasks 11-14 (AI) ──→ Tasks 15-16 (Templates) ─────────────→ Task 20
                ──→ Tasks 17-18 (AI Assists) ──────────────→ Task 20
```

Teams A+B+E can start simultaneously. C+D start after B. F+G start after E. H is the final integration.
