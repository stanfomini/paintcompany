<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $creatingChildOfId = null;
    public string $childLevel = '';
    public string $childName = '';

    #[Computed]
    public function territories(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);

        $query = SalesTerritory::query()
            ->with(['children.children', 'assignedUser', 'manager', 'teamMembers'])
            ->whereNull('parent_id')
            ->orderBy('name');

        if (! $scopeService->hasPermission($user, 'territories.view_all')) {
            $visibleIds = $scopeService->getVisibleTerritoryIds($user);
            $query->whereIn('id', $visibleIds);
        }

        return $query->get();
    }

    #[Computed]
    public function internalUsers(): \Illuminate\Support\Collection
    {
        return User::where('type', 'internal')->where('is_active', true)->orderBy('name')->get();
    }

    public function startCreateChild(int $parentId, string $level): void
    {
        $this->creatingChildOfId = $parentId;
        $this->childLevel = $level;
        $this->childName = '';
    }

    public function cancelCreate(): void
    {
        $this->reset('creatingChildOfId', 'childLevel', 'childName');
    }

    public function saveChild(): void
    {
        $this->validate([
            'childName' => 'required|string|max:255',
            'creatingChildOfId' => 'required|exists:sales_territories,id',
            'childLevel' => 'required|in:zone,block',
        ]);

        $parent = SalesTerritory::findOrFail($this->creatingChildOfId);

        SalesTerritory::create([
            'name' => $this->childName,
            'level' => $this->childLevel,
            'parent_id' => $parent->id,
            'manager_id' => $parent->assigned_user_id,
            'status' => 'active',
        ]);

        $this->reset('creatingChildOfId', 'childLevel', 'childName');
        unset($this->territories);
    }

    public function assignUser(int $territoryId, int $userId): void
    {
        $territory = SalesTerritory::findOrFail($territoryId);
        $territory->update(['assigned_user_id' => $userId]);
        unset($this->territories);
    }

    public function deleteTerritory(int $id): void
    {
        $territory = SalesTerritory::findOrFail($id);

        if ($territory->children()->count() > 0) {
            $this->addError('delete', 'Cannot delete a territory with children. Remove children first.');
            return;
        }

        $territory->delete();
        unset($this->territories);
    }
};
?>

<div class="space-y-4">
    {{-- Errors --}}
    @error('delete')
        <flux:callout variant="danger">{{ $message }}</flux:callout>
    @enderror

    {{-- Hierarchy Tree --}}
    @forelse($this->territories as $region)
        <div wire:key="region-{{ $region->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            {{-- Region Header --}}
            <div x-data="{ expanded: true }" class="bg-zinc-50 dark:bg-zinc-800">
                <div class="flex items-center justify-between p-3 cursor-pointer" @click="expanded = !expanded">
                    <div class="flex items-center gap-2">
                        <svg x-show="expanded" class="w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        <svg x-show="!expanded" class="w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <flux:badge variant="primary" size="sm">Region</flux:badge>
                        <span class="font-semibold text-sm">{{ $region->name }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-zinc-500">
                        @if($region->assignedUser)
                            <span>{{ $region->assignedUser->name }}</span>
                        @endif
                        <span>{{ $region->children->count() }} zones</span>
                        @can('territories.manage_zones')
                        <flux:button size="xs" variant="ghost" wire:click="startCreateChild({{ $region->id }}, 'zone')">+ Zone</flux:button>
                        @endcan
                    </div>
                </div>

                {{-- Zones --}}
                <div x-show="expanded" x-collapse>
                    @foreach($region->children as $zone)
                        <div wire:key="zone-{{ $zone->id }}" x-data="{ zoneExpanded: true }" class="border-t border-zinc-200 dark:border-zinc-700">
                            <div class="flex items-center justify-between px-3 py-2 pl-8 cursor-pointer" @click="zoneExpanded = !zoneExpanded">
                                <div class="flex items-center gap-2">
                                    <svg x-show="zoneExpanded" class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    <svg x-show="!zoneExpanded" class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    <flux:badge variant="info" size="sm">Zone</flux:badge>
                                    <span class="text-sm">{{ $zone->name }}</span>
                                </div>
                                <div class="flex items-center gap-2 text-xs text-zinc-500">
                                    @if($zone->assignedUser)
                                        <span>{{ $zone->assignedUser->name }}</span>
                                    @endif
                                    <span>{{ $zone->children->count() }} blocks</span>
                                    @can('territories.manage_blocks')
                                    <flux:button size="xs" variant="ghost" wire:click="startCreateChild({{ $zone->id }}, 'block')">+ Block</flux:button>
                                    @endcan
                                </div>
                            </div>

                            {{-- Blocks --}}
                            <div x-show="zoneExpanded" x-collapse>
                                @foreach($zone->children as $block)
                                    <div wire:key="block-{{ $block->id }}" class="flex items-center justify-between px-3 py-1.5 pl-14 border-t border-zinc-100 dark:border-zinc-700/50 text-sm">
                                        <div class="flex items-center gap-2">
                                            <flux:badge size="sm">Block</flux:badge>
                                            <span>{{ $block->name }}</span>
                                        </div>
                                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                                            @if($block->assignedUser)
                                                <span>{{ $block->assignedUser->name }}</span>
                                            @else
                                                <span class="italic text-zinc-400">Unassigned</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @empty
        <div class="text-center py-12 text-zinc-500 dark:text-zinc-400">
            <p class="text-lg font-medium">No territories yet</p>
            <p class="text-sm mt-1">Create a region from the Map tab to get started.</p>
        </div>
    @endforelse

    {{-- Create Child Modal --}}
    @if($creatingChildOfId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="cancelCreate">
            <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-lg max-w-md w-full p-6 space-y-4">
                <flux:heading size="lg">Create {{ ucfirst($childLevel) }}</flux:heading>
                <flux:input wire:model="childName" label="Name" placeholder="Enter {{ $childLevel }} name" autofocus />
                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancelCreate">Cancel</flux:button>
                    <flux:button variant="primary" wire:click="saveChild">Create</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
