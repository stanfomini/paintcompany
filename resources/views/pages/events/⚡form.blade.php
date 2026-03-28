<?php

use App\Models\LiveEvent;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Services\LiveEventService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Event')]
class extends Component
{
    public ?int $eventId = null;

    public string $title = '';

    public string $slug = '';

    public string $description = '';

    public string $serviceAreaId = '';

    public string $serviceId = '';

    public string $scheduledAt = '';

    public string $accessType = 'public';

    public int $maxViewers = 50;

    public bool $enableRecording = false;

    // Cover image placeholder — file upload to be added later

    public function boot(): void
    {
        if (auth()->user()?->type !== 'internal') {
            abort(403);
        }
    }

    public function mount(?LiveEvent $liveEvent = null): void
    {
        if ($liveEvent?->exists) {
            $this->eventId = $liveEvent->id;
            $this->title = $liveEvent->title;
            $this->slug = $liveEvent->slug ?? '';
            $this->description = $liveEvent->description ?? '';
            $this->serviceAreaId = (string) ($liveEvent->service_area_id ?? '');
            $this->serviceId = (string) ($liveEvent->service_id ?? '');
            $this->scheduledAt = $liveEvent->scheduled_at?->format('Y-m-d\TH:i') ?? '';
            $this->accessType = $liveEvent->access_type ?? 'public';
            $this->maxViewers = $liveEvent->max_viewers ?? 50;
            $this->enableRecording = (bool) $liveEvent->enable_recording;
        }
    }

    #[Computed]
    public function serviceAreas(): \Illuminate\Database\Eloquent\Collection
    {
        return ServiceArea::query()->active()->orderBy('city')->get();
    }

    #[Computed]
    public function services(): \Illuminate\Database\Eloquent\Collection
    {
        return Service::query()
            ->active()
            ->orderBy('title')
            ->get();
    }

    public function updatedTitle(): void
    {
        if (! $this->eventId) {
            $this->slug = Str::slug($this->title);
        }
    }

    public function save(): void
    {
        if (auth()->user()?->type !== 'internal') {
            abort(403);
        }

        $rules = [
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'required|string',
            'serviceAreaId' => 'nullable|string',
            'serviceId' => 'nullable|string',
            'scheduledAt' => ['required', 'date', ...($this->eventId ? [] : ['after:now'])],
            'accessType' => 'required|in:public,registration',
            'maxViewers' => 'required|integer|min:1|max:300',
            'enableRecording' => 'boolean',
        ];

        $this->validate($rules);

        $data = [
            'title' => $this->title,
            'description' => $this->description,
            'service_area_id' => $this->serviceAreaId ?: null,
            'service_id' => $this->serviceId ?: null,
            'scheduled_at' => $this->scheduledAt,
            'access_type' => $this->accessType,
            'max_viewers' => $this->maxViewers,
            'enable_recording' => $this->enableRecording,
        ];

        if ($this->slug) {
            $data['slug'] = Str::slug($this->slug);
        }

        if ($this->eventId) {
            $event = LiveEvent::findOrFail($this->eventId);

            if (! $this->slug) {
                $data['slug'] = Str::slug($this->title);
            }

            $event->update($data);

            session()->flash('success', __('Event updated successfully.'));
        } else {
            $data['host_user_id'] = auth()->id();

            app(LiveEventService::class)->createEvent($data);

            session()->flash('success', __('Event created successfully.'));
        }

        $this->redirect(route('events.index'));
    }
}; ?>

