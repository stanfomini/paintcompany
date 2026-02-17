<?php

use App\Models\EmployeeOnboardingProgress;
use App\Models\OnboardingChecklist;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('My Onboarding')]
class extends Component {
    public ?int $notesItemId = null;

    public string $notesText = '';

    public function boot(): void
    {
        if (! auth()->user()?->employeeProfile) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $this->ensureProgressRecordsExist();
    }

    #[Computed]
    public function employeeProfile()
    {
        return auth()->user()->employeeProfile;
    }

    #[Computed]
    public function checklists()
    {
        return OnboardingChecklist::query()
            ->active()
            ->forRole($this->employeeProfile->job_title)
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function progress()
    {
        return $this->employeeProfile
            ->onboardingProgress()
            ->with('employeeContract')
            ->get()
            ->keyBy('onboarding_checklist_item_id');
    }

    #[Computed]
    public function completionStats(): array
    {
        $allItems = $this->checklists->flatMap->items;
        $requiredItems = $allItems->where('is_required', true);
        $total = $requiredItems->count();

        if ($total === 0) {
            return ['total' => 0, 'completed' => 0, 'percentage' => 100];
        }

        $completed = $requiredItems->filter(function ($item) {
            $progress = $this->progress->get($item->id);

            return $progress && in_array($progress->status, ['completed', 'skipped']);
        })->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
        ];
    }

