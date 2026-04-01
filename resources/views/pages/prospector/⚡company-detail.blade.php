<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\CallScript;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\CompanyBookingLink;
use App\Models\Prospector\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Company Detail')]
class extends Component
{
    public int $companyId;

    // Assign number modal
    public bool $showAssignModal = false;
    public array $selectedNumbers = [];

    // Edit number modal
    public bool $showEditNumberModal = false;
    public ?int $editingNumberId = null;
    public string $numberRole = 'unassigned';
    public string $callerIdOverride = '';
    public string $inboundAction = 'forward_main';
    public string $inboundForwardTo = '';

    // Unassign confirm
    public bool $showUnassignModal = false;
    public ?int $unassigningNumberId = null;
    public string $unassigningNumber = '';

    // Company notes
    public string $companyNotes = '';

    // Booking links
    public bool $showBookingLinkModal = false;
    public ?int $editingBookingLinkId = null;
    public string $bookingLinkName = '';
    public string $bookingLinkUrl = '';

    // Default campaign
    public ?int $defaultCampaignId = null;

    // Call scripts
    public bool $showCallScriptModal = false;
    public ?int $editingScriptId = null;
    public string $scriptName = '';
    public string $scriptBody = '';
    public ?int $scriptCampaignId = null;

    // IVR Configuration
    public bool $ivrEnabled = false;
    public string $ivrActiveTab = 'business_hours';
    public string $bhGreeting = '';
    public array $bhOptions = [];
    public string $bhNoInputAction = 'route_agent';
    public string $ahGreeting = '';
    public array $ahOptions = [];
    public string $ahNoInputAction = 'voicemail';
    // Company color
    public string $companyColor = '#3B82F6';

    // Delete confirmations
    public bool $showDeleteBookingLinkModal = false;
    public ?int $deletingBookingLinkId = null;

    public bool $showDeleteScriptModal = false;
    public ?int $deletingScriptId = null;

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    public function mount(int $companyId): void
    {
        $this->companyId = $companyId;

        $company = Company::findOrFail($companyId);
        $this->companyNotes = $company->notes ?? '';
        $this->defaultCampaignId = $company->default_campaign_id;

        $settings = $company->settings ?? [];
        $this->ivrEnabled = data_get($settings, 'ivr.enabled', false);

        // Load dual-flow config
        $bh = data_get($settings, 'ivr.business_hours', []);
        $this->bhGreeting = $bh['greeting'] ?? '';
        $this->bhOptions = $this->normalizeOptions($bh['options'] ?? []);
        $this->bhNoInputAction = $bh['no_input_action'] ?? 'route_agent';

        $ah = data_get($settings, 'ivr.after_hours', []);
        $this->ahGreeting = $ah['greeting'] ?? '';
        $this->ahOptions = $this->normalizeOptions($ah['options'] ?? []);
        $this->ahNoInputAction = $ah['no_input_action'] ?? 'voicemail';

        // Legacy: migrate old single-flow config
        if (! $bh && data_get($settings, 'ivr.greeting')) {
            $this->bhGreeting = data_get($settings, 'ivr.greeting', '');
            $this->bhOptions = $this->normalizeOptions(data_get($settings, 'ivr.options', []));
        }

        $this->companyColor = $company->color ?? '#3B82F6';
    }

    #[Computed]
    public function company(): Company
    {
        return Company::query()
            ->with(['twilioAccount', 'mainPhoneNumber'])
            ->findOrFail($this->companyId);
    }

    #[Computed]
    public function assignedNumbers(): \Illuminate\Support\Collection
    {
        return PhoneNumber::query()
            ->where('company_id', $this->companyId)
            ->orderByRaw("CASE WHEN role = 'main' THEN 0 ELSE 1 END")
            ->orderBy('area_code')
            ->orderBy('phone_number')
            ->get();
    }

    #[Computed]
    public function unassignedNumbers(): \Illuminate\Support\Collection
    {
        $twilioAccountId = $this->company->twilio_account_id;

        return PhoneNumber::query()
            ->where('twilio_account_id', $twilioAccountId)
            ->whereNull('company_id')
            ->orderBy('area_code')
            ->orderBy('phone_number')
            ->get();
    }

    #[Computed]
    public function roleOptions(): array
    {
        return [
            'main' => __('Main'),
            'local_presence' => __('Local Presence'),
            'caller_id_shadow' => __('Caller ID Shadow'),
            'shadow' => __('Shadow'),
            'unassigned' => __('Unassigned'),
        ];
    }

    #[Computed]
    public function inboundActionOptions(): array
    {
        return [
            'forward_main' => __('Forward to Main'),
            'voicemail' => __('Voicemail'),
            'ivr' => __('IVR Menu'),
            'queue' => __('Queue'),
            'ignore' => __('Ignore'),
        ];
    }

    // ──────────────────────────────────────
    // Assign numbers
    // ──────────────────────────────────────

    public function openAssignModal(): void
    {
        $this->selectedNumbers = [];
        $this->showAssignModal = true;
    }

    public function closeAssignModal(): void
    {
        $this->showAssignModal = false;
        $this->selectedNumbers = [];
    }

    public function assignNumbers(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (empty($this->selectedNumbers)) {
            return;
        }

        PhoneNumber::query()
            ->whereIn('id', $this->selectedNumbers)
            ->whereNull('company_id')
            ->update(['company_id' => $this->companyId]);

        $this->closeAssignModal();
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Edit number config
    // ──────────────────────────────────────

    public function openEditNumber(int $numberId): void
    {
        $number = PhoneNumber::where('company_id', $this->companyId)->findOrFail($numberId);
        $this->editingNumberId = $number->id;
        $this->numberRole = $number->role ?? 'unassigned';
        $this->callerIdOverride = $number->caller_id_override ?? '';
        $this->inboundAction = $number->inbound_action ?? 'ring';
        $this->inboundForwardTo = $number->inbound_forward_to ?? '';
        $this->showEditNumberModal = true;
    }

    public function closeEditNumber(): void
    {
        $this->showEditNumberModal = false;
        $this->editingNumberId = null;
        $this->numberRole = 'unassigned';
        $this->callerIdOverride = '';
        $this->inboundAction = 'forward_main';
        $this->inboundForwardTo = '';
    }

    public function saveNumber(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'numberRole' => ['required', 'string', 'in:main,local_presence,caller_id_shadow,shadow,unassigned'],
            'callerIdOverride' => ['nullable', 'string', 'max:20'],
            'inboundAction' => ['required', 'string', 'in:forward_main,voicemail,ivr,queue,ignore'],
            'inboundForwardTo' => ['nullable', 'string', 'max:20'],
        ]);

