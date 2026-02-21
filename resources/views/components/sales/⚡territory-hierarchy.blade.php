<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    // Create territory form
    public bool $showCreateModal = false;
    public ?int $creatingChildOfId = null;
    public string $childLevel = '';
    public string $childName = '';

    // Assign team member form
    public ?int $assigningTerritoryId = null;
    public ?int $selectedUserId = null;
    public string $teamRole = 'knocker';

    #[Computed]
    public function territories(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);

        $query = SalesTerritory::query()
            ->with([
                'children.children.assignedUser',
                'children.children.teamMembers',
                'children.assignedUser',
                'children.teamMembers',
                'assignedUser',
                'manager',
                'teamMembers',
            ])
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

    public function startCreateRegion(): void
    {
        $this->creatingChildOfId = null;
        $this->childLevel = 'region';
        $this->childName = '';
        $this->showCreateModal = true;
    }

    public function startCreateChild(int $parentId, string $level): void
    {
        $this->creatingChildOfId = $parentId;
        $this->childLevel = $level;
        $this->childName = '';
        $this->showCreateModal = true;
    }

    public function cancelCreate(): void
    {
        $this->showCreateModal = false;
        $this->reset('creatingChildOfId', 'childLevel', 'childName');
    }

    public function saveChild(): void
    {
        $this->validate([
            'childName' => 'required|string|max:255',
            'childLevel' => 'required|in:region,zone,block',
        ]);

        if ($this->childLevel !== 'region') {
            $this->validate(['creatingChildOfId' => 'required|exists:sales_territories,id']);
        }

        $data = [
            'name' => $this->childName,
            'level' => $this->childLevel,
            'parent_id' => $this->creatingChildOfId,
            'status' => 'active',
        ];

        if ($this->creatingChildOfId) {
            $parent = SalesTerritory::findOrFail($this->creatingChildOfId);
            $data['manager_id'] = $parent->assigned_user_id;
        }

        SalesTerritory::create($data);

        $this->showCreateModal = false;
        $this->reset('creatingChildOfId', 'childLevel', 'childName');
        unset($this->territories);
    }

    public function assignUser(int $territoryId, ?int $userId): void
    {
        $territory = SalesTerritory::findOrFail($territoryId);
        $territory->update(['assigned_user_id' => $userId ?: null]);
        unset($this->territories);
    }

    public function assignManager(int $territoryId, ?int $userId): void
    {
        $territory = SalesTerritory::findOrFail($territoryId);
        $territory->update(['manager_id' => $userId ?: null]);
        unset($this->territories);
    }

    public function startAddTeamMember(int $territoryId): void
    {
        $this->assigningTerritoryId = $territoryId;
        $this->selectedUserId = null;
        $this->teamRole = 'knocker';
    }

    public function addTeamMember(): void
    {
        $this->validate([
            'assigningTerritoryId' => 'required|exists:sales_territories,id',
            'selectedUserId' => 'required|exists:users,id',
            'teamRole' => 'required|in:knocker,estimator,manager',
        ]);

        $territory = SalesTerritory::findOrFail($this->assigningTerritoryId);

        // Don't add duplicates
        if (! $territory->teamMembers()->where('user_id', $this->selectedUserId)->exists()) {
            $territory->teamMembers()->attach($this->selectedUserId, [
                'role' => $this->teamRole,
                'added_by' => auth()->id(),
            ]);
        }

        $this->reset('assigningTerritoryId', 'selectedUserId', 'teamRole');
        unset($this->territories);
    }

    public function removeTeamMember(int $territoryId, int $userId): void
    {
        $territory = SalesTerritory::findOrFail($territoryId);
        $territory->teamMembers()->detach($userId);
        unset($this->territories);
    }

    public function cancelAddTeamMember(): void
    {
        $this->reset('assigningTerritoryId', 'selectedUserId', 'teamRole');
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
    {{-- Header with Create Region button --}}
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="lg">Territory Hierarchy</flux:heading>
            <flux:text size="sm" class="mt-0.5">Manage regions, zones, and blocks.</flux:text>
        </div>
        @can('territories.manage_regions')
            <flux:button variant="primary" size="sm" wire:click="startCreateRegion">
                + Region
            </flux:button>
        @endcan
    </div>

    {{-- Errors --}}
    @error('delete')
        <flux:callout variant="danger">{{ $message }}</flux:callout>
    @enderror

    {{-- Hierarchy Tree --}}
    @forelse($this->territories as $region)
        <div wire:key="region-{{ $region->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            {{-- Region Header --}}
            <div x-data="{ expanded: true }" class="bg-zinc-50 dark:bg-zinc-800">
                <div class="flex items-center justify-between p-3">
                    <div class="flex items-center gap-2 cursor-pointer" @click="expanded = !expanded">
                        <svg x-show="expanded" class="w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        <svg x-show="!expanded" class="w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <flux:badge color="purple" size="sm">Region</flux:badge>
                        <span class="font-semibold text-sm">{{ $region->name }}</span>
                        <span class="text-xs text-zinc-400">({{ $region->children->count() }} zones)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        {{-- Manager assignment --}}
                        @can('territories.assign_team')
                            <flux:select size="sm" class="w-40" wire:change="assignManager({{ $region->id }}, $event.target.value)">
                                <option value="">Manager...</option>
                                @foreach($this->internalUsers as $u)
                                    <option value="{{ $u->id }}" @selected($region->manager_id === $u->id)>{{ $u->name }}</option>
                                @endforeach
                            </flux:select>
                        @else
                            @if($region->manager)
                                <span class="text-xs text-zinc-500">{{ $region->manager->name }}</span>
                            @endif
                        @endcan
                        @can('territories.manage_zones')
                            <flux:button size="xs" variant="ghost" wire:click="startCreateChild({{ $region->id }}, 'zone')">+ Zone</flux:button>
                        @endcan
                        @can('territories.assign_team')
                            <flux:button size="xs" variant="ghost" wire:click="startAddTeamMember({{ $region->id }})">+ Team</flux:button>
                        @endcan
                        @can('territories.manage_regions')
                            <flux:button size="xs" variant="danger" wire:click="deleteTerritory({{ $region->id }})" wire:confirm="Delete this region?">Delete</flux:button>
                        @endcan
                    </div>
                </div>

                {{-- Region team members --}}
                @if($region->teamMembers->count())
                    <div class="px-3 pb-2 flex flex-wrap gap-1">
                        @foreach($region->teamMembers as $member)
                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-200 dark:bg-zinc-700 px-2 py-0.5 text-xs">
                                {{ $member->name }} <span class="text-zinc-400">({{ $member->pivot->role }})</span>
                                @can('territories.assign_team')
                                    <button type="button" wire:click="removeTeamMember({{ $region->id }}, {{ $member->id }})" class="text-zinc-400 hover:text-red-500">&times;</button>
                                @endcan
                            </span>
                        @endforeach
                    </div>
                @endif

                {{-- Zones --}}
                <div x-show="expanded" x-collapse>
                    @foreach($region->children as $zone)
                        <div wire:key="zone-{{ $zone->id }}" x-data="{ zoneExpanded: true }" class="border-t border-zinc-200 dark:border-zinc-700">
                            <div class="flex items-center justify-between px-3 py-2 pl-8">
                                <div class="flex items-center gap-2 cursor-pointer" @click="zoneExpanded = !zoneExpanded">
                                    <svg x-show="zoneExpanded" class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    <svg x-show="!zoneExpanded" class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    <flux:badge color="sky" size="sm">Zone</flux:badge>
                                    <span class="text-sm">{{ $zone->name }}</span>
                                    <span class="text-xs text-zinc-400">({{ $zone->children->count() }} blocks)</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    @can('territories.assign_team')
                                        <flux:select size="sm" class="w-36" wire:change="assignUser({{ $zone->id }}, $event.target.value)">
                                            <option value="">Assigned to...</option>
                                            @foreach($this->internalUsers as $u)
                                                <option value="{{ $u->id }}" @selected($zone->assigned_user_id === $u->id)>{{ $u->name }}</option>
                                            @endforeach
                                        </flux:select>
                                    @else
                                        @if($zone->assignedUser)
                                            <span class="text-xs text-zinc-500">{{ $zone->assignedUser->name }}</span>
                                        @endif
                                    @endcan
                                    @can('territories.manage_blocks')
                                        <flux:button size="xs" variant="ghost" wire:click="startCreateChild({{ $zone->id }}, 'block')">+ Block</flux:button>
                                    @endcan
                                    @can('territories.assign_team')
                                        <flux:button size="xs" variant="ghost" wire:click="startAddTeamMember({{ $zone->id }})">+ Team</flux:button>
                                    @endcan
                                    @can('territories.manage_zones')
                                        <flux:button size="xs" variant="danger" wire:click="deleteTerritory({{ $zone->id }})" wire:confirm="Delete this zone?">Delete</flux:button>
                                    @endcan
                                </div>
                            </div>

                            {{-- Zone team members --}}
                            @if($zone->teamMembers->count())
                                <div class="px-3 pb-1 pl-12 flex flex-wrap gap-1">
                                    @foreach($zone->teamMembers as $member)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-200 dark:bg-zinc-700 px-2 py-0.5 text-xs">
                                            {{ $member->name }} <span class="text-zinc-400">({{ $member->pivot->role }})</span>
                                            @can('territories.assign_team')
                                                <button type="button" wire:click="removeTeamMember({{ $zone->id }}, {{ $member->id }})" class="text-zinc-400 hover:text-red-500">&times;</button>
                                            @endcan
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Blocks --}}
                            <div x-show="zoneExpanded" x-collapse>
                                @foreach($zone->children as $block)
                                    <div wire:key="block-{{ $block->id }}" class="flex items-center justify-between px-3 py-1.5 pl-14 border-t border-zinc-100 dark:border-zinc-700/50 text-sm">
                                        <div class="flex items-center gap-2">
                                            <flux:badge size="sm">Block</flux:badge>
                                            <span>{{ $block->name }}</span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            @can('territories.assign_team')
                                                <flux:select size="sm" class="w-36" wire:change="assignUser({{ $block->id }}, $event.target.value)">
                                                    <option value="">Assigned to...</option>
                                                    @foreach($this->internalUsers as $u)
                                                        <option value="{{ $u->id }}" @selected($block->assigned_user_id === $u->id)>{{ $u->name }}</option>
                                                    @endforeach
                                                </flux:select>
                                            @else
                                                @if($block->assignedUser)
                                                    <span class="text-xs text-zinc-500">{{ $block->assignedUser->name }}</span>
                                                @else
                                                    <span class="text-xs italic text-zinc-400">Unassigned</span>
                                                @endif
                                            @endcan
                                            @can('territories.manage_blocks')
                                                <flux:button size="xs" variant="danger" wire:click="deleteTerritory({{ $block->id }})" wire:confirm="Delete this block?">Delete</flux:button>
                                            @endcan
                                        </div>
                                    </div>

                                    {{-- Block team members --}}
                                    @if($block->teamMembers->count())
                                        <div class="px-3 py-1 pl-20 flex flex-wrap gap-1">
                                            @foreach($block->teamMembers as $member)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-200 dark:bg-zinc-700 px-2 py-0.5 text-xs">
                                                    {{ $member->name }} <span class="text-zinc-400">({{ $member->pivot->role }})</span>
                                                    @can('territories.assign_team')
                                                        <button type="button" wire:click="removeTeamMember({{ $block->id }}, {{ $member->id }})" class="text-zinc-400 hover:text-red-500">&times;</button>
                                                    @endcan
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
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
            <p class="text-sm mt-1">Click "+ Region" above to create your first region.</p>
        </div>
    @endforelse

    {{-- Create Territory Modal --}}
    @if($showCreateModal)
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

    {{-- Add Team Member Modal --}}
    @if($assigningTerritoryId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="cancelAddTeamMember">
            <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-lg max-w-md w-full p-6 space-y-4">
                <flux:heading size="lg">Add Team Member</flux:heading>
                <flux:select wire:model="selectedUserId" label="User">
                    <option value="">Select a user...</option>
                    @foreach($this->internalUsers as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="teamRole" label="Role">
                    <option value="knocker">Door Knocker</option>
                    <option value="estimator">Estimator</option>
                    <option value="manager">Manager</option>
                </flux:select>
                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancelAddTeamMember">Cancel</flux:button>
                    <flux:button variant="primary" wire:click="addTeamMember">Add</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
