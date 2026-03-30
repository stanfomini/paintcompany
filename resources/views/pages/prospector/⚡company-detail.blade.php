<?php

use App\Models\Prospector\Company;
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

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    public function mount(int $companyId): void
    {
        $this->companyId = $companyId;

        // Verify company exists
        Company::findOrFail($companyId);
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

    protected function clearCaches(): void
    {
        unset($this->company, $this->assignedNumbers, $this->unassignedNumbers);
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