<div>
    <div class="mb-6">
        <flux:button variant="ghost" icon="arrow-left" :href="route('events.index')" class="mb-4">
            {{ __('Back to Events') }}
        </flux:button>

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">
                    {{ $eventId ? __('Edit Event') : __('Create Event') }}
                </flux:heading>
                <flux:text class="mt-1">
                    {{ $eventId ? __('Update the event details below.') : __('Set up a new live event for your audience.') }}
                </flux:text>
            </div>
            @if ($eventId && $slug)
                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="ghost" icon="eye" href="{{ route('live.show', $slug) }}" target="_blank">
                        {{ __('Public Page') }}
                    </flux:button>
                    <flux:button size="sm" variant="primary" icon="signal" href="{{ route('events.manage', $slug) }}">
                        {{ __('Control Room') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </div>

    <form wire:submit="save" class="max-w-2xl space-y-6">
        {{-- Title --}}
        <flux:input
            wire:model.blur="title"
            :label="__('Title')"
            :placeholder="__('e.g., Spring Color Trends Webinar')"
            required
        />
        @error('title') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        {{-- Slug --}}
        <flux:input
            wire:model="slug"
            :label="__('Slug')"
            :placeholder="__('Auto-generated from title')"
            :description="__('URL-friendly identifier. Leave blank to auto-generate.')"
        />

        {{-- Description --}}
        <flux:textarea
            wire:model="description"
            :label="__('Description')"
            :placeholder="__('Describe the event, topics covered, and what viewers will learn...')"
            rows="4"
            required
        />
        @error('description') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        {{-- Service Area --}}
        <flux:select wire:model="serviceAreaId" :label="__('Service Area')" :placeholder="__('None')">
            <flux:select.option value="">{{ __('None') }}</flux:select.option>
            @foreach ($this->serviceAreas as $area)
                <flux:select.option value="{{ $area->id }}">{{ $area->city }}, {{ $area->state }}</flux:select.option>
            @endforeach
        </flux:select>

        {{-- Service --}}
        <flux:select wire:model="serviceId" :label="__('Service')" :placeholder="__('None')">
            <flux:select.option value="">{{ __('None') }}</flux:select.option>
            @foreach ($this->services as $service)
                <flux:select.option value="{{ $service->id }}">{{ $service->title }}</flux:select.option>
            @endforeach
        </flux:select>

        {{-- Scheduled Date/Time --}}
        <div>
            <flux:input
                wire:model="scheduledAt"
                type="datetime-local"
                :label="__('Scheduled Date & Time')"
                required
            />
            @error('scheduledAt') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
            @if (! $eventId)
                <flux:text class="mt-1 text-xs text-zinc-500">{{ __('Must be a future date and time.') }}</flux:text>
            @endif
        </div>

        {{-- Access Type --}}
        <fieldset>
            <flux:label>{{ __('Access Type') }}</flux:label>
            <flux:text class="mb-2 text-sm text-zinc-500">{{ __('Who can join the event?') }}</flux:text>
            <flux:radio.group wire:model="accessType">
                <flux:radio value="public" label="{{ __('Public') }}" description="{{ __('Anyone with the link can watch') }}" />
                <flux:radio value="registration" label="{{ __('Registration Required') }}" description="{{ __('Viewers must register before joining') }}" />
            </flux:radio.group>
        </fieldset>

        {{-- Max Viewers --}}
        <flux:input
            wire:model="maxViewers"
            type="number"
            :label="__('Max Viewers')"
            min="1"
            max="300"
            :description="__('Maximum number of concurrent viewers (1-300)')"
        />
        @error('maxViewers') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        {{-- Enable Recording --}}
        <flux:switch
            wire:model="enableRecording"
            :label="__('Enable Recording')"
            :description="__('Record the event for later viewing. (Recording coming soon)')"
        />

        {{-- Cover Image placeholder --}}
        {{-- TODO: Add cover_image file upload --}}

        {{-- Actions --}}
        <div class="flex items-center gap-3 pt-4">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">
                    {{ $eventId ? __('Update Event') : __('Create Event') }}
                </span>
                <span wire:loading wire:target="save">
                    {{ __('Saving...') }}
                </span>
            </flux:button>

            <flux:button variant="ghost" :href="route('events.index')">
                {{ __('Cancel') }}
            </flux:button>
        </div>
    </form>
</div>
