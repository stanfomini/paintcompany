# Media Hub & Video Platform Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a video management platform with public channels (/watch), Loom-style shareable recordings, and internal training paths for employees.

**Architecture:** Extends existing `VideoRecording` model with additive columns. New models for channels, views, emails, and training paths. Separate Livewire SFC components under `resources/views/components/media/` and `resources/views/pages/media/`. Public pages under `/watch` routes with no auth. Internal management under `/media` routes with permission middleware.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Flux UI Free, Pest 4, Spatie Permission, existing VideoRecording + Python processing pipeline.

**Execution:** Claude Agent Teams - parallel workstreams for independent tasks.

---

## Task 1: Database Migrations & Models

**Files:**
- Create: `database/migrations/2026_02_17_220000_add_media_hub_columns_to_video_recordings.php`
- Create: `database/migrations/2026_02_17_220100_create_video_channels_table.php`
- Create: `database/migrations/2026_02_17_220200_create_video_channel_items_table.php`
- Create: `database/migrations/2026_02_17_220300_create_video_views_table.php`
- Create: `database/migrations/2026_02_17_220400_create_video_emails_table.php`
- Create: `database/migrations/2026_02_17_220500_create_training_paths_table.php`
- Create: `database/migrations/2026_02_17_220600_create_training_path_steps_table.php`
- Create: `database/migrations/2026_02_17_220700_create_training_progress_table.php`
- Modify: `app/Models/VideoRecording.php` (add new fillable, relationships)
- Create: `app/Models/VideoChannel.php`
- Create: `app/Models/VideoChannelItem.php`
- Create: `app/Models/VideoView.php`
- Create: `app/Models/VideoEmail.php`
- Create: `app/Models/TrainingPath.php`
- Create: `app/Models/TrainingPathStep.php`
- Create: `app/Models/TrainingProgress.php`
- Create: `database/factories/VideoChannelFactory.php`
- Create: `database/factories/VideoChannelItemFactory.php`
- Create: `database/factories/VideoViewFactory.php`
- Create: `database/factories/VideoEmailFactory.php`
- Create: `database/factories/TrainingPathFactory.php`
- Create: `database/factories/TrainingPathStepFactory.php`
- Create: `database/factories/TrainingProgressFactory.php`
- Modify: `database/factories/VideoRecordingFactory.php` (add new states)
- Test: `tests/Feature/MediaHub/ModelsTest.php`

### Step 1: Create migrations

**Migration: add_media_hub_columns_to_video_recordings**
```php
Schema::table('video_recordings', function (Blueprint $table) {
    $table->string('visibility')->default('unlisted'); // unlisted, public, internal
    $table->text('description')->nullable();
    $table->json('metadata')->nullable();
    $table->index('visibility');
});
```
NOTE: SQLite doesn't support adding indexes to existing columns with ALTER. Use the driver check pattern:
```php
if (DB::getDriverName() === 'mysql') {
    $table->index('visibility');
}
```

**Migration: create_video_channels_table**
```php
Schema::create('video_channels', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('slug')->unique();
    $table->text('description')->nullable();
    $table->string('thumbnail_path')->nullable();
    $table->string('visibility')->default('public'); // public, private
    $table->integer('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->index('visibility');
    $table->index('sort_order');
});
```

**Migration: create_video_channel_items_table**
```php
Schema::create('video_channel_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('video_channel_id')->constrained()->cascadeOnDelete();
    $table->foreignId('video_recording_id')->constrained()->cascadeOnDelete();
    $table->integer('sort_order')->default(0);
    $table->timestamp('published_at')->nullable();
    $table->timestamps();
    $table->unique(['video_channel_id', 'video_recording_id']);
});
```

**Migration: create_video_views_table**
```php
Schema::create('video_views', function (Blueprint $table) {
    $table->id();
    $table->foreignId('video_recording_id')->constrained()->cascadeOnDelete();
    $table->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('viewer_email')->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->string('user_agent')->nullable();
    $table->unsignedInteger('watch_duration_seconds')->default(0);
    $table->unsignedTinyInteger('watch_percentage')->default(0);
    $table->string('source')->default('link'); // link, channel, email, embed
    $table->timestamps();
    $table->index('video_recording_id');
    $table->index('viewer_id');
});
```