        $number = PhoneNumber::where('company_id', $this->companyId)->findOrFail($this->editingNumberId);

        // If setting as main, unset any existing main number
        if ($this->numberRole === 'main') {
            PhoneNumber::where('company_id', $this->companyId)
                ->where('role', 'main')
                ->where('id', '!=', $number->id)
                ->update(['role' => 'local_presence']);

            // Also update the company's main_phone_number_id
            Company::where('id', $this->companyId)
                ->update(['main_phone_number_id' => $number->id]);
        }

        $number->update([
            'role' => $this->numberRole,
            'caller_id_override' => $this->callerIdOverride ?: null,
            'inbound_action' => $this->inboundAction,
            'inbound_forward_to' => $this->inboundForwardTo ?: null,
        ]);

        $this->closeEditNumber();
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Set main number
    // ──────────────────────────────────────

    public function setMainNumber(int $numberId): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $number = PhoneNumber::where('company_id', $this->companyId)->findOrFail($numberId);

        // Unset existing main
        PhoneNumber::where('company_id', $this->companyId)
            ->where('role', 'main')
            ->update(['role' => 'local_presence']);

        // Set new main
        $number->update(['role' => 'main']);
        Company::where('id', $this->companyId)
            ->update(['main_phone_number_id' => $number->id]);

        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Unassign number
    // ──────────────────────────────────────

    public function confirmUnassign(int $numberId): void
    {
        $number = PhoneNumber::where('company_id', $this->companyId)->findOrFail($numberId);
        $this->unassigningNumberId = $numberId;
        $this->unassigningNumber = $number->getDisplayNumber();
        $this->showUnassignModal = true;
    }

    public function unassignNumber(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (! $this->unassigningNumberId) {
            return;
        }

        $number = PhoneNumber::where('company_id', $this->companyId)->findOrFail($this->unassigningNumberId);

        // If it was the main number, clear the company reference
        if ($number->id === $this->company->main_phone_number_id) {
            Company::where('id', $this->companyId)
                ->update(['main_phone_number_id' => null]);
        }

        $number->update([
            'company_id' => null,
            'role' => 'unassigned',
        ]);

        $this->cancelUnassign();
        $this->clearCaches();
    }

    public function cancelUnassign(): void
    {
        $this->showUnassignModal = false;
        $this->unassigningNumberId = null;
        $this->unassigningNumber = '';
    }

    // ──────────────────────────────────────
    // Company Notes
    // ──────────────────────────────────────

    public function saveNotes(): void
    {
        Company::where('id', $this->companyId)->update(['notes' => $this->companyNotes ?: null]);
        unset($this->company);
    }

    // ──────────────────────────────────────
    // IVR Configuration
    // ──────────────────────────────────────

    protected function normalizeOptions(array $options): array
    {
        $normalized = [];
        foreach ($options as $digit => $option) {
            $normalized[] = [
                'digit' => (string) $digit,
                'label' => $option['label'] ?? '',
                'action' => $option['action'] ?? 'voicemail',
                'message' => $option['message'] ?? '',
                'forward_to' => $option['forward_to'] ?? '',
            ];
        }

        return $normalized;
    }

    protected function denormalizeOptions(array $options): array
    {
        $keyed = [];
        foreach ($options as $option) {
            $digit = $option['digit'] ?? '';
            if ($digit === '') {
                continue;
            }
            $entry = [
                'action' => $option['action'] ?? 'voicemail',
                'label' => $option['label'] ?? '',
            ];
            if ($option['action'] === 'play_info') {
                $entry['message'] = $option['message'] ?? '';
            }
            if ($option['action'] === 'forward') {
                $entry['forward_to'] = $option['forward_to'] ?? '';
            }
            $keyed[$digit] = $entry;
        }

        return $keyed;
    }

    public function addIvrOption(string $flow): void
    {
        $usedDigits = collect($flow === 'business_hours' ? $this->bhOptions : $this->ahOptions)->pluck('digit')->all();
        $nextDigit = '1';
        for ($i = 1; $i <= 9; $i++) {
            if (! in_array((string) $i, $usedDigits, true)) {
                $nextDigit = (string) $i;
                break;
            }
        }

        $newOption = [
            'digit' => $nextDigit,
            'label' => '',
            'action' => 'route_agent',
            'message' => '',
            'forward_to' => '',
        ];

        if ($flow === 'business_hours') {
            $this->bhOptions[] = $newOption;
        } else {
            $this->ahOptions[] = $newOption;
        }
    }

    public function removeIvrOption(string $flow, int $index): void
    {
        if ($flow === 'business_hours') {
            unset($this->bhOptions[$index]);
            $this->bhOptions = array_values($this->bhOptions);
        } else {
            unset($this->ahOptions[$index]);
            $this->ahOptions = array_values($this->ahOptions);
        }
    }

    public function saveIvr(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $company = Company::findOrFail($this->companyId);
        $settings = $company->settings ?? [];

        $settings['ivr'] = [
            'enabled' => $this->ivrEnabled,
            'business_hours' => [
                'greeting' => $this->bhGreeting,
                'options' => $this->denormalizeOptions($this->bhOptions),
                'no_input_action' => $this->bhNoInputAction,
            ],
            'after_hours' => [
                'greeting' => $this->ahGreeting,
                'options' => $this->denormalizeOptions($this->ahOptions),
                'no_input_action' => $this->ahNoInputAction,
            ],
        ];

        $company->update(['settings' => $settings]);
        unset($this->company);
    }

