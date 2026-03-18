# Active Call Bottom Bar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the active call banner from the top of the screen to sit above the footer, collapsible upward, so mobile PWA users can reach Transfer and Hang Up buttons.

**Architecture:** Two-file change — the sidebar layout gets the `active-call` Livewire include moved out of overlays and into the layout flow above `flux:footer`. The active-call component itself is restyled from `fixed top-0` to flow layout with a two-row collapsible design (info row always visible, actions row toggles upward via Alpine). A new `closeTransferPicker()` method is added to reset picker state on collapse.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Alpine.js (bundled with Livewire/Flux), Tailwind CSS v4, Flux UI free

---

## File Map

| File | Change |
|------|--------|
| `resources/views/layouts/app/sidebar.blade.php` | Move `active-call` include above `flux:footer`, inside the existing auth/isInternal block |
| `resources/views/components/communications/⚡active-call.blade.php` | Remove `fixed top-0 z-50`, add two-row collapsible layout, reset timer on connect, `closeTransferPicker()` method, picker opens upward |

---

### Task 1: Move active-call include in layout

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php`

- [ ] **Step 1: Open the layout file and locate the two relevant blocks**

  The file has two blocks to change:
  1. The footer block (around line 347–354):
     ```blade
     @auth
         @if (auth()->user()->isInternal())
             <flux:footer class="!p-0">
                 @livewire('layout.footer-bar')
             </flux:footer>
         @endif
     @endauth
     ```
  2. The overlays block (around line 357–368) which contains `@livewire('communications.active-call')` inside a `@can('phone.make_calls')` check.

- [ ] **Step 2: Merge the footer block and active-call into one `@auth` block**

  Replace the footer block with:
  ```blade
  @auth
      @if (auth()->user()->isInternal())
          @can('phone.make_calls')
              @livewire('communications.active-call')
          @endcan
          <flux:footer class="!p-0">
              @livewire('layout.footer-bar')
          </flux:footer>
      @endif
  @endauth
  ```

- [ ] **Step 3: Remove active-call from the overlays block**

  In the overlays block further down, find:
  ```blade
  @can('phone.make_calls')
      @livewire('communications.dial-pad')
      @livewire('communications.active-call')
  @endcan
  ```
  Change it to:
  ```blade
  @can('phone.make_calls')
      @livewire('communications.dial-pad')
  @endcan
  ```

- [ ] **Step 4: Verify the layout file looks correct**

  The order in the body should now be:
  1. `{{ $slot }}` — page content
  2. `@auth @if(isInternal())` block containing: active-call → flux:footer
  3. Overlays block (notifications, dial-pad, etc.) — no active-call here

- [ ] **Step 5: Commit**

  ```bash
  git add resources/views/layouts/app/sidebar.blade.php
  git commit -m "feat(phone): move active-call include above footer in layout"
  ```

---

### Task 2: Restyle the active-call component

**Files:**
- Modify: `resources/views/components/communications/⚡active-call.blade.php`

This task rewrites the Blade template section only. The PHP class gets one new method. Read the current file fully before editing.

- [ ] **Step 1: Add `closeTransferPicker()` to the PHP class**

  In the PHP class section, add this method after `toggleTransferPicker()`:
  ```php
  public function closeTransferPicker(): void
  {
      $this->showTransferPicker = false;
  }
  ```

- [ ] **Step 2: Replace the entire Blade template section**

  Replace everything from `<div>` to the closing `</div>` (the template part after `?>`) with:

  ```blade
  <div>
      @if ($isActive)
          <div
              x-data="{
                  expanded: true,
                  seconds: 0,
                  timer: null,
                  init() {
                      {{-- seconds resets naturally to 0 because @if ($isActive) causes Alpine to remount fresh on each new call --}}
                      this.timer = setInterval(() => this.seconds++, 1000);
                  },
                  destroy() {
                      if (this.timer) clearInterval(this.timer);
                  },
                  formatTime() {
                      const m = Math.floor(this.seconds / 60).toString().padStart(2, '0');
                      const s = (this.seconds % 60).toString().padStart(2, '0');
                      return m + ':' + s;
                  },
                  collapse() {
                      this.expanded = false;
                      $wire.closeTransferPicker();
                  }
              }"
              class="bg-green-700 dark:bg-green-800 text-white"
          >
              {{-- Actions row (expands upward, shown above info row) --}}
              <div
                  x-show="expanded"
                  x-transition
                  class="flex items-center gap-2 px-4 py-2 border-b border-green-600"
              >
                  {{-- Transfer button --}}
                  @can('phone.transfer_calls')
                      <div class="relative flex-1">
                          <button
                              wire:click="toggleTransferPicker"
                              class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-white/20 hover:bg-white/30 transition-colors"
                              title="{{ __('Transfer') }}"
                          >
                              <flux:icon name="arrow-right-start-on-rectangle" class="size-4" />
                              <span>{{ __('Transfer') }}</span>
                          </button>

                          {{-- Transfer picker — opens upward --}}
                          @if ($showTransferPicker)
                              <div class="absolute left-0 bottom-full mb-2 w-56 bg-white dark:bg-zinc-800 rounded-lg shadow-xl border border-zinc-200 dark:border-zinc-700 py-1 z-50">
                                  <p class="px-3 py-1.5 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                      {{ __('Transfer to') }}
                                  </p>
                                  @forelse ($this->internalUsers as $user)
                                      <button
                                          wire:key="transfer-user-{{ $user->id }}"
                                          wire:click="transfer('{{ $user->extension }}')"
                                          class="w-full text-left px-3 py-2 text-sm text-zinc-900 dark:text-white hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors flex items-center justify-between"
                                      >
                                          <span class="truncate">{{ $user->name }}</span>
                                          <span class="text-xs text-zinc-400 dark:text-zinc-500 font-mono">x{{ $user->extension }}</span>
                                      </button>
                                  @empty
                                      <p class="px-3 py-2 text-sm text-zinc-400 dark:text-zinc-500">
                                          {{ __('No users available') }}
                                      </p>
                                  @endforelse
                              </div>
                          @endif
                      </div>
                  @endcan

                  {{-- Hang up button --}}
                  <button
                      wire:click="hangup"
                      class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-red-500 hover:bg-red-600 transition-colors ml-auto"
                      title="{{ __('Hang Up') }}"
                  >
                      <flux:icon name="phone-x-mark" variant="solid" class="size-4" />
                      <span>{{ __('Hang Up') }}</span>
                  </button>
              </div>

              {{-- Info row — always visible --}}
              <div class="px-4 py-2.5 flex items-center justify-between gap-4">
                  {{-- Caller info --}}
                  <div class="flex items-center gap-3 min-w-0">
                      <div class="shrink-0 flex items-center gap-1.5">
                          <span class="relative flex size-2.5">
                              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white/75"></span>
                              <span class="relative inline-flex rounded-full size-2.5 bg-white"></span>
                          </span>
                          <flux:icon name="phone" variant="solid" class="size-4" />
                      </div>
                      <div class="min-w-0">
                          <p class="text-sm font-medium truncate">
                              {{ $callerName ?? $callerNumber ?? __('Active Call') }}
                          </p>
                          @if ($callerName && $callerNumber)
                              <p class="text-xs text-green-200 truncate">{{ $callerNumber }}</p>
                          @endif
                      </div>
                  </div>

                  {{-- Timer + chevron --}}
                  <div class="flex items-center gap-3 shrink-0">
                      <span class="text-sm font-mono tabular-nums" x-text="formatTime()">00:00</span>
                      <button
                          @click="expanded ? collapse() : expanded = true"
                          class="text-white/80 hover:text-white transition-colors"
                          :title="expanded ? '{{ __('Collapse') }}' : '{{ __('Expand') }}'"
                      >
                          <flux:icon name="chevron-up" class="size-4 transition-transform" :class="expanded ? '' : 'rotate-180'" />
                      </button>
                  </div>
              </div>
          </div>
      @endif
  </div>
  ```

- [ ] **Step 3: Run Pint to fix formatting**

  ```bash
  vendor/bin/pint --dirty resources/views/components/communications/⚡active-call.blade.php
  ```

- [ ] **Step 4: Commit**

  ```bash
  git add "resources/views/components/communications/⚡active-call.blade.php"
  git commit -m "feat(phone): redesign active call bar as collapsible bottom widget"
  ```

---

### Task 3: Test in browser

The active-call component fires via WebSocket events — it cannot be tested with standard Pest feature tests without a Twilio call in flight. Manual verification is the right approach here.

- [ ] **Step 1: Open the app on your mobile PWA**

  Navigate to any internal page. Confirm the footer bar is still visible and functional (notifications, phone, messages, email).

- [ ] **Step 2: Simulate an active call (Tinker)**

  In a terminal on the server, dispatch the `CallConnected` event to your user's channel to trigger the component without a real call:

  ```bash
  php artisan tinker
  ```
  ```php
  $user = \App\Models\User::where('email', 'your@email.com')->first();
  event(new \App\Events\CallConnected([
      'call_log_id' => 999,
      'ulid' => 'test-ulid',
      'caller_name' => 'Test Customer',
      'from_number' => '+17205550000',
      'customer_id' => null,
      'answered_at' => now()->toISOString(),
  ], $user->id));
  ```

  Expected: green bar appears above footer showing "Test Customer" + 00:00 timer + Transfer + Hang Up buttons.

- [ ] **Step 3: Verify collapse/expand**

  Tap the chevron. Expected: actions row fades out. Chevron rotates to point down. Tap again — actions row fades back in.

- [ ] **Step 4: Verify Transfer picker opens upward**

  Tap Transfer. Expected: picker dropdown appears above the button, not below (not obscured by footer).

- [ ] **Step 5: Verify collapse closes transfer picker**

  Open the transfer picker, then tap the chevron to collapse. Expected: picker closes, bar collapses.

- [ ] **Step 6: Simulate call end**

  ```php
  event(new \App\Events\CallEnded(['call_log_id' => 999], $user->id));
  ```

  Expected: green bar disappears instantly.

- [ ] **Step 7: Verify footer still works**

  Tap all footer buttons (notifications, phone dial pad, messages, email). Confirm none were broken by the layout change.
