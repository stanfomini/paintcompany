<?php

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $instructions = '';

    public function boot(): void
    {
        if (! auth()->user()?->can('ai.estimate_builder')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $settings = CompanySetting::getInstance();
        $this->instructions = $settings->estimate_builder_instructions ?? '';
    }

    public function save(): void
    {
        $validated = $this->validate([
            'instructions' => ['nullable', 'string', 'max:10000'],
        ]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'estimate_builder_instructions' => $validated['instructions'] ?: null,
        ]);

        CompanySetting::clearInstanceCache();

        $this->dispatch('notify', message: 'AI Estimator settings saved.');
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('AI Estimator')" :subheading="__('Configure AI estimate builder behavior.')">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:callout>
                <flux:callout.heading>{{ __('How It Works') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('The AI estimate builder uses these instructions as background context when generating estimates. Provide details about your business so the AI produces accurate, relevant estimates.') }}
                </flux:callout.text>
            </flux:callout>

            <flux:field>
                <flux:label>{{ __('Background Instructions') }}</flux:label>
                <flux:textarea
                    wire:model="instructions"
                    rows="14"
                    placeholder="{{ __("Example:\n- We primarily do residential interior/exterior painting in the Denver metro area\n- Default paint brand is Sherwin-Williams Duration for interiors, SuperPaint for exteriors\n- Always include 2 coats unless the customer specifies otherwise\n- For new construction, assume new drywall substrate\n- Our minimum job size is \$1,500\n- Always include ceiling painting unless told otherwise\n- Standard trim paint is semi-gloss, walls are eggshell/matte") }}"
                />
                <flux:description>
                    {{ __('Tell the AI about your typical job types, preferred brands, default assumptions, and pricing rules. This context helps generate more accurate estimates.') }}
                </flux:description>
            </flux:field>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled">
                    {{ __('Save') }}
                </flux:button>
                <span wire:loading wire:target="save" class="text-sm text-zinc-500">{{ __('Saving...') }}</span>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
