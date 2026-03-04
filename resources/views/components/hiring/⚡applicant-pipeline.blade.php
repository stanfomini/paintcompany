<?php

use App\Models\Applicant;
use App\Models\JobListing;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Applicant Pipeline')]
class extends Component {
    public string $listingFilter = 'all';

    public string $sourceFilter = 'all';

    public string $ratingFilter = 'all';

    public bool $showRejected = false;

    public function boot(): void
    {
        if (! auth()->user()?->can('hiring.view')) {
            abort(403);
        }
    }

    /** @return array<string, string> */
    public function pipelineStages(): array
    {
        return collect(Applicant::STAGE_LABELS)
            ->except(['rejected'])
            ->all();
    }

    #[Computed]
    public function applicants()
    {
        return Applicant::query()
            ->with('jobListing')
            ->when($this->listingFilter !== 'all', fn ($q) => $q->where('job_listing_id', $this->listingFilter))
            ->when($this->sourceFilter !== 'all', fn ($q) => $q->where('source', $this->sourceFilter))
            ->when($this->ratingFilter !== 'all', fn ($q) => $q->where('rating', $this->ratingFilter))
            ->latest()
            ->get()
            ->groupBy('stage');
    }

    #[Computed]
    public function activeListings()
    {
        return JobListing::query()->orderBy('title')->get();
    }

    public function advanceStage(int $applicantId): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $applicant = Applicant::findOrFail($applicantId);
        $applicant->advanceStage();
        unset($this->applicants);
    }

    public function moveToStage(int $applicantId, string $stage): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        if (! in_array($stage, Applicant::STAGES)) {
            return;
        }

        $applicant = Applicant::findOrFail($applicantId);
        $applicant->update(['stage' => $stage]);

        if ($stage === 'hired') {
            $applicant->update(['hired_at' => now()]);
        }

        unset($this->applicants);
    }

    #[On('applicant-updated')]
    public function refreshPipeline(): void
    {
        unset($this->applicants);
    }
}; ?>

