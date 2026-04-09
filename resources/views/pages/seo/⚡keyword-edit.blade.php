<?php

use App\Actions\Seo\AssignKeywordAction;
use App\Models\Keyword;
use App\Models\KeywordPageAssignment;
use App\Models\Service;
use App\Models\ServiceArea;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Edit Keyword')]
class extends Component {
    public Keyword $keyword;

    #[Validate('required|string|min:2|max:255')]
    public string $keywordValue = '';

    #[Validate('required|in:researched,targeted,ranking,won,dropped')]
    public string $status = 'researched';

    #[Validate('required|in:keyword_planner,search_console,manual,ai_suggested')]
    public string $source = 'manual';

    #[Validate('nullable|in:informational,commercial,transactional,navigational,local')]
    public ?string $intent = null;

    #[Validate('nullable|integer|min:0')]
    public ?int $searchVolume = null;

    #[Validate('nullable|in:low,medium,high')]
    public ?string $competition = null;

    #[Validate('nullable|numeric|min:0')]
    public ?float $cpc = null;

    #[Validate('nullable|string|max:1000')]
    public ?string $defaultSentence = null;

    #[Validate('nullable|string|max:5000')]
    public ?string $notes = null;

    // Add-assignment form
    public ?int $newServiceId = null;

    public ?int $newServiceAreaId = null;

    public string $newRole = 'secondary';

    public ?string $newCustomSentence = null;

    public function mount(Keyword $keyword): void
    {
        $this->keyword = $keyword;
        $this->keywordValue = $keyword->keyword;
        $this->status = $keyword->status;
        $this->source = $keyword->source;
        $this->intent = $keyword->intent;
        $this->searchVolume = $keyword->search_volume;
        $this->competition = $keyword->competition;
        $this->cpc = $keyword->cpc !== null ? (float) $keyword->cpc : null;
        $this->defaultSentence = $keyword->default_sentence;
        $this->notes = $keyword->notes;
    }

    public function save(): void
    {
        $this->validate();

        $this->keyword->update([
            'keyword' => $this->keywordValue,
            'status' => $this->status,
            'source' => $this->source,
            'intent' => $this->intent ?: null,
            'search_volume' => $this->searchVolume,
            'competition' => $this->competition ?: null,
            'cpc' => $this->cpc,
            'default_sentence' => $this->defaultSentence ?: null,
            'notes' => $this->notes ?: null,
        ]);

        \Flux\Flux::toast(heading: __('Saved'), text: __('Keyword updated.'), variant: 'success');
    }

    public function addAssignment(AssignKeywordAction $action): void
    {
        $this->validate([
            'newServiceId' => 'nullable|exists:services,id',
            'newServiceAreaId' => 'nullable|exists:service_areas,id',
            'newRole' => 'required|in:primary,secondary,supporting',
            'newCustomSentence' => 'nullable|string|max:1000',
        ]);

        if ($this->newServiceId === null && $this->newServiceAreaId === null) {
            $this->addError('newServiceId', __('Pick at least a service or an area.'));

            return;
        }

        $action->execute(
            keyword: $this->keyword->fresh(),
            service: $this->newServiceId ? Service::find($this->newServiceId) : null,
            serviceArea: $this->newServiceAreaId ? ServiceArea::find($this->newServiceAreaId) : null,
            role: $this->newRole,
            customSentence: $this->newCustomSentence ?: null,
        );

        $this->reset(['newServiceId', 'newServiceAreaId', 'newRole', 'newCustomSentence']);
        $this->newRole = 'secondary';
        unset($this->assignments);

        \Flux\Flux::toast(heading: __('Assigned'), text: __('Keyword assigned to page.'), variant: 'success');
    }

    public function removeAssignment(int $assignmentId): void
    {
        KeywordPageAssignment::query()->whereKey($assignmentId)->where('keyword_id', $this->keyword->id)->delete();
        unset($this->assignments);
    }

    public function changeRole(int $assignmentId, string $role): void
    {
        $assignment = KeywordPageAssignment::query()->whereKey($assignmentId)->where('keyword_id', $this->keyword->id)->firstOrFail();
        app(AssignKeywordAction::class)->execute(
            keyword: $this->keyword->fresh(),
            service: $assignment->service,
            serviceArea: $assignment->serviceArea,
            role: $role,
            customSentence: $assignment->custom_sentence,
        );
        unset($this->assignments);
    }