    public function saveCompanyColor(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'companyColor' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        Company::where('id', $this->companyId)->update(['color' => $this->companyColor]);
        unset($this->company);
    }

    // ──────────────────────────────────────
    // Booking Links
    // ──────────────────────────────────────

    #[Computed]
    public function bookingLinks(): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyBookingLink::where('company_id', $this->companyId)
            ->orderBy('sort_order')
            ->get();
    }

    public function openCreateBookingLink(): void
    {
        $this->editingBookingLinkId = null;
        $this->bookingLinkName = '';
        $this->bookingLinkUrl = '';
        $this->showBookingLinkModal = true;
    }

    public function openEditBookingLink(int $linkId): void
    {
        $link = CompanyBookingLink::where('company_id', $this->companyId)->findOrFail($linkId);
        $this->editingBookingLinkId = $link->id;
        $this->bookingLinkName = $link->name;
        $this->bookingLinkUrl = $link->url;
        $this->showBookingLinkModal = true;
    }

    public function saveBookingLink(): void
    {
        $this->validate([
            'bookingLinkName' => ['required', 'string', 'max:255'],
            'bookingLinkUrl' => ['required', 'url', 'max:2000'],
        ]);

        if ($this->editingBookingLinkId) {
            CompanyBookingLink::where('company_id', $this->companyId)
                ->findOrFail($this->editingBookingLinkId)
                ->update([
                    'name' => $this->bookingLinkName,
                    'url' => $this->bookingLinkUrl,
                ]);
        } else {
            $maxSort = CompanyBookingLink::where('company_id', $this->companyId)->max('sort_order') ?? -1;
            CompanyBookingLink::create([
                'company_id' => $this->companyId,
                'name' => $this->bookingLinkName,
                'url' => $this->bookingLinkUrl,
                'sort_order' => $maxSort + 1,
            ]);
        }

        $this->showBookingLinkModal = false;
        unset($this->bookingLinks);
    }

    public function confirmDeleteBookingLink(int $linkId): void
    {
        $this->deletingBookingLinkId = $linkId;
        $this->showDeleteBookingLinkModal = true;
    }

    public function deleteBookingLink(): void
    {
        if ($this->deletingBookingLinkId) {
            CompanyBookingLink::where('company_id', $this->companyId)
                ->findOrFail($this->deletingBookingLinkId)
                ->delete();
        }
        $this->showDeleteBookingLinkModal = false;
        $this->deletingBookingLinkId = null;
        unset($this->bookingLinks);
    }

    public function reorderBookingLinks(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            CompanyBookingLink::where('id', $id)
                ->where('company_id', $this->companyId)
                ->update(['sort_order' => $index]);
        }
        unset($this->bookingLinks);
    }

    // ──────────────────────────────────────
    // Default Campaign
    // ──────────────────────────────────────

    #[Computed]
    public function campaigns(): \Illuminate\Database\Eloquent\Collection
    {
        return Campaign::where('company_id', $this->companyId)->orderBy('name')->get(['id', 'name']);
    }

    public function saveDefaultCampaign(): void
    {
        Company::where('id', $this->companyId)->update([
            'default_campaign_id' => $this->defaultCampaignId ?: null,
        ]);
        unset($this->company);
    }

    // ──────────────────────────────────────
    // Call Scripts
    // ──────────────────────────────────────

    #[Computed]
    public function callScripts(): \Illuminate\Database\Eloquent\Collection
    {
        return CallScript::where('company_id', $this->companyId)
            ->orderBy('sort_order')
            ->get();
    }

    public function openCreateScript(): void
    {
        $this->editingScriptId = null;
        $this->scriptName = '';
        $this->scriptBody = '';
        $this->scriptCampaignId = null;
        $this->showCallScriptModal = true;
    }

    public function openEditScript(int $scriptId): void
    {
        $script = CallScript::where('company_id', $this->companyId)->findOrFail($scriptId);
        $this->editingScriptId = $script->id;
        $this->scriptName = $script->name;
        $this->scriptBody = $script->body;
        $this->scriptCampaignId = $script->campaign_id;
        $this->showCallScriptModal = true;
    }

    public function saveCallScript(): void
    {
        $this->validate([
            'scriptName' => ['required', 'string', 'max:255'],
            'scriptBody' => ['required', 'string'],
        ]);

        if ($this->editingScriptId) {
            CallScript::where('company_id', $this->companyId)
                ->findOrFail($this->editingScriptId)
                ->update([
                    'name' => $this->scriptName,
                    'body' => $this->scriptBody,
                    'campaign_id' => $this->scriptCampaignId ?: null,
                ]);
        } else {
            $maxSort = CallScript::where('company_id', $this->companyId)->max('sort_order') ?? -1;
            CallScript::create([
                'company_id' => $this->companyId,
                'campaign_id' => $this->scriptCampaignId ?: null,
                'name' => $this->scriptName,
                'body' => $this->scriptBody,
                'sort_order' => $maxSort + 1,
            ]);
        }

        $this->showCallScriptModal = false;
        unset($this->callScripts);
    }

    public function toggleScriptActive(int $scriptId): void
    {
        $script = CallScript::where('company_id', $this->companyId)->findOrFail($scriptId);
        $script->update(['is_active' => ! $script->is_active]);
        unset($this->callScripts);
    }

    public function confirmDeleteScript(int $scriptId): void
    {
        $this->deletingScriptId = $scriptId;
        $this->showDeleteScriptModal = true;
    }

    public function deleteCallScript(): void
    {
        if ($this->deletingScriptId) {
            CallScript::where('company_id', $this->companyId)
                ->findOrFail($this->deletingScriptId)
                ->delete();
        }
        $this->showDeleteScriptModal = false;
        $this->deletingScriptId = null;
        unset($this->callScripts);
    }

    // ──────────────────────────────────────
    // Agent Assignment
    // ──────────────────────────────────────

    #[Computed]
    public function availableAgents(): \Illuminate\Support\Collection
    {
        return Agent::with('user')
            ->whereDoesntHave('companies', fn ($q) => $q->where('prospector_companies.id', $this->companyId))
            ->get();
    }

    #[Computed]
    public function assignedAgents(): \Illuminate\Support\Collection
    {
        return $this->company->agents()->with('user')->get();
    }

    public function assignAgent(int $agentId): void
    {
        $this->company->agents()->syncWithoutDetaching([$agentId]);
        unset($this->assignedAgents, $this->availableAgents);
    }

    public function unassignAgent(int $agentId): void
    {
        $this->company->agents()->detach($agentId);
        unset($this->assignedAgents, $this->availableAgents);
    }

    // ──────────────────────────────────────
    // Webhook URL
    // ──────────────────────────────────────

    #[Computed]
    public function webhookUrl(): string
    {
        return url("/prospector/webhook/{$this->company->ulid}/leads");
    }

    protected function clearCaches(): void
    {
        unset($this->company, $this->assignedNumbers, $this->unassignedNumbers, $this->bookingLinks, $this->callScripts, $this->campaigns, $this->assignedAgents, $this->availableAgents);
    }

    protected function getRoleBadgeColor(string $role): string
    {
        return match ($role) {
            'main' => 'green',
            'local_presence' => 'blue',
            'caller_id_shadow' => 'purple',
            'shadow' => 'cyan',
            default => 'zinc',
        };
    }
}; ?>

