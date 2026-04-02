# Campaign-Based Routing & Pipeline Removal

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove pipeline-based queue from the dialer, make campaigns the only contact organization system, add per-campaign disposition routing, convert pipeline view to campaign list view, and add bulk contact enrollment.

**Architecture:** Each campaign defines disposition routes — a JSON mapping of disposition → action (move to campaign, remove, stay). The dialer queue is always campaign-driven. The pipeline view becomes a campaign list view showing all campaigns with progress. New contacts auto-enroll into a company's default campaign.

**Tech Stack:** Laravel 12, Livewire 4, Flux UI

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `database/migrations/xxxx_add_disposition_routes_to_campaigns.php` | Create | disposition_routes JSON column |
| `app/Models/Prospector/Campaign.php` | Modify | Add disposition routing logic |
| `app/Services/Prospector/DialerOrchestrationService.php` | Modify | Use disposition routes instead of next_campaign_id |
| `resources/views/pages/prospector/⚡dialer.blade.php` | Modify | Remove pipeline refs, campaign-only queue |
| `resources/views/pages/prospector/⚡campaign-detail.blade.php` | Modify | Add disposition route editor, bulk add all contacts |
| `resources/views/pages/prospector/⚡pipeline-view.blade.php` | Rebuild | Convert to campaign list view |
| `tests/Feature/Prospector/CampaignDispositionRoutingTest.php` | Create | Disposition routing tests |

---

### Task 1: Migration — Add disposition_routes to campaigns

**Files:**
- Create: migration

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_disposition_routes_to_campaigns --table=prospector_campaigns --no-interaction
```

```php
public function up(): void
{
    Schema::table('prospector_campaigns', function (Blueprint $table) {
        $table->json('disposition_routes')->nullable()->after('auto_dial');
    });
}

public function down(): void
{
    Schema::table('prospector_campaigns', function (Blueprint $table) {
        $table->dropColumn('disposition_routes');
    });
}
```

The `disposition_routes` JSON structure:
```json
{
    "no_answer": {"action": "stay"},
    "not_interested": {"action": "move", "target_campaign_id": 5},
    "callback": {"action": "stay"},
    "interested": {"action": "move", "target_campaign_id": 3},
    "booked": {"action": "remove"},
    "dnc": {"action": "dnc"},
    "wrong_number": {"action": "remove"},
    "voicemail": {"action": "stay"},
    "busy": {"action": "stay"}
}
```

Actions: `stay` (re-queue in same campaign), `move` (move to target_campaign_id), `remove` (remove from all campaigns), `dnc` (mark DNC + remove)

- [ ] **Step 2: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/
git commit -m "feat(prospector): add disposition_routes JSON column to campaigns"
```

---

### Task 2: Campaign Model — Disposition Routing Logic

**Files:**
- Modify: `app/Models/Prospector/Campaign.php`

- [ ] **Step 1: Add disposition_routes to fillable and casts**

Add `'disposition_routes'` to `$fillable`. Add cast: `'disposition_routes' => 'array'`.

- [ ] **Step 2: Add routing method**

```php
/**
 * Get the routing action for a given disposition.
 * Returns: ['action' => 'stay|move|remove|dnc', 'target_campaign_id' => ?int]
 */
public function getDispositionRoute(string $disposition): array
{
    $routes = $this->disposition_routes ?? [];
    $route = $routes[$disposition] ?? null;

    if (! $route) {
        // Default behavior based on disposition type
        return match ($disposition) {
            'dnc' => ['action' => 'dnc'],
            'booked' => ['action' => 'remove'],
            'wrong_number' => ['action' => 'remove'],
            default => ['action' => 'stay'],
        };
    }

    return $route;
}

/**
 * Get default disposition routes with all known dispositions.
 */
public static function defaultDispositionRoutes(): array
{
    $dispositions = config('prospector.dispositions', []);
    $routes = [];

    foreach ($dispositions as $key => $config) {
        $routes[$key] = match ($key) {
            'dnc' => ['action' => 'dnc'],
            'booked' => ['action' => 'remove'],
            'wrong_number' => ['action' => 'remove'],
            default => ['action' => 'stay'],
        };
    }

    return $routes;
}
```

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Prospector/Campaign.php
git commit -m "feat(prospector): campaign disposition routing logic with defaults"
```

---

### Task 3: Update DialerOrchestrationService — Use Disposition Routes

**Files:**
- Modify: `app/Services/Prospector/DialerOrchestrationService.php`
- Create: `tests/Feature/Prospector/CampaignDispositionRoutingTest.php`

- [ ] **Step 1: Write failing tests**

```bash
php artisan make:test Prospector/CampaignDispositionRoutingTest --pest --no-interaction
```

Tests should cover:
1. Disposition "stay" → contact remains in same campaign, status reset to queued
2. Disposition "move" → contact moves to target campaign
3. Disposition "remove" → contact removed from campaign (status = completed)
4. Disposition "dnc" → contact marked do_not_call, removed from campaign
5. Disposition "booked" defaults to remove even without explicit route

- [ ] **Step 2: Update the disposition method**

In `DialerOrchestrationService`, find the disposition/dispositionCall method. After marking the call completed, apply the campaign's disposition route:

```php
// Get the routing action for this disposition
$route = $campaign->getDispositionRoute($disposition);