**Migration: create_video_emails_table**
```php
Schema::create('video_emails', function (Blueprint $table) {
    $table->id();
    $table->foreignId('video_recording_id')->constrained()->cascadeOnDelete();
    $table->foreignId('sent_by')->constrained('users')->cascadeOnDelete();
    $table->string('recipient_email');
    $table->string('recipient_name')->nullable();
    $table->string('subject');
    $table->text('message')->nullable();
    $table->timestamp('opened_at')->nullable();
    $table->timestamp('clicked_at')->nullable();
    $table->timestamps();
});
```

**Migration: create_training_paths_table**
```php
Schema::create('training_paths', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('visibility')->default('all_internal'); // employees, crews, all_internal
    $table->boolean('is_required')->default(false);
    $table->integer('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Migration: create_training_path_steps_table**
```php
Schema::create('training_path_steps', function (Blueprint $table) {
    $table->id();
    $table->foreignId('training_path_id')->constrained()->cascadeOnDelete();
    $table->foreignId('video_recording_id')->constrained()->cascadeOnDelete();
    $table->string('title')->nullable();
    $table->text('description')->nullable();
    $table->integer('sort_order')->default(0);
    $table->boolean('is_required')->default(true);
    $table->timestamps();
});
```

**Migration: create_training_progress_table**
```php
Schema::create('training_progress', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('training_path_step_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('watch_percentage')->default(0);
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
    $table->unique(['user_id', 'training_path_step_id']);
});
```

### Step 2: Create models

**VideoChannel model** - relationships: `user()`, `items()`, `videos()` (through items), `activeItems()`. Scopes: `scopePublic()`, `scopeActive()`. Auto-generate slug from name in `booted()`.

**VideoChannelItem model** - relationships: `channel()`, `video()`. Scope: `scopePublished()` (where published_at <= now).

**VideoView model** - relationships: `video()`, `viewer()`.

**VideoEmail model** - relationships: `video()`, `sender()`. Methods: `markOpened()`, `markClicked()`.

**TrainingPath model** - relationships: `steps()`, `progress()`. Methods: `getCompletionPercentageForUser(User $user)`.

**TrainingPathStep model** - relationships: `path()`, `video()`, `progress()`.

**TrainingProgress model** - relationships: `user()`, `step()`. Methods: `markCompleted()`.

**Update VideoRecording** - add to fillable: `visibility`, `description`, `metadata`. Add cast: `metadata` => `array`. Add relationships: `channels()` (belongsToMany through video_channel_items), `views()`, `emails()`. Add scopes: `scopePublicVisible()`, `scopeInternal()`.

### Step 3: Create factories with useful states

Each factory should have states that match the model's typical use cases. For example, `VideoChannelFactory` should have `public()`, `private()`, `inactive()` states.

### Step 4: Run migrations

```bash
php artisan migrate
```

### Step 5: Write model tests

Test file: `tests/Feature/MediaHub/ModelsTest.php`

Test all relationships, scopes, auto-slug generation, factory states, and computed methods. Key tests:
- Channel auto-generates slug from name
- Channel slug is unique (appends -2, -3 etc.)
- VideoRecording can belong to multiple channels
- VideoView tracks watch percentage
- TrainingProgress unique constraint per user+step
- TrainingPath completion percentage calculation

### Step 6: Run tests and verify

```bash
php artisan test --compact tests/Feature/MediaHub/ModelsTest.php
```

### Step 7: Commit

```bash
git add -A && git commit -m "feat: add media hub database models, migrations, and factories"
```

---

## Task 2: Permissions & Routes

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/MediaHub/RoutesTest.php`

### Step 1: Add permissions to seeder

Add to the `$permissions` array in `RolesAndPermissionsSeeder.php`:
```php
// Media Hub
'media.view',
'media.record',
'media.manage_channels',
'media.manage_training',
'media.send_emails',
```

Add to owner role: all media permissions (already gets all via `Permission::all()`).
Add to admin role: `'media.view', 'media.record', 'media.send_emails'`.
Add to manager role: `'media.view', 'media.record', 'media.send_emails'`.