<div>
    {{-- Back link + Header --}}
    <div class="mb-6">
        <flux:button variant="ghost" icon="arrow-left" :href="route('prospector.companies')" class="mb-3">
            {{ __('Back to Companies') }}
        </flux:button>

        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <flux:heading size="xl">{{ $this->company->name }}</flux:heading>
                    @if ($this->company->is_active)
                        <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="amber">{{ __('Inactive') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-1 flex items-center gap-4 text-sm text-zinc-500 dark:text-zinc-400">
                    <span class="flex items-center gap-1">
                        <flux:icon name="clock" variant="micro" />
                        {{ $this->company->timezone ?? 'America/Denver' }}
                    </span>
                    @if ($this->company->twilioAccount)
                        <span class="flex items-center gap-1">
                            <flux:icon name="server" variant="micro" />
                            {{ $this->company->twilioAccount->name }}
                        </span>
                    @endif
                    @if ($this->company->business_hours_start && $this->company->business_hours_end)
                        <span class="flex items-center gap-1">
                            <flux:icon name="calendar" variant="micro" />
                            {{ $this->company->business_hours_start }} - {{ $this->company->business_hours_end }}
                        </span>
                    @endif
                </div>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="openAssignModal">
                {{ __('Assign Number') }}
            </flux:button>
        </div>
    </div>

    {{-- Main number callout --}}
    @if ($this->company->mainPhoneNumber)
        <flux:callout color="blue" icon="phone" class="mb-4">
            <flux:callout.heading>{{ __('Main Number') }}</flux:callout.heading>
            <flux:callout.text>
                {{ $this->company->mainPhoneNumber->getDisplayNumber() }}
                @if ($this->company->mainPhoneNumber->friendly_name)
                    - {{ $this->company->mainPhoneNumber->friendly_name }}
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Assigned Numbers Table --}}
    @if ($this->assignedNumbers->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="phone" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No numbers assigned') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Assign phone numbers from your Twilio account to this company.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" icon="plus" wire:click="openAssignModal">
                    {{ __('Assign Number') }}
                </flux:button>
            </div>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Friendly Name') }}</flux:table.column>
                <flux:table.column>{{ __('Area Code') }}</flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Caller ID Override') }}</flux:table.column>
                <flux:table.column>{{ __('Inbound Action') }}</flux:table.column>
                <flux:table.column>{{ __('Active') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->assignedNumbers as $number)
                    <flux:table.row wire:key="num-{{ $number->id }}">
                        <flux:table.cell>
                            <span class="font-mono text-sm">{{ $number->getDisplayNumber() }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            {{ $number->friendly_name ?: '-' }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $number->area_code }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" :color="$this->getRoleBadgeColor($number->role ?? 'unassigned')">
                                {{ $this->roleOptions[$number->role] ?? ucfirst($number->role ?? 'unassigned') }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($number->caller_id_override)
                                <span class="font-mono text-sm">{{ $number->caller_id_override }}</span>
                            @else
                                <span class="text-zinc-400">{{ __('None') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="text-sm">{{ $this->inboundActionOptions[$number->inbound_action] ?? ucfirst($number->inbound_action ?? 'forward_main') }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($number->is_active)
                                <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if ($number->role !== 'main')
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="star"
                                        wire:click="setMainNumber({{ $number->id }})"
                                        title="{{ __('Set as Main Number') }}"
                                    />
                                @endif
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil"
                                    wire:click="openEditNumber({{ $number->id }})"
                                    title="{{ __('Edit Configuration') }}"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="x-circle"
                                    wire:click="confirmUnassign({{ $number->id }})"
                                    title="{{ __('Unassign Number') }}"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- ──────────────────────────────────── --}}
    {{-- Company Notes --}}
    {{-- ──────────────────────────────────── --}}
    <div class="mt-8 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg" class="mb-3">{{ __('Company Notes') }}</flux:heading>
        <textarea
            wire:model.blur="companyNotes"
            wire:change="saveNotes"
            rows="4"
            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100 dark:placeholder-zinc-500 dark:focus:border-blue-400"
            placeholder="{{ __('Add notes about this company...') }}"
        ></textarea>
        <div class="mt-1 text-xs text-zinc-400" wire:loading.remove wire:target="saveNotes">{{ __('Auto-saves on blur') }}</div>
        <div class="mt-1 text-xs text-blue-500" wire:loading wire:target="saveNotes">{{ __('Saving...') }}</div>
    </div>

    {{-- ──────────────────────────────────── --}}
    {{-- Webhook URL --}}
    {{-- ──────────────────────────────────── --}}
    <div class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg" class="mb-3">{{ __('Webhook Integration') }}</flux:heading>

        <div class="space-y-3">
            <div>
                <flux:label>{{ __('Webhook URL') }}</flux:label>
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 rounded border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-mono dark:border-zinc-600 dark:bg-zinc-700">
                        {{ $this->webhookUrl }}
                    </code>
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="clipboard"
                        x-on:click="navigator.clipboard.writeText('{{ $this->webhookUrl }}'); $el.querySelector('.flux-icon').classList.add('text-green-500'); setTimeout(() => $el.querySelector('.flux-icon').classList.remove('text-green-500'), 2000)"
                        title="{{ __('Copy to clipboard') }}"
                    />
                </div>
            </div>

            <div x-data="{ showSecret: false }">
                <flux:label>{{ __('Webhook Secret') }}</flux:label>
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 rounded border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-mono dark:border-zinc-600 dark:bg-zinc-700">
                        <span x-show="!showSecret">{{ str_repeat('*', 32) }}</span>
                        <span x-show="showSecret" x-cloak>{{ $this->company->webhook_secret }}</span>
                    </code>
                    <flux:button size="sm" variant="ghost" icon="eye" x-on:click="showSecret = !showSecret" title="{{ __('Toggle visibility') }}" />
                </div>
            </div>

            <details class="mt-2">
                <summary class="cursor-pointer text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                    {{ __('Example payload') }}
                </summary>
                <pre class="mt-2 max-h-48 overflow-auto rounded border border-zinc-200 bg-zinc-50 p-3 text-xs dark:border-zinc-600 dark:bg-zinc-700"><code>{
  "first_name": "John",
  "last_name": "Smith",
  "phone": "+17205551234",
  "email": "john@example.com",
  "address": "123 Main St",
  "city": "Denver",
  "state": "CO",
  "zip": "80202",
  "source": "website",
  "tags": ["exterior", "priority"]
}</code></pre>
            </details>
        </div>
    </div>

    {{-- ──────────────────────────────────── --}}
    {{-- Default Campaign --}}
    {{-- ──────────────────────────────────── --}}
    <div class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg" class="mb-1">{{ __('Default Campaign') }}</flux:heading>
        <flux:text class="mb-3 text-sm">{{ __('Auto-enroll new webhook leads into this campaign.') }}</flux:text>

        <div class="flex items-center gap-2">
            <div class="flex-1">
                <flux:select wire:model="defaultCampaignId" wire:change="saveDefaultCampaign">
                    <flux:select.option value="">{{ __('No default campaign') }}</flux:select.option>
                    @foreach ($this->campaigns as $campaign)
                        <flux:select.option value="{{ $campaign->id }}">{{ $campaign->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div wire:loading wire:target="saveDefaultCampaign">
                <flux:text class="text-sm text-blue-500">{{ __('Saved') }}</flux:text>
            </div>
        </div>
    </div>

    {{-- ──────────────────────────────────── --}}
    {{-- Company Color --}}
    {{-- ──────────────────────────────────── --}}
    <div class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex items-center justify-between">
            <div>
                <flux:label>{{ __('Company Color') }}</flux:label>
                <flux:text class="text-xs">{{ __('Color used to identify this company in the dialer queue.') }}</flux:text>
            </div>
            <div class="flex items-center gap-2">
                <input type="color" wire:model="companyColor" wire:change="saveCompanyColor"
                    class="h-8 w-8 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600" />
                <span class="text-xs text-zinc-500" x-text="$wire.companyColor"></span>
            </div>
        </div>
    </div>

    {{-- ──────────────────────────────────── --}}
    {{-- IVR Configuration --}}
    {{-- ──────────────────────────────────── --}}
    <div class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg" class="mb-1">{{ __('IVR Configuration') }}</flux:heading>
        <flux:text class="mb-4 text-sm">{{ __('Configure interactive voice response for inbound calls. Set up separate flows for business hours and after hours.') }}</flux:text>

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:label>{{ __('Enable IVR') }}</flux:label>
                    <flux:text class="text-xs">{{ __('Route inbound calls through an IVR menu.') }}</flux:text>
                </div>
                <flux:switch wire:model.live="ivrEnabled" wire:change="saveIvr" />
            </div>

            @if ($ivrEnabled)
                <div class="border-b border-zinc-200 dark:border-zinc-700">
                    <nav class="-mb-px flex gap-4">
                        <button type="button" wire:click="$set('ivrActiveTab', 'business_hours')"
                            class="border-b-2 px-1 pb-2 text-sm font-medium transition {{ $ivrActiveTab === 'business_hours' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                            {{ __('Business Hours') }}
                        </button>
                        <button type="button" wire:click="$set('ivrActiveTab', 'after_hours')"
                            class="border-b-2 px-1 pb-2 text-sm font-medium transition {{ $ivrActiveTab === 'after_hours' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                            {{ __('After Hours') }}
                        </button>
                    </nav>
                </div>

                @if ($ivrActiveTab === 'business_hours')
                    <div class="space-y-4">
                        <flux:textarea wire:model="bhGreeting" :label="__('Greeting')"
                            :placeholder="__('e.g., Thank you for calling. Press 1 to speak with a team member, press 2 for general information.')"
                            rows="3" />

                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <flux:label>{{ __('Menu Options') }}</flux:label>
                                <flux:button size="xs" variant="ghost" icon="plus" wire:click="addIvrOption('business_hours')">
                                    {{ __('Add Option') }}
                                </flux:button>
                            </div>
                            <div class="space-y-2">
                                @foreach ($bhOptions as $index => $option)
                                    <div class="flex items-start gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="bh-opt-{{ $index }}">
                                        <div class="w-16 shrink-0">
                                            <flux:select wire:model="bhOptions.{{ $index }}.digit" size="sm" label="{{ __('Key') }}">
                                                @for ($d = 1; $d <= 9; $d++)
                                                    <flux:select.option value="{{ $d }}">{{ $d }}</flux:select.option>
                                                @endfor
                                            </flux:select>
                                        </div>
                                        <div class="min-w-0 flex-1 space-y-2">
                                            <flux:input wire:model="bhOptions.{{ $index }}.label" size="sm" :placeholder="__('Label (e.g., Sales)')" />
                                            <flux:select wire:model.live="bhOptions.{{ $index }}.action" size="sm">
                                                <flux:select.option value="route_agent">{{ __('Route to Agent') }}</flux:select.option>
                                                <flux:select.option value="voicemail">{{ __('Voicemail') }}</flux:select.option>
                                                <flux:select.option value="play_info">{{ __('Play Information') }}</flux:select.option>
                                                <flux:select.option value="forward">{{ __('Forward to Number') }}</flux:select.option>
                                            </flux:select>
                                            @if (($bhOptions[$index]['action'] ?? '') === 'play_info')
                                                <flux:textarea wire:model="bhOptions.{{ $index }}.message" size="sm" rows="2"
                                                    :placeholder="__('Message to read (e.g., Our hours are Mon-Fri 8am to 5pm.)')" />
                                            @endif
                                            @if (($bhOptions[$index]['action'] ?? '') === 'forward')
                                                <flux:input wire:model="bhOptions.{{ $index }}.forward_to" size="sm"
                                                    :placeholder="__('Phone number (e.g., +13035551234)')" />
                                            @endif
                                        </div>
                                        <flux:button size="xs" variant="ghost" icon="x-mark"
                                            wire:click="removeIvrOption('business_hours', {{ $index }})" class="mt-5 shrink-0" />
                                    </div>
                                @endforeach
                                @if (empty($bhOptions))
                                    <flux:text class="text-sm text-zinc-400">{{ __('No options configured. Add one above.') }}</flux:text>
                                @endif
                            </div>
                        </div>

                        <flux:select wire:model="bhNoInputAction" :label="__('No Input Fallback')">
                            <flux:select.option value="route_agent">{{ __('Route to Agent') }}</flux:select.option>
                            <flux:select.option value="voicemail">{{ __('Voicemail') }}</flux:select.option>
                        </flux:select>
                    </div>
                @endif

                @if ($ivrActiveTab === 'after_hours')
                    <div class="space-y-4">
                        <flux:textarea wire:model="ahGreeting" :label="__('Greeting')"
                            :placeholder="__('e.g., Thank you for calling. We are currently closed. Press 1 to leave a message.')"
                            rows="3" />

                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <flux:label>{{ __('Menu Options') }}</flux:label>
                                <flux:button size="xs" variant="ghost" icon="plus" wire:click="addIvrOption('after_hours')">
                                    {{ __('Add Option') }}
                                </flux:button>
                            </div>
                            <div class="space-y-2">
                                @foreach ($ahOptions as $index => $option)
                                    <div class="flex items-start gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="ah-opt-{{ $index }}">
                                        <div class="w-16 shrink-0">
                                            <flux:select wire:model="ahOptions.{{ $index }}.digit" size="sm" label="{{ __('Key') }}">
                                                @for ($d = 1; $d <= 9; $d++)
                                                    <flux:select.option value="{{ $d }}">{{ $d }}</flux:select.option>
                                                @endfor
                                            </flux:select>
                                        </div>
                                        <div class="min-w-0 flex-1 space-y-2">
                                            <flux:input wire:model="ahOptions.{{ $index }}.label" size="sm" :placeholder="__('Label')" />
                                            <flux:select wire:model.live="ahOptions.{{ $index }}.action" size="sm">
                                                <flux:select.option value="route_agent">{{ __('Route to Agent') }}</flux:select.option>
                                                <flux:select.option value="voicemail">{{ __('Voicemail') }}</flux:select.option>
                                                <flux:select.option value="play_info">{{ __('Play Information') }}</flux:select.option>
                                                <flux:select.option value="forward">{{ __('Forward to Number') }}</flux:select.option>
                                            </flux:select>
                                            @if (($ahOptions[$index]['action'] ?? '') === 'play_info')
                                                <flux:textarea wire:model="ahOptions.{{ $index }}.message" size="sm" rows="2"
                                                    :placeholder="__('Message to read')" />
                                            @endif
                                            @if (($ahOptions[$index]['action'] ?? '') === 'forward')
                                                <flux:input wire:model="ahOptions.{{ $index }}.forward_to" size="sm"
                                                    :placeholder="__('Phone number')" />
                                            @endif
                                        </div>
                                        <flux:button size="xs" variant="ghost" icon="x-mark"
                                            wire:click="removeIvrOption('after_hours', {{ $index }})" class="mt-5 shrink-0" />
                                    </div>
                                @endforeach
                                @if (empty($ahOptions))
                                    <flux:text class="text-sm text-zinc-400">{{ __('No options configured. Add one above.') }}</flux:text>
                                @endif
                            </div>
                        </div>

                        <flux:select wire:model="ahNoInputAction" :label="__('No Input Fallback')">
                            <flux:select.option value="route_agent">{{ __('Route to Agent') }}</flux:select.option>
                            <flux:select.option value="voicemail">{{ __('Voicemail') }}</flux:select.option>
                        </flux:select>
                    </div>
                @endif

                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="primary" wire:click="saveIvr" wire:loading.attr="disabled" wire:target="saveIvr">
                        <span wire:loading.remove wire:target="saveIvr">{{ __('Save IVR Settings') }}</span>
                        <span wire:loading wire:target="saveIvr">{{ __('Saving...') }}</span>
                    </flux:button>
                    <div wire:loading.remove wire:target="saveIvr">
                        @if (data_get($this->company->settings ?? [], 'ivr.enabled'))
                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ──────────────────────────────────── --}}
    {{-- Booking Links --}}
    {{-- ──────────────────────────────────── --}}
    <div class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="mb-3 flex items-center justify-between">
            <flux:heading size="lg">{{ __('Booking Links') }}</flux:heading>
            <flux:button size="sm" variant="ghost" icon="plus" wire:click="openCreateBookingLink">
                {{ __('Add Link') }}
            </flux:button>
        </div>

        @if ($this->bookingLinks->isEmpty())
            <flux:text class="text-sm text-zinc-500">{{ __('No booking links configured. Add links to Calendly, estimate forms, etc.') }}</flux:text>
        @else
            <div
                class="space-y-2"
                x-data="{
                    async initSortable() {
                        const Sortable = await window.loadSortable();
                        Sortable.create(this.$el, {
                            handle: '.link-handle',
                            animation: 150,
                            onEnd: (evt) => {
                                const ids = [...evt.from.children].map(el => parseInt(el.dataset.linkId));
                                $wire.reorderBookingLinks(ids);
                            }
                        });
                    }
                }"
                x-init="initSortable()"
            >
                @foreach ($this->bookingLinks as $link)
                    <div
                        wire:key="link-{{ $link->id }}"
                        data-link-id="{{ $link->id }}"
                        class="flex items-center gap-3 rounded-lg border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-600 dark:bg-zinc-700/50"
                    >
                        <div class="link-handle cursor-grab text-zinc-400 hover:text-zinc-600 active:cursor-grabbing dark:text-zinc-500">
                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-6 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                        </div>
                        <flux:icon name="link" variant="micro" class="text-zinc-400" />
                        <div class="min-w-0 flex-1">
                            <flux:text class="font-medium text-sm">{{ $link->name }}</flux:text>
                            <flux:text class="text-xs text-zinc-500 truncate">{{ $link->url }}</flux:text>
                        </div>
                        <div class="flex items-center gap-1">
                            <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" x-on:click="window.open('{{ $link->url }}', '_blank')" title="{{ __('Open') }}" />
                            <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditBookingLink({{ $link->id }})" title="{{ __('Edit') }}" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDeleteBookingLink({{ $link->id }})" title="{{ __('Delete') }}" />
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ──────────────────────────────────── --}}
    {{-- Call Scripts --}}
    {{-- ──────────────────────────────────── --}}
    <div class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="mb-3 flex items-center justify-between">
            <flux:heading size="lg">{{ __('Call Scripts') }}</flux:heading>
            <flux:button size="sm" variant="ghost" icon="plus" wire:click="openCreateScript">
                {{ __('Add Script') }}
            </flux:button>
        </div>

        @if ($this->callScripts->isEmpty())
            <flux:text class="text-sm text-zinc-500">{{ __('No call scripts yet. Create scripts for agents to reference during calls.') }}</flux:text>
        @else
            <div class="space-y-2">
                @foreach ($this->callScripts as $script)
                    <div wire:key="script-{{ $script->id }}" class="rounded-lg border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-600 dark:bg-zinc-700/50">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <flux:text class="font-medium text-sm">{{ $script->name }}</flux:text>
                                @if ($script->campaign_id)
                                    <flux:badge size="sm" color="blue">{{ $script->campaign?->name ?? __('Campaign') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Company-wide') }}</flux:badge>
                                @endif
                                @if ($script->is_active)
                                    <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                <flux:button size="sm" variant="ghost" wire:click="toggleScriptActive({{ $script->id }})" :icon="$script->is_active ? 'pause' : 'play'" title="{{ $script->is_active ? __('Deactivate') : __('Activate') }}" />
                                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditScript({{ $script->id }})" title="{{ __('Edit') }}" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDeleteScript({{ $script->id }})" title="{{ __('Delete') }}" />
                            </div>
                        </div>
                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Preview script') }}</summary>
                            <div class="mt-2 max-h-32 overflow-auto rounded border border-zinc-200 bg-white p-3 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                                {!! nl2br(e(Str::limit($script->body, 500))) !!}
                            </div>
                        </details>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ──────────────────────────────────── --}}
    {{-- Assigned Agents --}}
    {{-- ──────────────────────────────────── --}}
    <div class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg" class="mb-3">{{ __('Assigned Agents') }}</flux:heading>

        @foreach ($this->assignedAgents as $agent)
            <div class="flex items-center justify-between border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-700">
                <div class="flex items-center gap-2">
                    <div class="flex size-7 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                        <span class="text-xs font-bold text-blue-600 dark:text-blue-400">{{ substr($agent->user->name, 0, 1) }}</span>
                    </div>
                    <span class="text-sm font-medium">{{ $agent->user->name }}</span>
                    <flux:badge size="sm" :color="match($agent->status) {
                        'available' => 'green',
                        'on_call' => 'red',
                        'ringing' => 'amber',
                        'wrap_up' => 'amber',
                        'break' => 'zinc',
                        default => 'zinc',
                    }">
                        {{ ucfirst(str_replace('_', ' ', $agent->status)) }}
                    </flux:badge>
                </div>
                <flux:button size="xs" variant="ghost" icon="x-mark"
                    wire:click="unassignAgent({{ $agent->id }})"
                    wire:confirm="{{ __('Remove this agent from') }} {{ $this->company->name }}?" />
            </div>
        @endforeach

        @if ($this->assignedAgents->isEmpty())
            <div class="py-3 text-center">
                <flux:text class="text-sm text-zinc-400">{{ __('No agents assigned yet.') }}</flux:text>
            </div>
        @endif

        @if ($this->availableAgents->isNotEmpty())
            <div class="mt-3" x-data="{ agentToAssign: '' }">
                <div class="flex gap-2">
                    <flux:select x-model="agentToAssign" size="sm" class="flex-1" placeholder="{{ __('Add agent...') }}">
                        @foreach ($this->availableAgents as $agent)
                            <flux:select.option value="{{ $agent->id }}">{{ $agent->user->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" variant="primary" icon="plus"
                        x-on:click="if (agentToAssign) { $wire.assignAgent(parseInt(agentToAssign)); agentToAssign = '' }">
                        {{ __('Add') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </div>

    {{-- Booking Link Modal --}}
    <flux:modal wire:model="showBookingLinkModal" class="max-w-md">
        <form wire:submit="saveBookingLink" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingBookingLinkId ? __('Edit Booking Link') : __('Add Booking Link') }}
            </flux:heading>
            <flux:input wire:model="bookingLinkName" :label="__('Name')" :placeholder="__('e.g., Calendly')" required />
            <flux:input wire:model="bookingLinkUrl" :label="__('URL')" :placeholder="__('https://calendly.com/...')" type="url" required />
            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showBookingLinkModal', false)" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">{{ $editingBookingLinkId ? __('Update') : __('Add') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Booking Link Confirmation --}}
    <flux:modal wire:model="showDeleteBookingLinkModal" class="max-w-sm">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Delete Booking Link') }}</flux:heading>
            <flux:text>{{ __('Are you sure you want to delete this booking link?') }}</flux:text>
            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteBookingLinkModal', false)" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteBookingLink" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Call Script Modal --}}
    <flux:modal wire:model="showCallScriptModal" variant="flyout" class="w-[500px]">
        <form wire:submit="saveCallScript" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingScriptId ? __('Edit Call Script') : __('New Call Script') }}
            </flux:heading>

            <flux:input wire:model="scriptName" :label="__('Script Name')" :placeholder="__('e.g., Cold Call Intro')" required />

            <flux:select wire:model="scriptCampaignId" :label="__('Campaign (optional)')">
                <flux:select.option value="">{{ __('Company-wide (all campaigns)') }}</flux:select.option>
                @foreach ($this->campaigns as $campaign)
                    <flux:select.option value="{{ $campaign->id }}">{{ $campaign->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div>
                <flux:label>{{ __('Script Body') }}</flux:label>
                <textarea
                    wire:model="scriptBody"
                    rows="12"
                    class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                    placeholder="{{ __('Write your call script here...') }}"
                    required
                ></textarea>
            </div>

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showCallScriptModal', false)" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">{{ $editingScriptId ? __('Update') : __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Script Confirmation --}}
    <flux:modal wire:model="showDeleteScriptModal" class="max-w-sm">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Delete Call Script') }}</flux:heading>
            <flux:text>{{ __('Are you sure you want to delete this call script?') }}</flux:text>
            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteScriptModal', false)" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteCallScript" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Assign Numbers Modal --}}
    <flux:modal wire:model="showAssignModal" variant="flyout" class="w-[500px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Assign Phone Numbers') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Select unassigned numbers from your Twilio account to add to this company.') }}
                </flux:text>
            </div>

            @if ($this->unassignedNumbers->isEmpty())
                <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:text class="text-zinc-500">{{ __('No unassigned numbers available. Sync your Twilio account to import numbers.') }}</flux:text>
                </div>
            @else
                <div class="max-h-[400px] space-y-2 overflow-y-auto">
                    @foreach ($this->unassignedNumbers as $number)
                        <label
                            wire:key="assign-{{ $number->id }}"
                            class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        >
                            <input
                                type="checkbox"
                                wire:model="selectedNumbers"
                                value="{{ $number->id }}"
                                class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
                            />
                            <div class="flex-1">
                                <span class="font-mono text-sm font-medium">{{ $number->getDisplayNumber() }}</span>
                                @if ($number->friendly_name)
                                    <span class="ml-2 text-sm text-zinc-500">{{ $number->friendly_name }}</span>
                                @endif
                                <div class="text-xs text-zinc-400">
                                    {{ __('Area Code') }}: {{ $number->area_code }}
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="flex gap-2">
                    <flux:button type="button" variant="ghost" wire:click="closeAssignModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                    <flux:button
                        variant="primary"
                        wire:click="assignNumbers"
                        class="flex-1"
                    >
                        {{ __('Assign Selected') }} @if(count($selectedNumbers))({{ count($selectedNumbers) }})@endif
                    </flux:button>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- Edit Number Modal --}}
    <flux:modal wire:model="showEditNumberModal" variant="flyout" class="w-[420px]">
        <form wire:submit="saveNumber" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Configure Number') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Set the role and behavior for this phone number.') }}</flux:text>
            </div>

            <flux:select wire:model.live="numberRole" :label="__('Role')">
                @foreach ($this->roleOptions as $roleValue => $roleLabel)
                    <flux:select.option value="{{ $roleValue }}">{{ $roleLabel }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="callerIdOverride"
                :label="__('Caller ID Override')"
                :placeholder="__('e.g., +17205551234')"
                :description="__('Override the caller ID shown on outbound calls. Leave blank to use the number itself.')"
            />

            <flux:select wire:model.live="inboundAction" :label="__('Inbound Action')">
                @foreach ($this->inboundActionOptions as $actionValue => $actionLabel)
                    <flux:select.option value="{{ $actionValue }}">{{ $actionLabel }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($inboundAction === 'forward_main')
                <flux:input
                    wire:model="inboundForwardTo"
                    :label="__('Forward To Number')"
                    :placeholder="__('e.g., +17205551234')"
                    required
                />
            @endif

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeEditNumber" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">{{ __('Save Configuration') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Unassign Confirmation Modal --}}
    <flux:modal wire:model="showUnassignModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Unassign Number') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Remove') }} <strong>{{ $unassigningNumber }}</strong> {{ __('from this company? The number will remain in your Twilio account and can be reassigned later.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelUnassign" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="unassignNumber" class="flex-1">{{ __('Unassign') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
