<?php

use App\Models\PermissionScope;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $userId;
    public string $newPermission = '';
    public string $newScopeType = 'grant';
    public ?array $newScopeValue = null;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
    }

    #[Computed]
    public function user(): User
    {
        return User::findOrFail($this->userId);
    }

    #[Computed]
    public function overrides(): \Illuminate\Support\Collection
    {
        return PermissionScope::where('user_id', $this->userId)
            ->with('grantedBy')
            ->orderBy('permission')
            ->get();
    }

    #[Computed]
    public function availablePermissions(): array
    {
        return [
            'territories.manage_regions',
            'territories.manage_zones',
            'territories.manage_blocks',
            'territories.assign_team',
            'territories.view_all',
            'territories.view_own',
            'territories.dispatch_blocks',
            'commission.configure',
            'commission.view_team',
            'commission.view_all',
            'analytics.territory_pnl',
            'compensation.planner',
        ];
    }

    public function addOverride(): void
    {
        $this->validate([
            'newPermission' => 'required|string',
            'newScopeType' => 'required|in:grant,revoke',
        ]);

        $service = app(PermissionScopeService::class);

        if ($this->newScopeType === 'grant') {
            $service->grantPermission(
                $this->user,
                $this->newPermission,
                $this->newScopeValue,
                auth()->user()
            );
        } else {
            $service->revokePermission(
                $this->user,
                $this->newPermission,
                auth()->user()
            );
        }

        $this->reset('newPermission', 'newScopeType', 'newScopeValue');
        unset($this->overrides);
    }

    public function removeOverride(int $id): void
    {
        PermissionScope::where('id', $id)
            ->where('user_id', $this->userId)
            ->delete();

        unset($this->overrides);
    }
};
?>

<div class="space-y-4">
    <flux:heading size="lg">Permission Overrides for {{ $this->user->name }}</flux:heading>
    <p class="text-sm text-zinc-500">Grant or revoke specific permissions beyond this user's role defaults.</p>

    {{-- Current overrides --}}
    @forelse($this->overrides as $override)
        <div wire:key="override-{{ $override->id }}" class="flex items-center justify-between p-3 rounded-lg border border-zinc-200 dark:border-zinc-700">
            <div>
                <span class="font-mono text-sm">{{ $override->permission }}</span>
                <flux:badge size="sm" color="{{ $override->scope_type === 'grant' ? 'green' : 'red' }}">
                    {{ $override->scope_type }}
                </flux:badge>
                @if($override->scope_value)
                    <span class="text-xs text-zinc-500 ml-2">Scoped: {{ json_encode($override->scope_value) }}</span>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-zinc-400">by {{ $override->grantedBy?->name ?? 'System' }}</span>
                <flux:button size="xs" variant="danger" wire:click="removeOverride({{ $override->id }})">Remove</flux:button>
            </div>
        </div>
    @empty
        <p class="text-sm text-zinc-500">No permission overrides configured.</p>
    @endforelse

    {{-- Add new override --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
        <flux:heading size="sm">Add Override</flux:heading>
        <div class="flex gap-2 items-end">
            <flux:select wire:model="newPermission" label="Permission" class="flex-1">
                <option value="">Select permission...</option>
                @foreach($this->availablePermissions as $perm)
                    <option value="{{ $perm }}">{{ $perm }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model="newScopeType" label="Type" class="w-32">
                <option value="grant">Grant</option>
                <option value="revoke">Revoke</option>
            </flux:select>
            <flux:button wire:click="addOverride" variant="primary">Add</flux:button>
        </div>
    </div>
</div>