### Step 2: Run the seeder

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
```

### Step 3: Add internal routes to `routes/web.php`

Inside the existing `Route::middleware(['auth', 'verified', 'auth.internal'])` group (around line 172), add:

```php
// Media Hub routes
Route::middleware(['permission:media.view'])->group(function () {
    Route::livewire('/media', 'media.hub')->name('media.index');
    Route::livewire('/media/videos/{recording}', 'media.video-show')->name('media.video.show');
    Route::livewire('/media/channels/{channel}', 'media.channel-manage')
        ->middleware('permission:media.manage_channels')
        ->name('media.channel.manage');
    Route::livewire('/media/training/{trainingPath}', 'media.training-manage')
        ->middleware('permission:media.manage_training')
        ->name('media.training.manage');
});
```

### Step 4: Add public /watch routes

Before the internal routes group (around line 100, near other public routes):

```php
// Public watch hub (no auth)
Route::livewire('/watch', 'public.watch-hub')
    ->name('watch.index')
    ->middleware('throttle:60,1');
Route::livewire('/watch/{channel:slug}', 'public.watch-channel')
    ->name('watch.channel')
    ->middleware('throttle:60,1');
Route::livewire('/watch/{channel:slug}/{recording}', 'public.watch-player')
    ->name('watch.player')
    ->middleware('throttle:60,1');
```

### Step 5: Add API routes for view tracking and email

```php
// Media Hub API (view tracking - public, rate limited)
Route::post('/api/video-views', [\App\Http\Controllers\VideoViewController::class, 'store'])
    ->name('api.video-views.store')
    ->middleware('throttle:120,1');

// Media Hub API (email tracking pixel/click)
Route::get('/api/video-emails/{videoEmail}/pixel', [\App\Http\Controllers\VideoEmailTrackingController::class, 'pixel'])
    ->name('api.video-emails.pixel');
Route::get('/api/video-emails/{videoEmail}/click', [\App\Http\Controllers\VideoEmailTrackingController::class, 'click'])
    ->name('api.video-emails.click');
```

### Step 6: Add Media Hub to sidebar

In `resources/views/layouts/app/sidebar.blade.php`, add to the Platform group (after the Business Plan item, before Referrals):

```blade
@can('media.view')
    <flux:sidebar.item icon="film" :href="route('media.index')" :current="request()->routeIs('media.*')" wire:navigate>
        {{ __('Media Hub') }}
    </flux:sidebar.item>
@endcan
```

### Step 7: Write route tests

Test: auth required for `/media`, permissions enforced, public `/watch` accessible without auth, 404 for nonexistent channels.

### Step 8: Run tests and commit

```bash
php artisan test --compact tests/Feature/MediaHub/RoutesTest.php
git add -A && git commit -m "feat: add media hub permissions, routes, and sidebar link"
```

---

## Task 3: Media Hub Dashboard (Internal)

**Files:**
- Create: `resources/views/components/media/⚡hub.blade.php` (tab container)
- Create: `resources/views/components/media/⚡my-videos.blade.php` (videos grid)
- Create: `resources/views/components/media/⚡video-card.blade.php` (reusable card)
- Create: `resources/views/components/media/⚡channel-list.blade.php` (channel management)
- Test: `tests/Feature/MediaHub/HubTest.php`

### Step 1: Build the hub container

`⚡hub.blade.php` - Simple tab component with 4 tabs: My Videos, Channels, Training, Analytics. Uses `$activeTab` property. Each tab renders a child Livewire component. Keep this component under 80 lines - it's just the shell.

```php
<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Media Hub'])]
class extends Component {
    #[Url]
    public string $tab = 'videos';
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between mb-6">
        <flux:heading size="xl">{{ __('Media Hub') }}</flux:heading>
    </div>

    <div class="flex gap-2 mb-6">
        <!-- Tab buttons -->
    </div>

