<?php

use App\Actions\Seo\ImportKeywordPasteAction;
use App\Models\Keyword;
use App\Models\Service;
use App\Models\ServiceArea;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Import Keywords')]
class extends Component {
    #[Validate('required|string|min:2|max:50000')]
    public string $pasteBody = '';

    public string $defaultStatus = 'researched';

    public string $defaultSource = 'manual';

    public ?int $serviceId = null;

    public ?int $serviceAreaId = null;

    public string $defaultRole = 'secondary';

    public ?array $importResult = null;

    public function import(ImportKeywordPasteAction $action): void
    {
        $this->validate();

        $service = $this->serviceId ? Service::find($this->serviceId) : null;
        $area = $this->serviceAreaId ? ServiceArea::find($this->serviceAreaId) : null;

        $result = $action->execute(
            pasteBody: $this->pasteBody,
            defaultStatus: $this->defaultStatus,
            defaultSource: $this->defaultSource,
            service: $service,
            serviceArea: $area,
            defaultRole: $this->defaultRole,
        );

        $this->importResult = [
            'created' => $result['created'],
            'existing' => $result['existing'],
            'assigned' => $result['assigned'],
            'skipped' => $result['skipped'],
        ];

        $this->reset('pasteBody');

        \Flux\Flux::toast(
            heading: __('Import complete'),
            text: __(':created new, :existing existed, :assigned assigned to page', $this->importResult),
            variant: 'success',
        );
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
}; ?>

<section class="w-full max-w-3xl">
    <div class="mb-6">
        <flux:button size="sm" variant="ghost" href="{{ route('seo.keywords.index') }}" icon="arrow-left" wire:navigate>
            {{ __('Back to keywords') }}
        </flux:button>
    </div>

    <div class="mb-6">
        <flux:heading size="xl">{{ __('Import Keywords') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500">
            {{ __('Paste a list of keywords (one per line). Each line becomes a Keyword. Choose default attributes and an optional page target — every keyword will be assigned to that page with the chosen role.') }}
        </flux:text>
    </div>

    <form wire:submit="import" class="space-y-6">
        <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:field>
                <flux:label>{{ __('Keyword list') }}</flux:label>
                <flux:textarea wire:model="pasteBody" rows="12" placeholder="interior painters denver&#10;denver house painting&#10;best painters denver co" />
                <flux:description>{{ __('One keyword per line. Duplicates and lines longer than 255 characters are ignored.') }}</flux:description>
                <flux:error name="pasteBody" />
            </flux:field>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="md" class="mb-4">{{ __('Defaults applied to every imported keyword') }}</flux:heading>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Status') }}</flux:label>
                    <flux:select wire:model="defaultStatus">
                        @foreach(Keyword::STATUSES as $status)
                            <flux:select.option :value="$status">{{ Str::title($status) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Source') }}</flux:label>
                    <flux:select wire:model="defaultSource">
                        @foreach(Keyword::SOURCES as $source)
                            <flux:select.option :value="$source">{{ Str::title(str_replace('_', ' ', $source)) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Service') }}</flux:label>
                    <flux:select wire:model="serviceId" placeholder="{{ __('No service') }}">
                        <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                        @foreach($this->services as $service)
                            <flux:select.option :value="$service->id">{{ $service->title }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Service Area') }}</flux:label>
                    <flux:select wire:model="serviceAreaId" placeholder="{{ __('No area') }}">
                        <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                        @foreach($this->serviceAreas as $area)
                            <flux:select.option :value="$area->id">{{ $area->city }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('Role') }}</flux:label>
                    <flux:select wire:model="defaultRole">
                        <flux:select.option value="primary">{{ __('Primary — main target keyword for the page') }}</flux:select.option>
                        <flux:select.option value="secondary">{{ __('Secondary — supporting variant') }}</flux:select.option>
                        <flux:select.option value="supporting">{{ __('Supporting — long-tail / question variant') }}</flux:select.option>
                    </flux:select>
                    <flux:description>
                        {{ __('Only applied when at least one of Service or Service Area is set above. Setting "primary" will demote any existing primary on the same page to secondary.') }}
                    </flux:description>
                </flux:field>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <flux:button type="submit" variant="primary" icon="arrow-up-tray">
                {{ __('Import Keywords') }}
            </flux:button>
        </div>
    </form>

    @if($importResult !== null)
        <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-800 dark:bg-emerald-900/20">
            <flux:heading size="md" class="text-emerald-900 dark:text-emerald-200">{{ __('Last import result') }}</flux:heading>
            <ul class="mt-2 space-y-1 text-sm text-emerald-800 dark:text-emerald-300">
                <li>{{ __('Created') }}: <strong>{{ $importResult['created'] }}</strong></li>
                <li>{{ __('Already existed') }}: <strong>{{ $importResult['existing'] }}</strong></li>
                <li>{{ __('Assigned to page') }}: <strong>{{ $importResult['assigned'] }}</strong></li>
                <li>{{ __('Skipped') }}: <strong>{{ $importResult['skipped'] }}</strong></li>
            </ul>
        </div>
    @endif
</section>
