<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Contact;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Campaign Detail')]
class extends Component
{
    public int $campaignId;

    // Filters
    public string $statusFilter = '';

    // Add contacts modal
    public bool $showAddContactsModal = false;
    public string $contactSearch = '';
    public array $selectedContacts = [];

    // Bulk actions
    public array $selectedQueueItems = [];
    public bool $selectAll = false;

    // Confirm remove modal
    public bool $showRemoveModal = false;
    public ?int $removingContactId = null;
    public string $removingContactName = '';

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    public function mount(int $campaignId): void
    {
        $this->campaignId = $campaignId;
        Campaign::findOrFail($campaignId);
    }

    #[Computed]
    public function campaign(): Campaign
    {
        return Campaign::query()
            ->with(['company', 'phoneNumber', 'createdBy'])
            ->findOrFail($this->campaignId);
    }

    #[Computed]
    public function stats(): array
    {
        $total = CampaignContact::where('campaign_id', $this->campaignId)->count();
        $queued = CampaignContact::where('campaign_id', $this->campaignId)->where('status', 'queued')->count();
        $completed = CampaignContact::where('campaign_id', $this->campaignId)->where('status', 'completed')->count();
        $skipped = CampaignContact::where('campaign_id', $this->campaignId)->where('status', 'skipped')->count();

        return [
            'total' => $total,
            'queued' => $queued,
            'completed' => $completed,
            'skipped' => $skipped,
            'connection_rate' => $this->campaign->getConnectionRate(),
        ];
    }

    #[Computed]
    public function queueContacts(): \Illuminate\Support\Collection
    {
        $query = CampaignContact::query()
            ->where('campaign_id', $this->campaignId)
            ->with('contact')
            ->orderBy('sort_order');

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        return $query->get();
    }

    #[Computed]
    public function availableContacts(): \Illuminate\Support\Collection
    {
        $companyId = $this->campaign->company_id;
        $existingContactIds = CampaignContact::where('campaign_id', $this->campaignId)
            ->pluck('contact_id');

        $query = Contact::query()
            ->where('company_id', $companyId)
            ->where('do_not_call', false)
            ->whereNotIn('id', $existingContactIds)
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($this->contactSearch) {
            $query->search($this->contactSearch);
        }

        return $query->limit(50)->get();
    }

    #[Computed]
    public function statusFilterOptions(): array
    {
        return [
            '' => __('All Statuses'),
            'queued' => __('Queued'),
            'dialing' => __('Dialing'),
            'completed' => __('Completed'),
            'skipped' => __('Skipped'),
        ];
    }

    // ──────────────────────────────────────
    // Campaign status controls
    // ──────────────────────────────────────

    public function activateCampaign(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $campaign = Campaign::findOrFail($this->campaignId);
        $campaign->update(['status' => 'active']);
        $this->clearCaches();
    }

    public function pauseCampaign(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $campaign = Campaign::findOrFail($this->campaignId);
        $campaign->update(['status' => 'paused']);
        $this->clearCaches();
    }

