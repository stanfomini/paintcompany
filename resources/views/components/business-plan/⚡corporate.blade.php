<?php

use App\Models\Corporation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'corp-section')]
    public string $activeSection = 'corporations';

    public ?int $selectedCorporationId = null;

    public function boot(): void
    {
        if (! Auth::user()?->can('business_plan.view')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $first = Corporation::active()->first();
        $this->selectedCorporationId = $first?->id;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('business_plan.manage') ?? false;
    }

    #[Computed]
    public function corporations(): \Illuminate\Database\Eloquent\Collection
    {
        return Corporation::active()->orderBy('name')->get();
    }

    #[Computed]
    public function sections(): array
    {
        return [
            'corporations' => ['label' => __('Corporations'), 'icon' => 'building-office'],
            'documents' => ['label' => __('Documents'), 'icon' => 'document-text'],
            'minutes' => ['label' => __('Minutes'), 'icon' => 'microphone'],
            'send-packet' => ['label' => __('Send Packet'), 'icon' => 'paper-airplane'],
        ];
    }

    public function setSection(string $section): void
    {
        if (array_key_exists($section, $this->sections)) {
            $this->activeSection = $section;
        }
    }

    public function selectCorporation(?int $id): void
    {
        $this->selectedCorporationId = $id;
    }
};
?>

<div>
    {{-- Sub-navigation --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex gap-1 overflow-x-auto">
            @foreach ($this->sections as $key => $section)
                <button
                    wire:key="section-{{ $key }}"
                    wire:click="setSection('{{ $key }}')"
                    class="flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition-colors
                        {{ $activeSection === $key
                            ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-300' }}"
                >
                    <flux:icon :name="$section['icon']" variant="mini" class="size-4" />
                    {{ $section['label'] }}
                </button>
            @endforeach
        </div>

        {{-- Corporation selector (shown for docs/minutes/packet sections) --}}
        @if ($activeSection !== 'corporations' && $this->corporations->isNotEmpty())
            <div class="flex items-center gap-2">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Corporation:') }}</flux:text>
                <flux:select wire:model.live="selectedCorporationId" size="sm" class="w-64">
                    @foreach ($this->corporations as $corp)
                        <flux:select.option :value="$corp->id">{{ $corp->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </div>

    {{-- Section content --}}
    <div>
        @if ($activeSection === 'corporations')
            <livewire:dynamic-component :component="'corporate.corporation-list'" :key="'corp-list'" />
        @elseif ($activeSection === 'documents' && $selectedCorporationId)
            <livewire:dynamic-component
                :component="'corporate.document-manager'"
                :corporation-id="$selectedCorporationId"
                :key="'doc-mgr-' . $selectedCorporationId"
            />
        @elseif ($activeSection === 'minutes' && $selectedCorporationId)
            <livewire:dynamic-component
                :component="'corporate.meeting-minutes-list'"
                :corporation-id="$selectedCorporationId"
                :key="'min-list-' . $selectedCorporationId"
            />
        @elseif ($activeSection === 'send-packet')
            <livewire:dynamic-component :component="'corporate.document-packet-sender'" :key="'packet-sender'" />
        @else
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon name="building-office" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400">
                    {{ __('Create a corporation first to manage documents and minutes.') }}
                </flux:text>
            </div>
        @endif
    </div>
</div>
