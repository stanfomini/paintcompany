<?php

use App\Models\JobListing;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public bool $show = false;

    public ?int $listingId = null;

    public string $title = '';

    public string $description = '';

    public string $requirements = '';

    public string $location = '';

    public string $pay_range_min = '';

    public string $pay_range_max = '';

    public string $employment_type = 'full_time';

    public int $positions_available = 1;

    #[On('open-listing-form')]
    public function open(?int $listingId = null): void
    {
        $this->reset();
        $this->resetValidation();

        if ($listingId) {
            $listing = JobListing::findOrFail($listingId);
            $this->listingId = $listing->id;
            $this->title = $listing->title;
            $this->description = $listing->description ?? '';
            $this->requirements = $listing->requirements ?? '';
            $this->location = $listing->location ?? '';
            $this->pay_range_min = $listing->pay_range_min ? (string) $listing->pay_range_min : '';
            $this->pay_range_max = $listing->pay_range_max ? (string) $listing->pay_range_max : '';
            $this->employment_type = $listing->employment_type ?? 'full_time';
            $this->positions_available = $listing->positions_available ?? 1;
        }

        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
        $this->reset();
        $this->resetValidation();
    }

    public function save(): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'requirements' => ['nullable', 'string', 'max:10000'],
            'location' => ['nullable', 'string', 'max:255'],
            'pay_range_min' => ['nullable', 'numeric', 'min:0'],
            'pay_range_max' => ['nullable', 'numeric', 'min:0'],
            'employment_type' => ['required', 'in:'.implode(',', array_keys(JobListing::EMPLOYMENT_TYPES))],
            'positions_available' => ['required', 'integer', 'min:1'],
        ]);

        $data = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'requirements' => $validated['requirements'] ?: null,
            'location' => $validated['location'] ?: null,
            'pay_range_min' => $validated['pay_range_min'] ?: null,
            'pay_range_max' => $validated['pay_range_max'] ?: null,
            'employment_type' => $validated['employment_type'],
            'positions_available' => $validated['positions_available'],
        ];

        if ($this->listingId) {
            $listing = JobListing::findOrFail($this->listingId);
            $listing->update($data);
        } else {
            $data['status'] = 'draft';
            $data['created_by'] = auth()->id();
            JobListing::create($data);
        }

        $this->show = false;
        $this->dispatch('listing-saved');
        session()->flash('success', $this->listingId ? __('Job listing updated.') : __('Job listing created.'));
    }
}; ?>

<div>
    <flux:modal wire:model="show" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-1">
                {{ $listingId ? __('Edit Job Listing') : __('Create Job Listing') }}
            </flux:heading>
            <flux:text class="mb-6">
                {{ $listingId ? __('Update the job listing details.') : __('Create a new job listing to attract applicants.') }}
            </flux:text>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Title') }}</flux:label>
                    <flux:input wire:model="title" placeholder="Lead Painter" required />
                    <flux:error name="title" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Description') }}</flux:label>
                    <flux:textarea wire:model="description" rows="4" placeholder="{{ __('Describe the role and responsibilities...') }}" />
                    <flux:error name="description" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Requirements') }}</flux:label>
                    <flux:textarea wire:model="requirements" rows="3" placeholder="{{ __('List qualifications and experience needed...') }}" />
                    <flux:error name="requirements" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Location') }}</flux:label>
                    <flux:input wire:model="location" placeholder="Dallas, TX" />
                    <flux:error name="location" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Pay Min') }}</flux:label>
                        <flux:input wire:model="pay_range_min" type="number" step="0.01" min="0" placeholder="0.00" icon="currency-dollar" />
                        <flux:error name="pay_range_min" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Pay Max') }}</flux:label>
                        <flux:input wire:model="pay_range_max" type="number" step="0.01" min="0" placeholder="0.00" icon="currency-dollar" />
                        <flux:error name="pay_range_max" />
                    </flux:field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Employment Type') }}</flux:label>
                        <flux:select wire:model="employment_type">
                            @foreach (JobListing::EMPLOYMENT_TYPES as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="employment_type" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Positions Available') }}</flux:label>
                        <flux:input wire:model="positions_available" type="number" min="1" required />
                        <flux:error name="positions_available" />
                    </flux:field>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="close" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $listingId ? __('Update Listing') : __('Create Listing') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
