# Footer Action Bar Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace floating FABs (notification bell, camera recorder, chat dock) with a fixed footer bar containing Notifications, Record, and Messages buttons for internal users.

**Architecture:** Single new Livewire SFC component rendered in the sidebar layout. It contains real-time unread counting (notifications + comms) and renders the existing quick-recorder component inside itself. The chat dock and notification center floating panels are removed entirely — clicking Notifications or Messages navigates to the Communications Hub page.

**Tech Stack:** Livewire 4 SFC, Tailwind CSS v4, Flux UI, Alpine.js (for recorder trigger)

**Design doc:** `docs/plans/2026-02-26-footer-action-bar-design.md`

---

### Task 1: Create the footer bar component with static buttons

**Files:**
- Create: `resources/views/components/layout/⚡footer-bar.blade.php`

**Step 1: Create the Livewire SFC component**

Create `resources/views/components/layout/⚡footer-bar.blade.php`:

```blade
<?php

use App\Models\EmailMessage;
use App\Services\ChatService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->isInternal()) {
            abort(403);
        }
    }

    public function getListeners(): array
    {
        $userId = auth()->id();

        return [
            "echo-private:App.Models.User.{$userId},.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated" => 'refreshCounts',
            "echo-private:chat.user.{$userId},.thread.created" => 'refreshCounts',
            "echo-private:chat.user.{$userId},.message.sent" => 'refreshCounts',
            "echo-private:email.user.{$userId},.email.received" => 'refreshCounts',
            'message-sent' => 'refreshCounts',
        ];
    }

    public function refreshCounts(): void
    {
        unset($this->notificationCount, $this->messageCount);
    }

    #[Computed]
    public function notificationCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    #[Computed]
    public function messageCount(): int
    {
        $user = auth()->user();
        $count = 0;

        if ($user->can('chat.access')) {
            $count += app(ChatService::class)->getUnreadCountForUser($user);
        }

        if ($user->can('email.access')) {
            $account = $user->emailAccount;
            if ($account) {
                $count += EmailMessage::where('email_account_id', $account->id)
                    ->withLabel('inbox')
                    ->unread()
                    ->count();
            }
        }

        return $count;
    }
};
?>

<div wire:poll.30s="refreshCounts">
    {{-- Footer action bar --}}
    <div class="fixed bottom-0 inset-x-0 z-40 lg:pl-64" style="padding-bottom: env(safe-area-inset-bottom, 0px)">
        <div class="flex items-stretch border-t border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            {{-- Notifications --}}
            <a href="{{ route('communications.index') }}" wire:navigate
               class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 transition-colors">
                <div class="relative">
                    <flux:icon name="bell" variant="outline" class="size-5" />
                    @if ($this->notificationCount > 0)
                        <span class="absolute -top-1.5 -right-2 bg-red-500 text-white text-[10px] rounded-full min-w-4 h-4 flex items-center justify-center px-0.5 font-bold">
                            {{ $this->notificationCount > 99 ? '99+' : $this->notificationCount }}
                        </span>
                    @endif
                </div>
                <span class="hidden sm:inline">{{ __('Notifications') }}</span>
            </a>

            {{-- Record --}}
            @can('media.record')
                <button
                    x-data
                    x-on:click="$dispatch('open-quick-recorder')"
                    class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 transition-colors border-x border-zinc-200 dark:border-zinc-700">
                    <flux:icon name="video-camera" variant="outline" class="size-5" />
                    <span class="hidden sm:inline">{{ __('Record') }}</span>
                </button>
            @endcan

            {{-- Messages --}}
            <a href="{{ route('communications.index') }}" wire:navigate
               class="flex-1 flex items-center justify-center gap-2 py-3 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 transition-colors">
                <div class="relative">
                    <flux:icon name="chat-bubble-left-right" variant="outline" class="size-5" />
                    @if ($this->messageCount > 0)
                        <span class="absolute -top-1.5 -right-2 bg-red-500 text-white text-[10px] rounded-full min-w-4 h-4 flex items-center justify-center px-0.5 font-bold">
                            {{ $this->messageCount > 99 ? '99+' : $this->messageCount }}
                        </span>
                    @endif
                </div>
                <span class="hidden sm:inline">{{ __('Messages') }}</span>
            </a>
        </div>
    </div>
</div>
```

**Step 2: Commit**

```bash
git add resources/views/components/layout/⚡footer-bar.blade.php
git commit -m "feat: create footer action bar component"
```

---

### Task 2: Wire footer bar into layout and remove floating FABs

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php:334-352` (replace floating FABs block)

**Step 1: Replace the floating components block in the sidebar layout**

In `resources/views/layouts/app/sidebar.blade.php`, find lines 334-352:

```blade
        {{-- Global notification listener for internal users --}}
        @auth
            @if (auth()->user()->isInternal())
                {{-- Floating bottom-left button bar (bell + record) --}}
                <div class="fixed inset-x-0 z-50 pointer-events-none lg:pl-64" style="bottom: calc(1rem + env(safe-area-inset-bottom, 0px))">
                    <div class="pl-4 flex items-end gap-2">
                        @livewire('notifications.notification-center')
                        @can('media.record')
                            @livewire('media.quick-recorder')
                        @endcan
                    </div>
                </div>
                @livewire('notifications.new-lead-alert')
                @can('chat.access')
                    @persist('chat-dock')
                        @livewire('chat.chat-dock')
                    @endpersist
                @endcan
            @endif
        @endauth
