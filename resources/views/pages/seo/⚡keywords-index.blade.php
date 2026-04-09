<?php

use App\Models\Keyword;
use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('SEO Keywords')]
class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $intent = '';

    #[Url(as: 'svc')]
    public ?int $serviceId = null;

    #[Url(as: 'area')]
    public ?int $serviceAreaId = null;

    #[Url(as: 'unassigned')]
    public bool $unassignedOnly = false;

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'status', 'source', 'intent', 'serviceId', 'serviceAreaId', 'unassignedOnly'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'source', 'intent', 'serviceId', 'serviceAreaId', 'unassignedOnly']);
        $this->resetPage();
    }

    #[Computed]
    public function keywords(): LengthAwarePaginator
    {
        return Keyword::query()
            ->withCount('assignments')
            ->when($this->search !== '', fn ($q) => $q->search($this->search))
            ->when($this->status !== '', fn ($q) => $q->status($this->status))
            ->when($this->source !== '', fn ($q) => $q->source($this->source))
            ->when($this->intent !== '', fn ($q) => $q->intent($this->intent))
            ->when($this->serviceId !== null, fn ($q) => $q->whereHas('assignments', fn ($a) => $a->where('service_id', $this->serviceId)))
            ->when($this->serviceAreaId !== null, fn ($q) => $q->whereHas('assignments', fn ($a) => $a->where('service_area_id', $this->serviceAreaId)))
            ->when($this->unassignedOnly, fn ($q) => $q->doesntHave('assignments'))
            ->orderByDesc('id')
            ->paginate(25);
    }

    #[Computed]
    public function services(): \Illuminate\Support\Collection
    {
        return Service::query()->where('is_active', true)->orderBy('title')->get(['id', 'title']);
    }

    #[Computed]
    public function serviceAreas(): \Illuminate\Support\Collection
    {
        return ServiceArea::query()->where('is_active', true)->orderBy('city')->get(['id', 'city']);
    }

    #[Computed]
    public function statuses(): array
    {
        return Keyword::STATUSES;
    }

    #[Computed]
    public function sources(): array
    {
        return Keyword::SOURCES;
    }

    #[Computed]
    public function intents(): array
    {
        return Keyword::INTENTS;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('SEO Keywords') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Researched keywords assigned to landing pages.') }}</flux:text>
        </div>
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('seo.keywords.import') }}" variant="primary" icon="arrow-up-tray" wire:navigate>
                {{ __('Import Keywords') }}
            </flux:button>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-6 grid grid-cols-1 gap-3 md:grid-cols-3 lg:grid-cols-6">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search keywords...') }}" icon="magnifying-glass" />

        <flux:select wire:model.live="status" placeholder="{{ __('Status') }}">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach($this->statuses as $option)
                <flux:select.option :value="$option">{{ Str::title(str_replace('_', ' ', $option)) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="source" placeholder="{{ __('Source') }}">
            <flux:select.option value="">{{ __('All sources') }}</flux:select.option>
            @foreach($this->sources as $option)
                <flux:select.option :value="$option">{{ Str::title(str_replace('_', ' ', $option)) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="intent" placeholder="{{ __('Intent') }}">
            <flux:select.option value="">{{ __('All intents') }}</flux:select.option>
            @foreach($this->intents as $option)
                <flux:select.option :value="$option">{{ Str::title($option) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="serviceId" placeholder="{{ __('Service') }}">
            <flux:select.option value="">{{ __('Any service') }}</flux:select.option>
            @foreach($this->services as $service)
                <flux:select.option :value="$service->id">{{ $service->title }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="serviceAreaId" placeholder="{{ __('Area') }}">
            <flux:select.option value="">{{ __('Any area') }}</flux:select.option>
            @foreach($this->serviceAreas as $area)
                <flux:select.option :value="$area->id">{{ $area->city }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="mb-4 flex items-center justify-between">
        <flux:checkbox wire:model.live="unassignedOnly" :label="__('Show only unassigned keywords')" />

        @if($search !== '' || $status !== '' || $source !== '' || $intent !== '' || $serviceId !== null || $serviceAreaId !== null || $unassignedOnly)
            <flux:button size="sm" variant="ghost" wire:click="clearFilters" icon="x-mark">
                {{ __('Clear filters') }}
            </flux:button>
        @endif
    </div>

    {{-- Table --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        @if($this->keywords->isEmpty())
            <div class="p-12 text-center">
                <flux:icon.magnifying-glass class="mx-auto h-10 w-10 text-zinc-400" />
                <flux:heading size="md" class="mt-3">{{ __('No keywords yet') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ __('Import your researched keywords to start targeting landing pages.') }}
                </flux:text>
                <div class="mt-4">
                    <flux:button href="{{ route('seo.keywords.import') }}" variant="primary" icon="arrow-up-tray" wire:navigate>
                        {{ __('Import Keywords') }}
                    </flux:button>
                </div>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-left text-xs uppercase text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3">{{ __('Keyword') }}</th>
                        <th class="px-4 py-3">{{ __('Volume') }}</th>
                        <th class="px-4 py-3">{{ __('Intent') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Source') }}</th>
                        <th class="px-4 py-3">{{ __('Pages') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @foreach($this->keywords as $keyword)
                        <tr wire:key="kw-{{ $keyword->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-900">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $keyword->keyword }}</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $keyword->search_volume ? number_format($keyword->search_volume) : '—' }}</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $keyword->intent ? Str::title($keyword->intent) : '—' }}</td>
                            <td class="px-4 py-3">
                                <flux:badge size="sm" :color="match($keyword->status) {
                                    'won' => 'green',
                                    'ranking' => 'blue',
                                    'targeted' => 'amber',
                                    'dropped' => 'red',
                                    default => 'zinc',
                                }">{{ Str::title($keyword->status) }}</flux:badge>
                            </td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ Str::title(str_replace('_', ' ', $keyword->source)) }}</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $keyword->assignments_count }}</td>
                            <td class="px-4 py-3 text-right">
                                <flux:button size="sm" variant="ghost" href="{{ route('seo.keywords.edit', $keyword) }}" wire:navigate>
                                    {{ __('Edit') }}
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $this->keywords->links() }}
            </div>
        @endif
    </div>
</section>