    public function toggleItem(int $itemId): void
    {
        $item = \App\Models\OnboardingChecklistItem::find($itemId);
        if ($item && $item->type === 'contract') {
            return;
        }

        $progress = $this->getOrCreateProgress($itemId);

        if ($progress->status === 'completed') {
            $progress->update([
                'status' => 'pending',
                'completed_at' => null,
                'completed_by' => null,
            ]);
        } else {
            $progress->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => auth()->id(),
            ]);
        }

        unset($this->progress, $this->completionStats);
    }

    public function openNotes(int $itemId): void
    {
        $this->notesItemId = $itemId;
        $progress = $this->progress->get($itemId);
        $this->notesText = $progress?->notes ?? '';
    }

    public function addNote(): void
    {
        if ($this->notesItemId) {
            $progress = $this->getOrCreateProgress($this->notesItemId);
            $progress->update(['notes' => $this->notesText ?: null]);
            $this->notesItemId = null;
            $this->notesText = '';
            unset($this->progress);
        }
    }

    private function ensureProgressRecordsExist(): void
    {
        $profile = auth()->user()->employeeProfile;

        $checklists = OnboardingChecklist::query()
            ->active()
            ->forRole($profile->job_title)
            ->with('items')
            ->get();

        $existingItemIds = $profile
            ->onboardingProgress()
            ->pluck('onboarding_checklist_item_id')
            ->toArray();

        $newRecords = [];
        foreach ($checklists as $checklist) {
            foreach ($checklist->items as $item) {
                if (! in_array($item->id, $existingItemIds)) {
                    $newRecords[] = [
                        'employee_profile_id' => $profile->id,
                        'onboarding_checklist_item_id' => $item->id,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        if (! empty($newRecords)) {
            EmployeeOnboardingProgress::insert($newRecords);
        }
    }

    private function getOrCreateProgress(int $itemId): EmployeeOnboardingProgress
    {
        return EmployeeOnboardingProgress::firstOrCreate(
            [
                'employee_profile_id' => $this->employeeProfile->id,
                'onboarding_checklist_item_id' => $itemId,
            ],
            ['status' => 'pending']
        );
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('My Onboarding') }}</flux:heading>
            <flux:text class="font-medium">
                {{ $this->completionStats['completed'] }}/{{ $this->completionStats['total'] }}
                {{ __('completed') }}
            </flux:text>
        </div>

        {{-- Progress Bar --}}
        <div class="mb-6">
            <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-3">
                <div class="h-3 rounded-full transition-all duration-300 {{ $this->completionStats['percentage'] === 100 ? 'bg-green-500' : 'bg-blue-500' }}"
                     style="width: {{ $this->completionStats['percentage'] }}%"></div>
            </div>
            <flux:text class="text-sm text-zinc-500 mt-1">{{ $this->completionStats['percentage'] }}% {{ __('complete') }}</flux:text>

            @if ($this->completionStats['percentage'] === 100 && $this->employeeProfile->onboarding_completed_at)
                <flux:callout variant="success" class="mt-4">
                    {{ __('Onboarding completed on') }} {{ $this->employeeProfile->onboarding_completed_at->format('M d, Y') }}
                </flux:callout>
            @elseif ($this->completionStats['percentage'] === 100)
                <flux:callout variant="success" class="mt-4">
                    {{ __('All required items are complete! Your manager will finalize your onboarding.') }}
                </flux:callout>
            @endif
        </div>

        {{-- Checklists --}}
        @forelse ($this->checklists as $checklist)
            <div wire:key="checklist-{{ $checklist->id }}" class="mb-6">
                <div class="flex items-center gap-2 mb-3">
                    <flux:heading size="sm">{{ $checklist->name }}</flux:heading>
                    @if ($checklist->description)
                        <flux:text class="text-sm text-zinc-500">{{ $checklist->description }}</flux:text>
                    @endif
                </div>

                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($checklist->items as $item)
                        @php
                            $itemProgress = $this->progress->get($item->id);
                            $status = $itemProgress?->status ?? 'pending';
                            $typeColors = ['document' => 'blue', 'contract' => 'purple', 'training' => 'amber', 'task' => 'green'];
                            $isContractItem = $item->type === 'contract';
                            $contract = $isContractItem ? $itemProgress?->employeeContract : null;
                            $contractStatus = $contract?->status;
                        @endphp
                        <div wire:key="item-{{ $item->id }}" class="flex items-center justify-between px-4 py-3 {{ $status === 'completed' ? 'bg-green-50 dark:bg-green-900/10' : '' }}">
                            <div class="flex items-center gap-3">
                                @if ($isContractItem)
                                    @if ($contractStatus === 'signed')
                                        <flux:icon name="check-circle" class="w-5 h-5 text-green-500 shrink-0" />
                                    @elseif (in_array($contractStatus, ['sent', 'viewed']))
                                        <flux:icon name="clock" class="w-5 h-5 text-yellow-500 shrink-0" />
                                    @else
                                        <flux:icon name="document-text" class="w-5 h-5 text-zinc-400 shrink-0" />
                                    @endif
                                @else
                                    <flux:checkbox
                                        wire:click="toggleItem({{ $item->id }})"
                                        :checked="$status === 'completed'"
                                    />
                                @endif
                                <div>
                                    <div class="flex items-center gap-2">
                                        <flux:text class="font-medium {{ $status === 'completed' ? 'line-through text-zinc-400' : '' }}">
                                            {{ $item->title }}
                                        </flux:text>
                                        <flux:badge size="sm" color="{{ $typeColors[$item->type] ?? 'zinc' }}">{{ ucfirst($item->type) }}</flux:badge>
                                        @if ($item->is_required)
                                            <flux:badge size="sm" color="red">{{ __('Required') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Optional') }}</flux:badge>
                                        @endif
                                        @if ($isContractItem)
                                            @if ($contractStatus === 'signed')
                                                <flux:badge size="sm" color="green">{{ __('Signed on') }} {{ $contract->signed_at->format('M d, Y') }}</flux:badge>
                                            @elseif (in_array($contractStatus, ['sent', 'viewed']))
                                                <flux:badge size="sm" color="yellow">{{ __('Awaiting Signature') }}</flux:badge>
                                            @elseif ($contractStatus === 'draft')
                                                <flux:badge size="sm" color="zinc">{{ __('Draft') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="zinc">{{ __('Not Assigned') }}</flux:badge>
                                            @endif
                                        @endif
                                    </div>
                                    @if ($item->description)
                                        <flux:text class="text-sm text-zinc-500">{{ $item->description }}</flux:text>
                                    @endif
                                    @if ($itemProgress?->notes)
                                        <flux:text class="text-sm text-blue-500 mt-1">{{ __('Note:') }} {{ $itemProgress->notes }}</flux:text>
                                    @endif
                                    @if (! $isContractItem && $itemProgress?->completed_at)
                                        <flux:text class="text-xs text-zinc-400 mt-1">
                                            {{ __('Completed') }} {{ $itemProgress->completed_at->format('M d, Y g:i A') }}
                                        </flux:text>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                @if ($isContractItem && $contract && ! in_array($contractStatus, ['signed', 'draft']))
                                    <flux:button variant="primary" size="sm" icon="pencil-square"
                                        href="{{ url('/contracts/'.$contract->id) }}"
                                    >
                                        {{ __('Sign Contract') }}
                                    </flux:button>
                                @endif
                                <flux:button variant="ghost" size="sm" icon="chat-bubble-left"
                                    wire:click="openNotes({{ $item->id }})"
                                    title="{{ __('Notes') }}"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="clipboard-document-list" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No onboarding checklists') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    {{ __('No active onboarding checklists are available for your role.') }}
                </flux:text>
            </div>
        @endforelse
    </div>

    {{-- Notes Modal --}}
    @if ($notesItemId)
        <flux:modal wire:model.self="notesItemId" class="max-w-md">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Item Notes') }}</flux:heading>
                <flux:textarea wire:model="notesText" rows="3" placeholder="{{ __('Add notes about this item...') }}" />
                <div class="flex justify-end gap-3">
                    <flux:button wire:click="$set('notesItemId', null)">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" wire:click="addNote">{{ __('Save Notes') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