    @if($tab === 'videos')
        @livewire('media.my-videos')
    @elseif($tab === 'channels')
        @livewire('media.channel-list')
    @elseif($tab === 'training')
        @livewire('media.training-list')
    @elseif($tab === 'analytics')
        @livewire('media.analytics-overview')
    @endif
</div>
```

### Step 2: Build the my-videos grid

`⚡my-videos.blade.php` - Lists current user's videos with pagination. Excludes videos attached to estimates/jobs (those stay in their own workflow). Filter by visibility. Each video rendered as a `⚡video-card` component.

Key properties: `$search`, `$filterVisibility`, `$sortBy`.
Computed: `videos()` query with filters.
Actions: `delete($id)`, `copyLink($id)`.

### Step 3: Build the reusable video card

`⚡video-card.blade.php` - Small component: thumbnail, title, duration, view count, visibility badge, action dropdown (copy link, edit, add to channel, delete). Under 60 lines.

### Step 4: Build the channel list

`⚡channel-list.blade.php` - Lists channels with create modal. Shows: name, visibility badge, video count, active toggle. Owner-only (check `media.manage_channels` permission).

### Step 5: Write tests

Test the hub renders correct tabs, my-videos shows user's videos, video card displays correct info, channel list respects permissions.

### Step 6: Run tests and commit

```bash
php artisan test --compact tests/Feature/MediaHub/HubTest.php
git add -A && git commit -m "feat: add media hub dashboard with videos grid and channel list"
```

---

## Task 4: Channel Management (Internal)

**Files:**
- Create: `resources/views/components/media/⚡channel-editor.blade.php`
- Create: `resources/views/components/media/⚡channel-manage.blade.php`
- Create: `resources/views/components/media/⚡channel-video-picker.blade.php`
- Test: `tests/Feature/MediaHub/ChannelManagementTest.php`

### Step 1: Channel editor modal

`⚡channel-editor.blade.php` - Create/edit channel form. Fields: name, description, visibility (public/private), thumbnail upload. Auto-generates slug from name. Dispatches `channel-saved` event.

### Step 2: Channel management page

`⚡channel-manage.blade.php` - Full page for managing a single channel. Shows: channel details, list of videos with drag-to-reorder (use `wire:sortable`), add video button opens picker, remove video button. Publish/unpublish per video.

### Step 3: Video picker modal

`⚡channel-video-picker.blade.php` - Modal to search and add existing videos to a channel. Shows videos not already in this channel. Search by title. Click to add.

### Step 4: Write tests

Test: create channel with auto-slug, edit channel, add video to channel, remove video, reorder videos, duplicate slug handling, permission enforcement.

### Step 5: Run tests and commit

```bash
php artisan test --compact tests/Feature/MediaHub/ChannelManagementTest.php
git add -A && git commit -m "feat: add channel management with video picker and reordering"
```

---

## Task 5: Video Detail & Edit Page (Internal)

**Files:**
- Create: `resources/views/components/media/⚡video-show.blade.php`
- Create: `resources/views/components/media/⚡video-edit-form.blade.php`
- Create: `resources/views/components/media/⚡video-analytics.blade.php`
- Create: `resources/views/components/media/⚡video-send-email.blade.php`
- Test: `tests/Feature/MediaHub/VideoShowTest.php`

### Step 1: Video show page

`⚡video-show.blade.php` - Layout page with video player, title, description, and child components for edit form, analytics, and email send. Tabs or collapsible sections.

### Step 2: Edit form component

`⚡video-edit-form.blade.php` - Edit title, description, visibility, manage channel assignments (checkbox list of channels).

### Step 3: Analytics component

`⚡video-analytics.blade.php` - View count, unique viewers, average watch percentage, recent viewers table. Simple stats computed from `VideoView` records.

### Step 4: Email send component

`⚡video-send-email.blade.php` - Form: recipient email, recipient name, subject (pre-filled), message. Sends a `VideoSharedNotification`. Creates `VideoEmail` record. Shows sent history below form.

### Step 5: Create VideoSharedNotification

`app/Notifications/VideoSharedNotification.php` - Mail notification with branded template showing video thumbnail, play button, and custom message. Links to `/v/{shareToken}?ref=email&eid={emailId}`.

### Step 6: Write tests

Test: video show page renders, edit form updates video, analytics show correct counts, email send creates record and dispatches notification.

### Step 7: Run tests and commit

```bash
php artisan test --compact tests/Feature/MediaHub/VideoShowTest.php
git add -A && git commit -m "feat: add video detail page with editing, analytics, and email sending"
```

---

## Task 6: View Tracking System

**Files:**
- Create: `app/Http/Controllers/VideoViewController.php`
- Create: `app/Http/Controllers/VideoEmailTrackingController.php`
- Create: `resources/js/video-tracker.js`
- Modify: existing video player views to include tracker
- Test: `tests/Feature/MediaHub/ViewTrackingTest.php`

### Step 1: VideoViewController

API endpoint that receives view tracking beacons from the JS tracker. Creates/updates `VideoView` records. Fields: `video_recording_id`, `watch_duration_seconds`, `watch_percentage`, `source`. Uses request IP and user agent. If user is authenticated, sets `viewer_id`.

```php
public function store(Request $request): JsonResponse
{
    $request->validate([
        'video_id' => ['required', 'exists:video_recordings,id'],
        'duration' => ['required', 'integer', 'min:0'],
        'percentage' => ['required', 'integer', 'min:0', 'max:100'],
        'source' => ['required', 'string', 'in:link,channel,email,embed'],
        'session_id' => ['required', 'string', 'max:64'],
    ]);

    VideoView::updateOrCreate(
        [
            'video_recording_id' => $request->input('video_id'),
            'ip_address' => $request->ip(),
            'created_at' => now()->subMinutes(30), // same session within 30 min
        ],
        [
            'viewer_id' => auth()->id(),
            'watch_duration_seconds' => $request->input('duration'),
            'watch_percentage' => $request->input('percentage'),
            'source' => $request->input('source'),
            'user_agent' => $request->userAgent(),
        ]
    );

    return response()->json(['ok' => true]);
}
```

### Step 2: Email tracking controller

`VideoEmailTrackingController` - Two endpoints:
- `pixel()` - returns 1x1 transparent GIF, marks `VideoEmail` as opened
- `click()` - redirects to video share URL, marks `VideoEmail` as clicked

### Step 3: JavaScript video tracker

`resources/js/video-tracker.js` - Attaches to `<video>` elements. Fires beacon at 25%, 50%, 75%, 100% watch marks and every 30 seconds. Uses `navigator.sendBeacon()` for reliability. Generates session ID to deduplicate.

### Step 4: Write tests

Test: view tracking creates records, deduplicates within session, email pixel tracking works, email click tracking redirects correctly.

### Step 5: Run tests and commit

```bash
php artisan test --compact tests/Feature/MediaHub/ViewTrackingTest.php
npm run build
git add -A && git commit -m "feat: add video view tracking with JS beacon and email open/click tracking"
```

---

## Task 7: Public Watch Hub

**Files:**
- Create: `resources/views/components/public/⚡watch-hub.blade.php`
- Create: `resources/views/components/public/⚡watch-channel.blade.php`
- Create: `resources/views/components/public/⚡watch-player.blade.php`
- Create: `resources/views/components/public/⚡watch-channel-card.blade.php`
- Create: `resources/views/components/public/⚡watch-video-card.blade.php`
- Create: `resources/views/layouts/watch.blade.php` (public layout)
- Test: `tests/Feature/MediaHub/WatchHubTest.php`

### Step 1: Create public watch layout

`resources/views/layouts/watch.blade.php` - Clean public layout with company branding from `CompanySetting`. Header with company logo and name, no sidebar, no auth required. Footer with company info and link to main site.

### Step 2: Watch hub landing page

`⚡watch-hub.blade.php` - Hero section with company name + "Video Library" heading. Grid of public active channels rendered as `⚡watch-channel-card` components. Optional search bar for filtering videos across all channels.

### Step 3: Channel card component

`⚡watch-channel-card.blade.php` - Thumbnail, channel name, video count, description snippet. Links to `/watch/{slug}`.

### Step 4: Channel view page

`⚡watch-channel.blade.php` - Channel banner with name and description. Grid of published videos in sort order. Each video as `⚡watch-video-card`.

### Step 5: Video card component (public)

`⚡watch-video-card.blade.php` - Thumbnail with play icon overlay, title, duration badge, view count. Links to `/watch/{slug}/{recording}`.

### Step 6: Video player page

`⚡watch-player.blade.php` - Full video player, title, description below. "Up Next" sidebar with other videos from the same channel. Includes the JS video tracker. Source = 'channel'.

### Step 7: Write tests

Test: hub shows public channels only, private channels hidden, channel page shows published videos only, player page renders, video player includes tracker script, 404 for inactive channels.

### Step 8: Run tests and commit

```bash
php artisan test --compact tests/Feature/MediaHub/WatchHubTest.php
git add -A && git commit -m "feat: add public /watch hub with channels and video player"
```

---

## Task 8: Quick Recorder

**Files:**
- Create: `resources/views/components/media/⚡quick-recorder.blade.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php` (add recorder button)
- Create: `resources/views/components/media/⚡post-record-actions.blade.php`
- Test: `tests/Feature/MediaHub/QuickRecorderTest.php`

### Step 1: Quick recorder component

`⚡quick-recorder.blade.php` - Floating overlay that uses `navigator.mediaDevices.getUserMedia()` for webcam access. Uses existing chunked upload API (`/api/video-recordings/start`, `/chunk`, `/finalize`). States: idle, recording, uploading, done.

UI: Compact recording controls - record/stop button, timer, camera preview. When done, transitions to post-record actions.

This component should be ~100 lines of PHP + ~100 lines of Blade/JS. The JS recording logic should use the same `MediaRecorder` API pattern already used in the Studio component.

### Step 2: Post-record actions component

`⚡post-record-actions.blade.php` - After recording finishes: title input, then three action buttons:
- Copy Link (copies share URL to clipboard)
- Send via Email (opens email modal)
- Add to Channel (opens channel picker)

### Step 3: Add to sidebar

In `resources/views/layouts/app/sidebar.blade.php`, add the recorder button near the top of the sidebar header or in the global area (after the notification center). Permission-gated with `media.record`:

```blade
@can('media.record')
    @livewire('media.quick-recorder')
@endcan
```

Place this in the `@auth` section at the bottom of the sidebar, before the user menu, or as a fixed floating button.

### Step 4: Write tests

Test: recorder component renders for users with permission, hidden without permission, integration with upload API (mock the upload).

### Step 5: Run tests and commit

```bash
php artisan test --compact tests/Feature/MediaHub/QuickRecorderTest.php
git add -A && git commit -m "feat: add quick recorder with sidebar integration"
```

---

## Task 9: Training Paths System

**Files:**
- Create: `resources/views/components/media/⚡training-list.blade.php`
- Create: `resources/views/components/media/⚡training-manage.blade.php`
- Create: `resources/views/components/media/⚡training-step-row.blade.php`
- Create: `resources/views/components/media/⚡training-dashboard.blade.php`
- Create: `resources/views/components/media/⚡training-center.blade.php` (employee view)
- Create: `resources/views/components/media/⚡training-player.blade.php` (step player)
- Test: `tests/Feature/MediaHub/TrainingTest.php`

### Step 1: Training list (admin tab)

`⚡training-list.blade.php` - Shows in Media Hub Training tab. Lists all training paths with: name, visibility, required badge, step count, active toggle. Create button opens editor. Permission: `media.manage_training`.

### Step 2: Training path editor

`⚡training-manage.blade.php` - Full page for managing a training path. Form: name, description, visibility (employees/crews/all_internal), is_required toggle. Below: ordered list of steps. Each step rendered as `⚡training-step-row`. Add step button (pick video from existing recordings).

### Step 3: Training step row

`⚡training-step-row.blade.php` - Single step: video thumbnail, title (editable), required toggle, sort handle for reordering, remove button. Compact inline component.

### Step 4: Training dashboard

`⚡training-dashboard.blade.php` - Completion stats. Table: employees as rows, path steps as columns. Cells show: watch percentage or checkmark. Filter by path. Shows overall completion percentage per employee.

### Step 5: Employee training center

`⚡training-center.blade.php` - Employee-facing view of assigned training paths. Shows: path name, progress bar, step list with completion status. Click a step to watch the video.

Can be embedded in existing employee dashboard or "My Work" section.

### Step 6: Training player

`⚡training-player.blade.php` - Video player for a training step. Includes JS tracker that updates `TrainingProgress`. Auto-marks completed when watch_percentage >= 90%. Shows next step button.

### Step 7: Add route for employee training center

Add to the "My Work" self-service routes section:
```php
Route::livewire('/my/training', 'media.training-center')->name('my.training');
```

Add to sidebar "My Work" group:
```blade
<flux:sidebar.item icon="academic-cap" :href="route('my.training')" :current="request()->routeIs('my.training')" wire:navigate>
    {{ __('My Training') }}
</flux:sidebar.item>
```

### Step 8: Write tests

Test: create training path, add steps, reorder steps, remove steps, completion tracking (auto-complete at 90%), employee sees assigned paths, dashboard shows correct completion percentages.

### Step 9: Run tests and commit

```bash
php artisan test --compact tests/Feature/MediaHub/TrainingTest.php
git add -A && git commit -m "feat: add training paths with progress tracking and employee training center"
```

---

## Task 10: Enhanced Share Page & Analytics Overview

**Files:**
- Modify: `app/Http/Controllers/VideoRecordingPlaybackController.php` (enhance existing)
- Create: `resources/views/components/media/⚡analytics-overview.blade.php`
- Test: `tests/Feature/MediaHub/AnalyticsTest.php`

### Step 1: Enhance existing share page

The existing `/v/{shareToken}` route already works. Enhance the playback controller/view to:
- Include company branding from `CompanySetting`
- Include the JS video tracker (source = 'link')
- Show video title and description
- If the video belongs to a public channel, show "See more videos" link to `/watch`
- Handle `?ref=email&eid={id}` params for email click tracking

### Step 2: Analytics overview tab

`⚡analytics-overview.blade.php` - Media Hub Analytics tab. Shows:
- Total views (last 7 days, last 30 days, all time)
- Top 5 most viewed videos
- Recent views table (video, viewer, watch %, source, time)
- Videos with no views (opportunity to promote)
- Email stats: sent, opened, clicked (conversion rates)

All data from `VideoView` and `VideoEmail` queries. Use `#[Computed]` properties for each stat block.

### Step 3: Write tests

Test: analytics queries return correct counts, enhanced share page includes tracker, email ref params tracked.

### Step 4: Run tests and commit

```bash
php artisan test --compact tests/Feature/MediaHub/AnalyticsTest.php
git add -A && git commit -m "feat: add analytics overview and enhanced share page"
```

---

## Task 11: Final Integration & Polish

**Files:**
- Run full test suite
- Run Pint formatter
- Verify all routes work end-to-end

### Step 1: Run Pint

```bash
vendor/bin/pint --dirty
```

### Step 2: Run full media hub tests

```bash
php artisan test --compact tests/Feature/MediaHub/
```

### Step 3: Run broader test suite to check for regressions

```bash
php artisan test --compact --exclude-group=browser
```

### Step 4: Final commit

```bash
git add -A && git commit -m "chore: format code and finalize media hub feature"
```

---

## Parallel Execution Strategy (Claude Agent Teams)

These tasks can be parallelized as follows:

**Phase 1 (Foundation) - Sequential:**
- Task 1: Database Migrations & Models (everything depends on this)
- Task 2: Permissions & Routes (needed for all UI)

**Phase 2 (Core UI) - Parallel after Phase 1:**
- Agent A: Task 3 (Media Hub Dashboard) + Task 5 (Video Detail Page)
- Agent B: Task 4 (Channel Management) + Task 7 (Public Watch Hub)
- Agent C: Task 6 (View Tracking System)

**Phase 3 (Features) - Parallel after Phase 2:**
- Agent A: Task 8 (Quick Recorder)
- Agent B: Task 9 (Training Paths)

**Phase 4 (Polish) - Sequential after Phase 3:**
- Task 10: Enhanced Share Page & Analytics
- Task 11: Final Integration & Polish

This gives us 4 phases with maximum parallelism in phases 2 and 3.