<div>
    <div class="max-w-[1600px] mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ __('Applicant Pipeline') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ __('Track applicants through the hiring process.') }}</flux:text>
            </div>
            <flux:button variant="ghost" icon="arrow-left" :href="route('hiring.index')" wire:navigate>
                {{ __('Dashboard') }}
            </flux:button>
        </div>

        {{-- Filters --}}
        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="sm:w-56">
                <flux:select wire:model.live="listingFilter">
                    <option value="all">{{ __('All Listings') }}</option>
                    @foreach ($this->activeListings as $listing)
                        <flux:select.option wire:key="listing-{{ $listing->id }}" value="{{ $listing->id }}">{{ $listing->title }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:w-44">
                <flux:select wire:model.live="sourceFilter">
                    <option value="all">{{ __('All Sources') }}</option>
                    @foreach (Applicant::SOURCES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:w-36">
                <flux:select wire:model.live="ratingFilter">
                    <option value="all">{{ __('All Ratings') }}</option>
                    @for ($i = 5; $i >= 1; $i--)
                        <flux:select.option value="{{ $i }}">{{ $i }} {{ str_repeat('*', $i) }}</flux:select.option>
                    @endfor
                </flux:select>
            </div>
        </div>

        {{-- Pipeline Columns --}}
        <div class="overflow-x-auto pb-4">
            <div class="flex gap-4 min-w-max">
                @foreach ($this->pipelineStages() as $stage => $label)
                    @php
                        $stageApplicants = $this->applicants->get($stage, collect());
                        $columnColors = [
                            'applied' => 'border-t-zinc-400',
                            'screening' => 'border-t-blue-500',
                            'phone_interview' => 'border-t-indigo-500',
                            'in_person' => 'border-t-purple-500',
                            'background_check' => 'border-t-yellow-500',
                            'offer' => 'border-t-amber-500',
                            'hired' => 'border-t-green-500',
                        ];
                    @endphp
                    <div wire:key="stage-{{ $stage }}" class="w-72 shrink-0">
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 border-t-4 {{ $columnColors[$stage] ?? 'border-t-zinc-400' }}">
                            <div class="px-4 py-3 flex items-center justify-between">
                                <flux:text class="font-semibold text-sm">{{ __($label) }}</flux:text>
                                <flux:badge size="sm" color="zinc">{{ $stageApplicants->count() }}</flux:badge>
                            </div>

                            <div data-stage="{{ $stage }}" class="px-3 pb-3 space-y-2 max-h-[600px] overflow-y-auto">
                                @forelse ($stageApplicants as $applicant)
                                    <div
                                        wire:key="applicant-{{ $applicant->id }}"
                                        data-applicant-id="{{ $applicant->id }}"
                                        class="group relative bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 cursor-pointer hover:border-zinc-300 dark:hover:border-zinc-600 transition-colors"
                                        wire:click="$dispatch('open-applicant-detail', { applicantId: {{ $applicant->id }} })"
                                    >
                                        <div class="drag-handle cursor-grab active:cursor-grabbing absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                            <flux:icon name="bars-3" class="h-4 w-4 text-zinc-400" />
                                        </div>
                                        <div class="flex items-start justify-between gap-2">
                                            <flux:text class="font-medium text-sm">{{ $applicant->getFullName() }}</flux:text>
                                            @if ($applicant->rating)
                                                <div class="flex shrink-0">
                                                    @for ($i = 1; $i <= 5; $i++)
                                                        <flux:icon name="star" class="h-3 w-3 {{ $i <= $applicant->rating ? 'text-yellow-400 fill-yellow-400' : 'text-zinc-300 dark:text-zinc-600' }}" />
                                                    @endfor
                                                </div>
                                            @endif
                                        </div>
                                        <flux:text class="text-xs text-zinc-500 mt-1">{{ $applicant->jobListing?->title ?? __('No listing') }}</flux:text>
                                        <div class="flex items-center justify-between mt-2">
                                            <flux:text class="text-xs text-zinc-400">{{ $applicant->created_at->diffForHumans() }}</flux:text>
                                            @if ($applicant->source)
                                                <flux:badge size="sm" color="zinc">{{ Applicant::SOURCES[$applicant->source] ?? $applicant->source }}</flux:badge>
                                            @endif
                                        </div>
                                        @can('hiring.manage')
                                            <div class="mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-800 flex gap-1">
                                                @if ($stage !== 'hired')
                                                    <flux:button variant="ghost" size="sm" class="flex-1 no-drag" wire:click.stop="advanceStage({{ $applicant->id }})">
                                                        {{ __('Next Stage') }}
                                                    </flux:button>
                                                @endif
                                                <flux:dropdown>
                                                    <flux:button variant="ghost" size="sm" class="no-drag" icon="arrows-right-left" wire:click.stop />
                                                    <flux:menu>
                                                        @foreach ($this->pipelineStages() as $targetStage => $targetLabel)
                                                            @if ($targetStage !== $stage)
                                                                <flux:menu.item wire:click.stop="moveToStage({{ $applicant->id }}, '{{ $targetStage }}')">
                                                                    {{ __($targetLabel) }}
                                                                </flux:menu.item>
                                                            @endif
                                                        @endforeach
                                                        @if ($stage !== 'hired')
                                                            <flux:menu.item wire:click.stop="moveToStage({{ $applicant->id }}, 'rejected')" class="text-red-600 dark:text-red-400">
                                                                {{ __('Reject') }}
                                                            </flux:menu.item>
                                                        @endif
                                                    </flux:menu>
                                                </flux:dropdown>
                                            </div>
                                        @endcan
                                    </div>
                                @empty
                                    <div class="py-6 text-center">
                                        <flux:text class="text-xs text-zinc-400">{{ __('No applicants') }}</flux:text>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Rejected Applicants --}}
        @php
            $rejectedApplicants = $this->applicants->get('rejected', collect());
        @endphp
        @if ($rejectedApplicants->count() > 0)
            <div class="mt-6">
                <button
                    wire:click="$toggle('showRejected')"
                    class="flex items-center gap-2 text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300"
                >
                    <flux:icon name="{{ $showRejected ? 'chevron-down' : 'chevron-right' }}" class="h-4 w-4" />
                    {{ __('Rejected') }} ({{ $rejectedApplicants->count() }})
                </button>

                @if ($showRejected)
                    <div class="mt-3 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">{{ __('Name') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">{{ __('Position') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">{{ __('Reason') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">{{ __('Date') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($rejectedApplicants as $applicant)
                                    <tr wire:key="rejected-{{ $applicant->id }}">
                                        <td class="px-6 py-3 whitespace-nowrap">
                                            <flux:text class="font-medium text-sm">{{ $applicant->getFullName() }}</flux:text>
                                        </td>
                                        <td class="px-6 py-3 whitespace-nowrap">
                                            <flux:text class="text-sm">{{ $applicant->jobListing?->title ?? '-' }}</flux:text>
                                        </td>
                                        <td class="px-6 py-3">
                                            <flux:text class="text-sm text-zinc-500">{{ $applicant->rejection_reason ?? '-' }}</flux:text>
                                        </td>
                                        <td class="px-6 py-3 whitespace-nowrap">
                                            <flux:text class="text-sm">{{ $applicant->updated_at->format('M d, Y') }}</flux:text>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <livewire:hiring.applicant-detail />
    <livewire:hiring.hire-modal />

    @script
    <script>
        async function loadAndInitSortable() {
            const { default: Sortable } = await import('https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/+esm');

            function initSortable() {
                document.querySelectorAll('[data-stage]').forEach(el => {
                    if (el._sortable) return;
                    el._sortable = Sortable.create(el, {
                        group: 'pipeline',
                        animation: 150,
                        ghostClass: 'opacity-30',
                        dragClass: 'shadow-xl',
                        handle: '.drag-handle',
                        filter: '.no-drag',
                        onEnd: function(evt) {
                            const applicantId = parseInt(evt.item.dataset.applicantId);
                            const newStage = evt.to.dataset.stage;
                            if (applicantId && newStage) {
                                $wire.moveToStage(applicantId, newStage);
                            }
                        }
                    });
                });
            }

            initSortable();
            Livewire.hook('morph.updated', () => {
                setTimeout(initSortable, 100);
            });
        }
        loadAndInitSortable();
    </script>
    @endscript
</div>