    public function completeCampaign(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $campaign = Campaign::findOrFail($this->campaignId);
        $campaign->update(['status' => 'completed']);
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Add contacts
    // ──────────────────────────────────────

    public function openAddContacts(): void
    {
        $this->contactSearch = '';
        $this->selectedContacts = [];
        $this->showAddContactsModal = true;
    }

    public function closeAddContacts(): void
    {
        $this->showAddContactsModal = false;
        $this->contactSearch = '';
        $this->selectedContacts = [];
    }

    public function addContacts(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (empty($this->selectedContacts)) {
            return;
        }

        $maxSort = CampaignContact::where('campaign_id', $this->campaignId)->max('sort_order') ?? 0;

        foreach ($this->selectedContacts as $index => $contactId) {
            CampaignContact::create([
                'campaign_id' => $this->campaignId,
                'contact_id' => $contactId,
                'status' => 'queued',
                'sort_order' => $maxSort + $index + 1,
                'attempts' => 0,
                'added_at' => now(),
            ]);
        }

        $campaign = Campaign::findOrFail($this->campaignId);
        $campaign->refreshContactCount();

        $this->closeAddContacts();
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Remove contact
    // ──────────────────────────────────────

    public function confirmRemove(int $campaignContactId): void
    {
        $entry = CampaignContact::where('campaign_id', $this->campaignId)
            ->with('contact')
            ->findOrFail($campaignContactId);

        $this->removingContactId = $campaignContactId;
        $this->removingContactName = $entry->contact?->getFullName() ?? __('Unknown');
        $this->showRemoveModal = true;
    }

    public function removeContact(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (! $this->removingContactId) {
            return;
        }

        CampaignContact::where('campaign_id', $this->campaignId)
            ->where('id', $this->removingContactId)
            ->delete();

        $campaign = Campaign::findOrFail($this->campaignId);
        $campaign->refreshContactCount();

        $this->cancelRemove();
        $this->clearCaches();
    }

    public function cancelRemove(): void
    {
        $this->showRemoveModal = false;
        $this->removingContactId = null;
        $this->removingContactName = '';
    }

    // ──────────────────────────────────────
    // Bulk actions
    // ──────────────────────────────────────

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedQueueItems = $this->queueContacts->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        } else {
            $this->selectedQueueItems = [];
        }
    }

    public function bulkRemove(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (empty($this->selectedQueueItems)) {
            return;
        }

        CampaignContact::where('campaign_id', $this->campaignId)
            ->whereIn('id', $this->selectedQueueItems)
            ->delete();

        $campaign = Campaign::findOrFail($this->campaignId);
        $campaign->refreshContactCount();

        $this->selectedQueueItems = [];
        $this->selectAll = false;
        $this->clearCaches();
    }

    public function bulkResetToQueued(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (empty($this->selectedQueueItems)) {
            return;
        }

        CampaignContact::where('campaign_id', $this->campaignId)
            ->whereIn('id', $this->selectedQueueItems)
            ->update(['status' => 'queued']);

        $this->selectedQueueItems = [];
        $this->selectAll = false;
        $this->clearCaches();
    }