    #[Computed]
    public function assignments(): \Illuminate\Support\Collection
    {
        return $this->keyword->assignments()->with(['service', 'serviceArea'])->latest()->get();
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

<section class="w-full max-w-4xl space-y-8">
    <div>
        <flux:button size="sm" variant="ghost" href="{{ route('seo.keywords.index') }}" icon="arrow-left" wire:navigate>
            {{ __('Back to keywords') }}
        </flux:button>
    </div>

    <div>
        <flux:heading size="xl">{{ $keyword->keyword }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500">{{ __('Edit keyword metadata and manage page assignments.') }}</flux:text>
    </div>

    {{-- Edit form --}}
    <form wire:submit="save" class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="md" class="mb-4">{{ __('Keyword details') }}</flux:heading>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:field class="md:col-span-2">
                <flux:label>{{ __('Keyword') }}</flux:label>
                <flux:input wire:model="keywordValue" />
                <flux:error name="keywordValue" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Status') }}</flux:label>
                <flux:select wire:model="status">
                    @foreach(\App\Models\Keyword::STATUSES as $option)
                        <flux:select.option :value="$option">{{ Str::title($option) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Source') }}</flux:label>
                <flux:select wire:model="source">
                    @foreach(\App\Models\Keyword::SOURCES as $option)
                        <flux:select.option :value="$option">{{ Str::title(str_replace('_', ' ', $option)) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Intent') }}</flux:label>
                <flux:select wire:model="intent" placeholder="{{ __('— Unset —') }}">
                    <flux:select.option value="">{{ __('— Unset —') }}</flux:select.option>
                    @foreach(\App\Models\Keyword::INTENTS as $option)
                        <flux:select.option :value="$option">{{ Str::title($option) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Competition') }}</flux:label>
                <flux:select wire:model="competition" placeholder="{{ __('— Unset —') }}">
                    <flux:select.option value="">{{ __('— Unset —') }}</flux:select.option>
                    @foreach(\App\Models\Keyword::COMPETITIONS as $option)
                        <flux:select.option :value="$option">{{ Str::title($option) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Search volume / month') }}</flux:label>
                <flux:input type="number" wire:model="searchVolume" min="0" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('CPC ($)') }}</flux:label>
                <flux:input type="number" step="0.01" wire:model="cpc" min="0" />
            </flux:field>

            <flux:field class="md:col-span-2">
                <flux:label>{{ __('Default sentence (used as natural-language injection on pages)') }}</flux:label>
                <flux:textarea wire:model="defaultSentence" rows="2" placeholder="{{ __('When homeowners search for...') }}" />
                <flux:description>
                    {{ __('Optional. Per-assignment custom sentence overrides this. If both are blank, an auto-generated sentence is used.') }}
                </flux:description>
            </flux:field>

            <flux:field class="md:col-span-2">
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="notes" rows="2" />
            </flux:field>
        </div>

        <div class="mt-4 flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
        </div>
    </form>

    {{-- Assignments --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="md" class="mb-4">{{ __('Page assignments') }}</flux:heading>

        @if($this->assignments->isEmpty())
            <flux:text class="text-zinc-500">{{ __('Not assigned to any page yet.') }}</flux:text>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-200 text-left text-xs uppercase text-zinc-600 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="px-2 py-2">{{ __('Service') }}</th>
                            <th class="px-2 py-2">{{ __('Area') }}</th>
                            <th class="px-2 py-2">{{ __('Specificity') }}</th>
                            <th class="px-2 py-2">{{ __('Role') }}</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach($this->assignments as $assignment)
                            <tr wire:key="assign-{{ $assignment->id }}">
                                <td class="px-2 py-2">{{ $assignment->service?->title ?? '—' }}</td>
                                <td class="px-2 py-2">{{ $assignment->serviceArea?->city ?? '—' }}</td>
                                <td class="px-2 py-2">
                                    @if($assignment->specificity === 3)
                                        <flux:badge size="sm" color="green">{{ __('Combo') }}</flux:badge>
                                    @elseif($assignment->specificity === 2)
                                        <flux:badge size="sm" color="blue">{{ __('Service') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="purple">{{ __('Area') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-2 py-2">
                                    <flux:select size="sm" wire:change="changeRole({{ $assignment->id }}, $event.target.value)">
                                        @foreach(\App\Models\KeywordPageAssignment::ROLES as $role)
                                            <flux:select.option :value="$role" :selected="$role === $assignment->role">{{ Str::title($role) }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <flux:button size="sm" variant="danger" wire:click="removeAssignment({{ $assignment->id }})" icon="trash">
                                        {{ __('Remove') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="mt-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:heading size="sm" class="mb-3">{{ __('Add assignment') }}</flux:heading>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <flux:select wire:model="newServiceId" placeholder="{{ __('Service') }}">
                    <flux:select.option value="">{{ __('— Any service —') }}</flux:select.option>
                    @foreach($this->services as $service)
                        <flux:select.option :value="$service->id">{{ $service->title }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="newServiceAreaId" placeholder="{{ __('Area') }}">
                    <flux:select.option value="">{{ __('— Any area —') }}</flux:select.option>
                    @foreach($this->serviceAreas as $area)
                        <flux:select.option :value="$area->id">{{ $area->city }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="newRole">
                    @foreach(\App\Models\KeywordPageAssignment::ROLES as $role)
                        <flux:select.option :value="$role">{{ Str::title($role) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:error name="newServiceId" class="mt-2" />

            <flux:field class="mt-3">
                <flux:label>{{ __('Custom sentence (overrides default for this page)') }}</flux:label>
                <flux:textarea wire:model="newCustomSentence" rows="2" />
            </flux:field>

            <div class="mt-3 flex justify-end">
                <flux:button wire:click="addAssignment" variant="primary" icon="plus">
                    {{ __('Add assignment') }}
                </flux:button>
            </div>
        </div>
    </div>
</section>
