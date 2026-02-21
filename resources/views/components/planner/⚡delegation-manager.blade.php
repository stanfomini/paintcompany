<?php

use App\Models\PlannerDelegation;
use App\Models\User;
use App\Services\PlannerDelegationService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?int $targetUserId = null;

    public bool $showCreateForm = false;

    public ?int $newDelegateId = null;

    public string $newAccessLevel = 'view';

    public string $newTimeframe = 'permanent';

    public ?string $newExpiresAt = null;

    public function mount(): void
    {
        $this->targetUserId = auth()->id();
    }

    #[Computed]
    public function canManageOthers(): bool
    {
        return auth()->user()->can('planner.delegate');
    }

    #[Computed]
    public function internalUsers()
    {
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function delegations()
    {
        $targetUser = User::find($this->targetUserId ?? auth()->id());

        if (! $targetUser) {
            return collect();
        }

        return app(PlannerDelegationService::class)->getDelegatesFor($targetUser);
    }

    public function applyPreset(string $preset): void
    {
        $this->newTimeframe = $preset;

        $this->newExpiresAt = match ($preset) {
            'today' => now()->endOfDay()->format('Y-m-d\TH:i'),
            'this_week' => now()->endOfWeek()->format('Y-m-d\TH:i'),
            'two_weeks' => now()->addWeeks(2)->endOfDay()->format('Y-m-d\TH:i'),
            'one_month' => now()->addMonth()->endOfDay()->format('Y-m-d\TH:i'),
            default => null,
        };
    }

    public function saveDelegation(): void
    {
        $this->validate([
            'newDelegateId' => ['required', 'exists:users,id'],
            'newAccessLevel' => ['required', 'in:view,act,full'],
        ]);

        $targetUser = User::findOrFail($this->targetUserId ?? auth()->id());
        $delegate = User::findOrFail($this->newDelegateId);
        $isPermanent = $this->newTimeframe === 'permanent';

        app(PlannerDelegationService::class)->createDelegation(
            grantor: auth()->user(),
            targetUser: $targetUser,
            delegate: $delegate,
            accessLevel: $this->newAccessLevel,
            isPermanent: $isPermanent,
            expiresAt: ! $isPermanent && $this->newExpiresAt ? \Carbon\Carbon::parse($this->newExpiresAt) : null,
        );

        $this->reset(['newDelegateId', 'newAccessLevel', 'newTimeframe', 'newExpiresAt']);
        $this->newAccessLevel = 'view';
        $this->newTimeframe = 'permanent';
        $this->showCreateForm = false;
        unset($this->delegations);
    }

    public function revoke(int $delegationId): void
    {
        app(PlannerDelegationService::class)->revokeDelegation($delegationId, auth()->user());
        unset($this->delegations);
    }
}; ?>

<div class="space-y-6">
    <div>
        <flux:heading size="lg">{{ __('Manage Planner Access') }}</flux:heading>
        <flux:text class="mt-1 text-sm text-zinc-500">{{ __('Control who can view or act on planner activities.') }}</flux:text>
    </div>

    @if ($this->canManageOthers)
        <flux:field>
            <flux:label>{{ __('Managing planner for') }}</flux:label>
            <flux:select wire:model.live="targetUserId">
                <option value="{{ auth()->id() }}">{{ __('My Planner') }}</option>
                @foreach ($this->internalUsers as $user)
                    @if ($user->id !== auth()->id())
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endif
                @endforeach
            </flux:select>
        </flux:field>
    @endif

    <div>
        <div class="flex items-center justify-between mb-3">
            <flux:text class="font-semibold text-sm">{{ __('Current Access') }}</flux:text>
            <flux:button size="sm" variant="primary" icon="plus" wire:click="$set('showCreateForm', true)">
                {{ __('Grant Access') }}
            </flux:button>
        </div>

        @if ($this->delegations->isEmpty())
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 text-center">
                <flux:text class="text-sm text-zinc-500">{{ __('No one has delegated access to this planner.') }}</flux:text>
            </div>
        @else
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->delegations as $delegation)
                    <div wire:key="delegation-{{ $delegation->id }}" class="flex items-center justify-between p-3">
                        <div>
                            <flux:text class="font-medium text-sm">{{ $delegation->delegate->name }}</flux:text>
                            <div class="flex items-center gap-2 mt-0.5">
                                <flux:badge size="sm" :color="match($delegation->access_level) { 'view' => 'zinc', 'act' => 'blue', 'full' => 'green' }">
                                    {{ ucfirst($delegation->access_level) }}
                                </flux:badge>
                                @if ($delegation->is_permanent)
                                    <flux:text class="text-xs text-zinc-400">{{ __('Permanent') }}</flux:text>
                                @elseif ($delegation->expires_at)
                                    <flux:text class="text-xs text-zinc-400">
                                        {{ __('Expires') }} {{ $delegation->expires_at->diffForHumans() }}
                                    </flux:text>
                                @endif
                            </div>
                            <flux:text class="text-xs text-zinc-400 mt-0.5">
                                {{ __('Granted by') }} {{ $delegation->grantor->name }}
                            </flux:text>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="revoke({{ $delegation->id }})" wire:confirm="{{ __('Revoke this access?') }}" />
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if ($showCreateForm)
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
            <flux:heading size="md">{{ __('Grant Access') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('User') }}</flux:label>
                <flux:select wire:model="newDelegateId">
                    <option value="">{{ __('Select a user...') }}</option>
                    @foreach ($this->internalUsers as $user)
                        @if ($user->id !== ($this->targetUserId ?? auth()->id()))
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endif
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Access Level') }}</flux:label>
                <flux:select wire:model="newAccessLevel">
                    @foreach (\App\Models\PlannerDelegation::ACCESS_LEVELS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Duration') }}</flux:label>
                <div class="flex flex-wrap gap-2 mb-2">
                    @foreach (['today' => 'Today', 'this_week' => 'This Week', 'two_weeks' => '2 Weeks', 'one_month' => '1 Month', 'permanent' => 'Permanent'] as $key => $label)
                        <flux:button
                            size="sm"
                            :variant="$newTimeframe === $key ? 'primary' : 'ghost'"
                            wire:click="applyPreset('{{ $key }}')"
                        >
                            {{ __($label) }}
                        </flux:button>
                    @endforeach
                </div>

                @if ($newTimeframe !== 'permanent')
                    <flux:input wire:model="newExpiresAt" type="datetime-local" class="mt-2" />
                @endif
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showCreateForm', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveDelegation">{{ __('Grant Access') }}</flux:button>
            </div>
        </div>
    @endif
</div>
