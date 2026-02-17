<?php

use App\Models\JobListing;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Job Listings')]
class extends Component {
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $qrCodeSvg = '';

    public string $qrCodeUrl = '';

    public bool $showQrModal = false;

    public function boot(): void
    {
        if (! auth()->user()?->can('hiring.view')) {
            abort(403);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function listings()
    {
        return JobListing::query()
            ->withCount('applicants')
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('title', 'like', "%{$this->search}%")
                        ->orWhere('location', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->latest()
            ->paginate(15);
    }

    public function activate(int $listingId): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $listing = JobListing::findOrFail($listingId);
        $listing->update([
            'status' => 'active',
            'published_at' => $listing->published_at ?? now(),
        ]);
    }

    public function close(int $listingId): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $listing = JobListing::findOrFail($listingId);
        $listing->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }

    public function deleteListing(int $listingId): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $listing = JobListing::findOrFail($listingId);

        if ($listing->applicants()->count() > 0) {
            session()->flash('error', __('Cannot delete a listing that has applicants.'));

            return;
        }

        $listing->delete();
        session()->flash('success', __('Job listing deleted.'));
    }

    #[On('listing-saved')]
    public function refreshListings(): void
    {
        unset($this->listings);
    }

    public function showQrCode(int $listingId): void
    {
        $listing = JobListing::findOrFail($listingId);

        $this->qrCodeUrl = url("/careers/qr/{$listing->qr_code_token}");

        $renderer = new ImageRenderer(
            new RendererStyle(300),
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);
        $this->qrCodeSvg = $writer->writeString($this->qrCodeUrl);
        $this->showQrModal = true;
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ __('Job Listings') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ __('Manage open positions and track applicants.') }}</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:button variant="ghost" icon="arrow-left" :href="route('hiring.index')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:button>
                @can('hiring.manage')
                    <flux:button variant="primary" icon="plus" wire:click="$dispatch('open-listing-form')">
                        {{ __('Create Listing') }}
                    </flux:button>
                @endcan
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">{{ session('success') }}</flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">{{ session('error') }}</flux:callout>
        @endif

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('Search by title or location...') }}"
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="statusFilter">
                    <option value="all">{{ __('All Statuses') }}</option>
                    @foreach (JobListing::STATUSES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->listings->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Title') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Positions') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Applicants') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Pay Range') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Published') }}</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->listings as $listing)
                            <tr wire:key="listing-{{ $listing->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-medium">{{ $listing->title }}</flux:text>
                                    @if ($listing->location)
                                        <flux:text class="text-sm text-zinc-500">{{ $listing->location }}</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($listing->status === 'draft')
                                        <flux:badge size="sm" color="zinc">{{ __('Draft') }}</flux:badge>
                                    @elseif ($listing->status === 'active')
                                        <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                    @elseif ($listing->status === 'closed')
                                        <flux:badge size="sm" color="red">{{ __('Closed') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $listing->positions_filled }}/{{ $listing->positions_available }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $listing->applicants_count }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $listing->getPayRangeDisplay() }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $listing->published_at?->format('M d, Y') ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            @can('hiring.manage')
                                                <flux:menu.item icon="pencil" wire:click="$dispatch('open-listing-form', { listingId: {{ $listing->id }} })">
                                                    {{ __('Edit') }}
                                                </flux:menu.item>
                                            @endcan
                                            @if ($listing->status === 'draft' || $listing->status === 'closed')
                                                @can('hiring.manage')
                                                    <flux:menu.item icon="play" wire:click="activate({{ $listing->id }})">
                                                        {{ __('Activate') }}
                                                    </flux:menu.item>
                                                @endcan
                                            @endif
                                            @if ($listing->status === 'active')
                                                @can('hiring.manage')
                                                    <flux:menu.item icon="x-circle" wire:click="close({{ $listing->id }})">
                                                        {{ __('Close') }}
                                                    </flux:menu.item>
                                                @endcan
                                            @endif
                                            @if ($listing->isActive())
                                                <flux:menu.item icon="qr-code" wire:click="showQrCode({{ $listing->id }})">
                                                    {{ __('Show QR Code') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="link" wire:click="$dispatch('copy-qr-url', { token: '{{ $listing->qr_code_token }}' })">
                                                    {{ __('Copy QR URL') }}
                                                </flux:menu.item>
                                            @endif
                                            @can('hiring.manage')
                                                <flux:menu.item icon="trash" variant="danger" wire:click="deleteListing({{ $listing->id }})" wire:confirm="{{ __('Are you sure? This cannot be undone.') }}">
                                                    {{ __('Delete') }}
                                                </flux:menu.item>
                                            @endcan
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->listings->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="briefcase" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No job listings found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $statusFilter !== 'all')
                        {{ __('Try adjusting your search or filter criteria.') }}
                    @else
                        {{ __('Create your first job listing to start hiring.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>

    <livewire:hiring.listing-form />

    <flux:modal wire:model="showQrModal" class="max-w-sm">
        <div class="space-y-4" x-data="{
            copyUrl() { navigator.clipboard.writeText(@js($qrCodeUrl)) },
            downloadSvg() {
                const el = this.$refs.qrContainer?.querySelector('svg');
                if (el) {
                    const blob = new Blob([el.outerHTML], { type: 'image/svg+xml' });
                    const u = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = u;
                    a.download = 'qr-code.svg';
                    a.click();
                    URL.revokeObjectURL(u);
                }
            }
        }">
            <flux:heading size="lg">{{ __('QR Code') }}</flux:heading>

            <div class="flex justify-center" x-ref="qrContainer">
                {!! $qrCodeSvg !!}
            </div>

            <flux:text class="text-center text-sm text-zinc-500 break-all">{{ $qrCodeUrl }}</flux:text>

            <div class="flex gap-2">
                <flux:button
                    variant="primary"
                    icon="clipboard"
                    class="flex-1"
                    x-on:click="copyUrl()"
                >
                    {{ __('Copy URL') }}
                </flux:button>
                <flux:button
                    variant="ghost"
                    icon="arrow-down-tray"
                    class="flex-1"
                    x-on:click="downloadSvg()"
                >
                    {{ __('Download SVG') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    @script
    <script>
        $wire.on('copy-qr-url', ({ token }) => {
            const url = `${window.location.origin}/careers/qr/${token}`;
            navigator.clipboard.writeText(url).then(() => {
                // Livewire dispatch for user feedback could go here
            });
        });
    </script>
    @endscript
</div>