switch ($route['action']) {
    case 'stay':
        // Re-queue the contact (already handled by existing logic)
        $cc->update(['status' => 'queued']);
        break;

    case 'move':
        $cc->exhaust();
        if (! empty($route['target_campaign_id'])) {
            CampaignContact::firstOrCreate(
                ['campaign_id' => $route['target_campaign_id'], 'contact_id' => $cc->contact_id],
                ['status' => 'queued', 'calls_today' => 0, 'total_attempts' => 0, 'entered_at' => now(), 'sort_order' => 0, 'attempts' => 0],
            );
        }
        break;

    case 'remove':
        $cc->update(['status' => 'completed']);
        break;

    case 'dnc':
        $cc->update(['status' => 'completed']);
        $contact->update(['do_not_call' => true]);
        break;
}
```

- [ ] **Step 3: Run tests, Pint, commit**

```bash
php artisan test --filter=CampaignDispositionRoutingTest --compact
vendor/bin/pint --dirty --format agent
git add app/Services/Prospector/DialerOrchestrationService.php tests/Feature/Prospector/CampaignDispositionRoutingTest.php
git commit -m "feat(prospector): disposition routing — stay/move/remove/dnc actions per campaign"
```

---

### Task 4: Campaign Detail — Disposition Route Editor + Bulk Add All

**Files:**
- Modify: `resources/views/pages/prospector/⚡campaign-detail.blade.php`

- [ ] **Step 1: Add disposition route editing to PHP section**

Add properties for editing routes:
```php
public array $dispositionRoutes = [];

// In mount(), after hydrating call rules:
$this->dispositionRoutes = $campaign->disposition_routes ?? Campaign::defaultDispositionRoutes();
```

Add save method:
```php
public function saveDispositionRoutes(): void
{
    $campaign = Campaign::findOrFail($this->campaignId);
    $campaign->update(['disposition_routes' => $this->dispositionRoutes]);
    $this->clearCaches();
}

public function updateDispositionRoute(string $disposition, string $action, ?int $targetCampaignId = null): void
{
    $this->dispositionRoutes[$disposition] = [
        'action' => $action,
        'target_campaign_id' => $targetCampaignId,
    ];
    $this->saveDispositionRoutes();
}
```

Add bulk enrollment method:
```php
public function addAllCompanyContacts(): void
{
    $campaign = Campaign::findOrFail($this->campaignId);
    $existingContactIds = CampaignContact::where('campaign_id', $this->campaignId)->pluck('contact_id');
    $maxSort = CampaignContact::where('campaign_id', $this->campaignId)->max('sort_order') ?? 0;

    $contacts = Contact::where('company_id', $campaign->company_id)
        ->where('do_not_call', false)
        ->whereNotIn('id', $existingContactIds)
        ->get();

    $index = 0;
    foreach ($contacts as $contact) {
        CampaignContact::create([
            'campaign_id' => $this->campaignId,
            'contact_id' => $contact->id,
            'status' => 'queued',
            'sort_order' => $maxSort + $index + 1,
            'attempts' => 0,
            'added_at' => now(),
        ]);
        $index++;
    }

    $campaign->refreshContactCount();
    $this->clearCaches();
}
```

- [ ] **Step 2: Add disposition routing UI section**

Add a "Disposition Routing" card below the Call Rules card. For each disposition, show a row with:
- Disposition label
- Action dropdown: Stay / Move to Campaign / Remove / DNC
- Campaign selector (only visible when action = Move)

```blade
{{-- Disposition Routing --}}
<div class="mb-6 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800" x-data="{ open: false }">
    <button x-on:click="open = !open" class="flex w-full items-center justify-between px-5 py-4 text-left">
        <div class="flex items-center gap-2">
            <flux:icon name="arrows-right-left" variant="mini" class="text-zinc-400" />
            <flux:heading size="sm">Disposition Routing</flux:heading>
            <flux:text class="text-zinc-400">What happens after each call outcome</flux:text>
        </div>
        <flux:icon name="chevron-down" variant="mini" class="text-zinc-400 transition" ::class="open && 'rotate-180'" />
    </button>

    <div x-show="open" x-collapse class="border-t border-zinc-200 px-5 pb-5 pt-4 dark:border-zinc-700">
        <div class="space-y-3">
            @foreach(config('prospector.dispositions', []) as $key => $config)
                @php
                    $route = $dispositionRoutes[$key] ?? ['action' => 'stay'];
                    $action = $route['action'] ?? 'stay';
                    $targetId = $route['target_campaign_id'] ?? null;
                @endphp
                <div class="flex items-center gap-3" wire:key="route-{{ $key }}">
                    <div class="w-36 text-sm font-medium">{{ $config['label'] ?? ucfirst($key) }}</div>
                    <flux:select
                        class="w-44"
                        wire:change="updateDispositionRoute('{{ $key }}', $event.target.value)"
                    >
                        <option value="stay" @selected($action === 'stay')>Stay in campaign</option>
                        <option value="move" @selected($action === 'move')>Move to campaign</option>
                        <option value="remove" @selected($action === 'remove')>Remove (complete)</option>
                        <option value="dnc" @selected($action === 'dnc')>Do Not Call</option>
                    </flux:select>
                    @if($action === 'move')
                        <flux:select
                            class="w-48"
                            wire:change="updateDispositionRoute('{{ $key }}', 'move', $event.target.value)"
                        >
                            <option value="">Select campaign...</option>
                            @foreach($this->otherCampaigns as $camp)
                                <option value="{{ $camp->id }}" @selected($targetId == $camp->id)>{{ $camp->name }}</option>
                            @endforeach
                        </flux:select>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