```

Replace with:

```blade
        {{-- Footer action bar + global listeners for internal users --}}
        @auth
            @if (auth()->user()->isInternal())
                @livewire('layout.footer-bar')
                @livewire('notifications.new-lead-alert')
                @can('media.record')
                    @livewire('media.quick-recorder')
                @endcan
            @endif
        @endauth
```

Key changes:
- Removed the floating `<div>` wrapper with notification-center
- Removed the chat-dock and its `@persist` wrapper
- Added footer-bar component
- Kept new-lead-alert (it's a toast/modal, not a floating button)
- Kept quick-recorder (needed for the fullscreen overlay) but outside the footer bar

**Step 2: Add bottom padding to content area so it doesn't hide behind footer**

In the same file, find the `{{ $slot }}` line (around line 332). Add padding wrapper:

Find:
```blade
        {{ $slot }}
```

Replace with:
```blade
        <div class="pb-14">
            {{ $slot }}
        </div>
```

This ensures page content clears the fixed footer bar (56px = 3.5rem = `pb-14`).

**Step 3: Commit**

```bash
git add resources/views/layouts/app/sidebar.blade.php
git commit -m "feat: replace floating FABs with footer action bar in layout"
```

---

### Task 3: Add event listener to quick-recorder for footer bar trigger

**Files:**
- Modify: `resources/views/components/media/⚡quick-recorder.blade.php` (add Alpine event listener)

**Step 1: Check how the quick recorder currently opens**

The current quick-recorder has its own button that triggers `startCamera()` in Alpine. We need to also listen for a dispatched `open-quick-recorder` event from the footer bar's Record button.

In `resources/views/components/media/⚡quick-recorder.blade.php`, find the root `x-data` Alpine component. Add an `@open-quick-recorder.window` listener that calls the same `startCamera()` method.

Find the opening `<div x-data="{` block and add this listener attribute to the root div:

```
x-on:open-quick-recorder.window="startCamera()"
```

This way the footer bar's `$dispatch('open-quick-recorder')` will trigger the recorder overlay.

**Step 2: Hide the recorder's own floating button**

The quick-recorder currently renders its own floating camera button. Since the footer bar now handles that, hide the standalone button. Find the button element in the quick-recorder template (it's the red camera circle button) and add `class="hidden"` or wrap it in a conditional. Since we still need the component mounted for the overlay, just hide the trigger button by adding `hidden` to it.

Find the trigger button (red circle with camera icon) and add the `hidden` attribute so it's not visible but the component and its Alpine state still mount.

**Step 3: Run existing quick-recorder tests to verify nothing breaks**

Run: `php artisan test --compact tests/Feature/MediaHub/QuickRecorderTest.php`
Expected: All tests PASS

**Step 4: Commit**

```bash
git add resources/views/components/media/⚡quick-recorder.blade.php
git commit -m "feat: add event listener to quick-recorder for footer bar trigger"
```

---

### Task 4: Write tests for the footer bar component

**Files:**
- Create: `tests/Feature/Livewire/Layout/FooterBarTest.php`

**Step 1: Create the test file**

Run: `php artisan make:test --pest Livewire/Layout/FooterBarTest`

**Step 2: Write the tests**

```php
<?php

use App\Models\Estimate;
use App\Models\User;
use App\Notifications\EstimateAccepted;
use Livewire\Livewire;

it('renders for internal users', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertStatus(200)
        ->assertSee(__('Notifications'))
        ->assertSee(__('Messages'));
});

it('denies access to non-internal users', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer');

    Livewire::test('layout.footer-bar')
        ->assertForbidden();
});

it('shows notification count when there are unread notifications', function () {
    $user = User::factory()->internal()->create();

    $user->notify(new EstimateAccepted(
        Estimate::factory()->create()
    ));

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee('1');
});

it('shows zero badges when no unread items', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertDontSee('99+');
});

it('shows record button for users with media.record permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee(__('Record'));
});

it('hides record button for users without media.record permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertDontSee(__('Record'));
});

it('links to communications hub', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSeeHtml(route('communications.index'));
});
```

**Step 3: Run the tests**

Run: `php artisan test --compact tests/Feature/Livewire/Layout/FooterBarTest.php`
Expected: All tests PASS

**Step 4: Commit**

```bash
git add tests/Feature/Livewire/Layout/FooterBarTest.php
git commit -m "test: add footer action bar component tests"
```

---

### Task 5: Run full related test suite and verify

**Step 1: Run notification center tests (still used for its logic, but removed from layout)**

Run: `php artisan test --compact tests/Feature/Livewire/Notifications/NotificationCenterTest.php`
Expected: PASS (component still exists, just not rendered in layout)

**Step 2: Run chat dock tests**

Run: `php artisan test --compact tests/Feature/Livewire/Chat/ChatDockTest.php`
Expected: PASS (component still exists, just not rendered in layout)

**Step 3: Run quick recorder tests**

Run: `php artisan test --compact tests/Feature/MediaHub/QuickRecorderTest.php`
Expected: PASS

**Step 4: Run footer bar tests**

Run: `php artisan test --compact tests/Feature/Livewire/Layout/FooterBarTest.php`
Expected: PASS

**Step 5: Run pint**

Run: `vendor/bin/pint --dirty`

**Step 6: Final commit if pint made changes**

```bash
git add -u
git commit -m "style: apply pint formatting"
```