    public function bulkSkip(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (empty($this->selectedQueueItems)) {
            return;
        }

        CampaignContact::where('campaign_id', $this->campaignId)
            ->whereIn('id', $this->selectedQueueItems)
            ->update(['status' => 'skipped']);

        $this->selectedQueueItems = [];
        $this->selectAll = false;
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Reorder queue
    // ──────────────────────────────────────

    public function moveUp(int $campaignContactId): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $entry = CampaignContact::where('campaign_id', $this->campaignId)->findOrFail($campaignContactId);
        $above = CampaignContact::where('campaign_id', $this->campaignId)
            ->where('sort_order', '<', $entry->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if ($above) {
            $tempSort = $entry->sort_order;
            $entry->update(['sort_order' => $above->sort_order]);
            $above->update(['sort_order' => $tempSort]);
            $this->clearCaches();
        }
    }

    public function moveDown(int $campaignContactId): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $entry = CampaignContact::where('campaign_id', $this->campaignId)->findOrFail($campaignContactId);
        $below = CampaignContact::where('campaign_id', $this->campaignId)
            ->where('sort_order', '>', $entry->sort_order)
            ->orderBy('sort_order')
            ->first();

        if ($below) {
            $tempSort = $entry->sort_order;
            $entry->update(['sort_order' => $below->sort_order]);
            $below->update(['sort_order' => $tempSort]);
            $this->clearCaches();
        }
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    protected function clearCaches(): void
    {
        unset($this->campaign, $this->stats, $this->queueContacts, $this->availableContacts);
    }

    protected function getStatusBadgeColor(string $status): string
    {
        return match ($status) {
            'queued' => 'blue',
            'dialing' => 'amber',
            'completed' => 'green',
            'skipped' => 'zinc',
            default => 'zinc',
        };
    }

    protected function getCampaignStatusColor(string $status): string
    {
        return match ($status) {
            'active' => 'green',
            'paused' => 'amber',
            'draft' => 'zinc',
            'completed' => 'blue',
            default => 'zinc',
        };
    }
}; ?>

<div>
    {{-- Back link + Header --}}
    <div class="mb-6">
        <flux:button variant="ghost" icon="arrow-left" :href="route('prospector.campaigns')" class="mb-3">
            {{ __('Back to Campaigns') }}
        </flux:button>

        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <flux:heading size="xl">{{ $this->campaign->name }}</flux:heading>
                    <flux:badge size="sm" :color="$this->getCampaignStatusColor($this->campaign->status)">
                        {{ ucfirst($this->campaign->status) }}
                    </flux:badge>
                </div>
                <div class="mt-1 flex items-center gap-4 text-sm text-zinc-500 dark:text-zinc-400">
                    @if ($this->campaign->company)
                        <span class="flex items-center gap-1">
                            <flux:icon name="building-office" variant="micro" />
                            {{ $this->campaign->company->name }}
                        </span>
                    @endif
                    <span class="flex items-center gap-1">
                        <flux:icon name="signal" variant="micro" />
                        {{ ucfirst($this->campaign->dialer_mode ?? 'preview') }} {{ __('Dialer') }}
                    </span>
                    @if ($this->campaign->phoneNumber)
                        <span class="flex items-center gap-1">
                            <flux:icon name="phone" variant="micro" />
                            {{ $this->campaign->phoneNumber->getDisplayNumber() }}
                        </span>
                    @endif
                    <span class="flex items-center gap-1">
                        <flux:icon name="chart-bar" variant="micro" />
                        {{ $this->campaign->getConnectionRate() }}% {{ __('Connection Rate') }}
                    </span>
                </div>
                @if ($this->campaign->description)
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $this->campaign->description }}</p>
                @endif
            </div>

            <div class="flex items-center gap-2">
                @if ($this->campaign->status !== 'active')
                    <flux:button variant="primary" icon="play" wire:click="activateCampaign">
                        {{ __('Activate') }}
                    </flux:button>
                @endif
                @if ($this->campaign->status === 'active')
                    <flux:button variant="filled" icon="pause" wire:click="pauseCampaign">
                        {{ __('Pause') }}
                    </flux:button>
                @endif
                @if ($this->campaign->status !== 'completed')
                    <flux:button variant="ghost" icon="check-circle" wire:click="completeCampaign">
                        {{ __('Complete') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </div>

    {{-- Stats Row --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">{{ $this->stats['total'] }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total Contacts') }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-2xl font-semibold text-blue-600 dark:text-blue-400">{{ $this->stats['queued'] }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Queued') }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-2xl font-semibold text-green-600 dark:text-green-400">{{ $this->stats['completed'] }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Completed') }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-2xl font-semibold text-zinc-500 dark:text-zinc-400">{{ $this->stats['skipped'] }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Skipped') }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">{{ $this->stats['connection_rate'] }}%</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Connection Rate') }}</div>
        </div>
    </div>

    {{-- Toolbar: Filter + Add Contacts + Bulk Actions --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <flux:select wire:model.live="statusFilter" class="w-44">
                @foreach ($this->statusFilterOptions as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            @if (count($selectedQueueItems) > 0)
                <div class="flex items-center gap-2">
                    <flux:badge size="sm" color="blue">{{ count($selectedQueueItems) }} {{ __('selected') }}</flux:badge>
                    <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="bulkResetToQueued" title="{{ __('Reset to Queued') }}">
                        {{ __('Reset') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" icon="forward" wire:click="bulkSkip" title="{{ __('Skip Selected') }}">
                        {{ __('Skip') }}
                    </flux:button>
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="bulkRemove" title="{{ __('Remove Selected') }}">
                        {{ __('Remove') }}
                    </flux:button>
                </div>
            @endif
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openAddContacts">
            {{ __('Add Contacts') }}
        </flux:button>
    </div>

    {{-- Contact Queue Table --}}
    @if ($this->queueContacts->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="users" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No contacts in queue') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Add contacts from your company list to start building the call queue.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" icon="plus" wire:click="openAddContacts">
                    {{ __('Add Contacts') }}
                </flux:button>
            </div>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="w-10">
                    <input
                        type="checkbox"
                        wire:model.live="selectAll"
                        class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
                    />
                </flux:table.column>
                <flux:table.column class="w-16">{{ __('Order') }}</flux:table.column>
                <flux:table.column>{{ __('Contact Name') }}</flux:table.column>
                <flux:table.column>{{ __('Phone') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Attempts') }}</flux:table.column>
                <flux:table.column>{{ __('Last Attempted') }}</flux:table.column>
                <flux:table.column>{{ __('Last Disposition') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->queueContacts as $entry)
                    <flux:table.row wire:key="queue-{{ $entry->id }}">
                        <flux:table.cell>
                            <input
                                type="checkbox"
                                wire:model.live="selectedQueueItems"
                                value="{{ $entry->id }}"
                                class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
                            />
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="font-mono text-sm text-zinc-500">{{ $entry->sort_order }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($entry->contact)
                                <span class="font-medium">{{ $entry->contact->getFullName() }}</span>
                            @else
                                <span class="text-zinc-400">{{ __('Deleted Contact') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($entry->contact)
                                <span class="font-mono text-sm">{{ $entry->contact->getDisplayPhone() }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" :color="$this->getStatusBadgeColor($entry->status)">
                                {{ ucfirst($entry->status) }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            {{ $entry->attempts }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($entry->last_attempted_at)
                                <span class="text-sm text-zinc-500">{{ $entry->last_attempted_at->diffForHumans() }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($entry->last_disposition)
                                <span class="text-sm">{{ ucfirst($entry->last_disposition) }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="chevron-up"
                                    wire:click="moveUp({{ $entry->id }})"
                                    title="{{ __('Move Up') }}"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="chevron-down"
                                    wire:click="moveDown({{ $entry->id }})"
                                    title="{{ __('Move Down') }}"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="x-circle"
                                    wire:click="confirmRemove({{ $entry->id }})"
                                    title="{{ __('Remove from Campaign') }}"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- Add Contacts Modal --}}
    <flux:modal wire:model="showAddContactsModal" variant="flyout" class="w-[500px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Add Contacts to Campaign') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Select contacts from your company to add to this campaign queue.') }}
                </flux:text>
            </div>

            <flux:input
                wire:model.live.debounce.300ms="contactSearch"
                :placeholder="__('Search by name, phone, or email...')"
                icon="magnifying-glass"
            />

            @if ($this->availableContacts->isEmpty())
                <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:text class="text-zinc-500">
                        @if ($contactSearch)
                            {{ __('No matching contacts found.') }}
                        @else
                            {{ __('All contacts are already in this campaign.') }}
                        @endif
                    </flux:text>
                </div>
            @else
                <div class="max-h-[400px] space-y-2 overflow-y-auto">
                    @foreach ($this->availableContacts as $contact)
                        <label
                            wire:key="add-contact-{{ $contact->id }}"
                            class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        >
                            <input
                                type="checkbox"
                                wire:model="selectedContacts"
                                value="{{ $contact->id }}"
                                class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
                            />
                            <div class="flex-1">
                                <span class="text-sm font-medium">{{ $contact->getFullName() }}</span>
                                <div class="flex items-center gap-3 text-xs text-zinc-400">
                                    <span class="font-mono">{{ $contact->getDisplayPhone() }}</span>
                                    @if ($contact->email)
                                        <span>{{ $contact->email }}</span>
                                    @endif
                                    @if ($contact->city && $contact->state)
                                        <span>{{ $contact->city }}, {{ $contact->state }}</span>
                                    @endif
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="flex gap-2">
                    <flux:button type="button" variant="ghost" wire:click="closeAddContacts" class="flex-1">{{ __('Cancel') }}</flux:button>
                    <flux:button
                        variant="primary"
                        wire:click="addContacts"
                        class="flex-1"
                        :disabled="empty($selectedContacts)"
                    >
                        {{ __('Add Selected') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- Remove Contact Confirmation Modal --}}
    <flux:modal wire:model="showRemoveModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Remove from Campaign') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Remove') }} <strong>{{ $removingContactName }}</strong> {{ __('from this campaign? They can be re-added later.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelRemove" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="removeContact" class="flex-1">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