```

- [ ] **Step 3: Add "Add All Contacts" button**

Next to the existing "Add Contacts" button in the toolbar:

```blade
<flux:button variant="filled" icon="users" wire:click="addAllCompanyContacts"
    wire:confirm="Add all company contacts to this campaign?">
    Add All Contacts
</flux:button>
```

- [ ] **Step 4: Pint, commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/prospector/⚡campaign-detail.blade.php"
git commit -m "feat(prospector): disposition routing editor + bulk add all contacts to campaign"
```

---

### Task 5: Remove Pipeline from Dialer — Campaign-Only Queue

**Files:**
- Modify: `resources/views/pages/prospector/⚡dialer.blade.php`

- [ ] **Step 1: Remove SmartQueueService references**

- Remove `use App\Services\Prospector\SmartQueueService;` import
- Remove `syncQueue()` method (the pipeline-based one)
- Remove any `SmartQueueService` usage from PHP methods (check `dialNext`, `skipContact`, `quickDisposition`)
- Remove `$filterStageId` property and stage filter references
- Remove pipeline stage tabs/buttons from template

- [ ] **Step 2: Make loadQueue always use campaign queue**

In Alpine, change `loadQueue()` to always use campaign queue:

```javascript
async loadQueue() {
    if ($wire.selectedCampaignId) {
        this.queueItems = await $wire.syncCampaignQueue();
    } else {
        this.queueItems = [];
    }
},
```

- [ ] **Step 3: Remove stage filter tabs from template**

Remove the stage filter buttons (All, New lead, Follow up, Phone call, etc.) from the queue sidebar. The queue is now just the campaign's contacts.

- [ ] **Step 4: Require campaign selection**

When no campaign is selected, show a message instead of an empty queue:
```blade
<div x-show="!$wire.selectedCampaignId" class="p-4 text-center text-sm text-zinc-400">
    Select a campaign to see the queue
</div>
```

- [ ] **Step 5: Build, Pint, commit**

```bash
npm run build
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/prospector/⚡dialer.blade.php"
git commit -m "feat(prospector): remove pipeline from dialer — campaign-only queue"
```

---

### Task 6: Convert Pipeline View to Campaign List View

**Files:**
- Rebuild: `resources/views/pages/prospector/⚡pipeline-view.blade.php`

- [ ] **Step 1: Rebuild as campaign list view**

Replace the pipeline kanban board with a campaign list showing:
- Campaign name, company, status badge
- Contact counts: queued / completed / total
- Call rules summary (3x/day, 30 days)
- Connection rate
- Progress bar (completed / total)
- Click to go to campaign detail page

The view should show all campaigns for the agent's companies, grouped by company if multiple.

- [ ] **Step 2: Update navigation label**

If the sidebar says "Pipeline" or "Pipeline View", update it to "Campaign Lists" or just "Lists". Check `routes/prospector.php` and the sidebar navigation.

- [ ] **Step 3: Pint, commit**

```bash
vendor/bin/pint --dirty --format agent
git add "resources/views/pages/prospector/⚡pipeline-view.blade.php"
git commit -m "feat(prospector): convert pipeline view to campaign list view"
```

---

### Task 7: Deploy and Test

- [ ] **Step 1: Run Prospector tests**

```bash
php artisan test --filter=Prospector --compact
```

- [ ] **Step 2: Push and migrate production**

```bash
git push origin main
ssh forge@paintingmadeeasydenver.com "cd /home/forge/paintingmadeeasydenver.com && php artisan migrate --force && php artisan optimize:clear"
```

- [ ] **Step 3: Test on production**

1. Create a campaign with call rules
2. Set disposition routes (configure where each outcome sends contacts)
3. Use "Add All Contacts" to bulk enroll
4. Open dialer — verify campaign selector required, no pipeline references
5. Check campaign list view shows all campaigns with progress
