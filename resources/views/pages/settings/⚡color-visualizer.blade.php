<?php

use App\Models\CompanySetting;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.company')) {
            abort(403);
        }
    }

    public string $visualizer_agent_prompt = '';
    public string $visualizer_image_prompt = '';

    public function mount(): void
    {
        $settings = CompanySetting::getInstance();

        $this->visualizer_agent_prompt = $settings->visualizer_agent_prompt ?? '';
        $this->visualizer_image_prompt = $settings->visualizer_image_prompt ?? '';
    }

    public function save(): void
    {
        $validated = $this->validate([
            'visualizer_agent_prompt' => ['nullable', 'string', 'max:5000'],
            'visualizer_image_prompt' => ['nullable', 'string', 'max:5000'],
        ]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'visualizer_agent_prompt' => $validated['visualizer_agent_prompt'] ?: null,
            'visualizer_image_prompt' => $validated['visualizer_image_prompt'] ?: null,
        ]);

        CompanySetting::clearInstanceCache();

        $this->dispatch('notify', message: 'Color visualizer settings saved.');
    }

    public function resetAgentPrompt(): void
    {
        $this->visualizer_agent_prompt = $this->getDefaultAgentPrompt();
    }

    public function resetImagePrompt(): void
    {
        $this->visualizer_image_prompt = $this->getDefaultImagePrompt();
    }

    public static function getDefaultAgentPrompt(): string
    {
        return <<<'PROMPT'
You are a paint color expert for a professional painting company. Your job is to take a customer's description of desired paint colors and resolve each one to an exact hex color code using the PaintColorLookupTool.

When a customer says something like "Benjamin Moore White Dove on the walls and Sherwin-Williams Iron Ore on the trim", you must:

1. Identify each color mentioned and which surface it applies to (walls, trim, ceiling, doors, cabinets, shutters, siding, fascia, etc.)
2. Use the PaintColorLookupTool to look up each color by name or code
3. Return your response in this EXACT format, one line per color:

- Surface: [surface] | Color: [Brand] [Color Name] ([Code]) | Hex: [#HEXVALUE]

Rules:
- Always use the exact hex values from the database — never guess or approximate hex values
- If the customer gives a generic description like "light blue" without a brand, suggest the closest matching paint color from the database and note it as a suggestion
- If a color cannot be found, say so clearly and suggest the closest match
- Common surface mappings: "body" = exterior walls/siding, "accent" = shutters/doors, "trim" = baseboards/crown/window frames
PROMPT;
    }

    public static function getDefaultImagePrompt(): string
    {
        return <<<'PROMPT'
You are editing the attached photograph. Do NOT generate a new image. Modify this exact photo by repainting only the specified surfaces with the exact colors listed below.

{color_instructions}

RULES FOR EDITING:
- This is a PHOTO EDIT, not image generation. The output must be the same photograph with only paint colors changed.
- Preserve EVERYTHING else exactly: the building structure, architecture, windows, doors, roof, landscaping, driveway, sky, lighting, shadows, reflections, camera angle, perspective, and all surrounding elements.
- Apply the new paint colors realistically — match how real paint looks on the surface material (flat on drywall, slight sheen on trim, satin on doors, etc.)
- Maintain realistic lighting interactions — shadows should tint with the new color, highlights should reflect appropriately.
- Keep hard edges between surfaces clean (e.g., where wall meets trim, where siding meets fascia).
- Do NOT change, add, remove, or rearrange any objects, furniture, plants, or architectural features.
- The final image must look like a real photograph of this exact same building/room, just freshly painted with the specified colors.
PROMPT;
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Color Visualizer')" :subheading="__('Configure AI prompts for the color visualization tool.')">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            {{-- Current Model Info --}}
            <flux:callout>
                <flux:callout.heading>{{ __('Image Model') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Currently using') }} <strong>{{ config('services.color_visualizer.image_model') }}</strong>.
                    {{ __('Change via COLOR_VISUALIZER_IMAGE_MODEL in .env.') }}
                </flux:callout.text>
            </flux:callout>

            {{-- Agent Prompt --}}
            <flux:field>
                <div class="flex items-center justify-between">
                    <flux:label>{{ __('Color Resolution Agent Prompt') }}</flux:label>
                    <flux:button variant="subtle" size="sm" wire:click="resetAgentPrompt" type="button">
                        {{ __('Reset to Default') }}
                    </flux:button>
                </div>
                <flux:textarea wire:model="visualizer_agent_prompt" rows="12" />
                <flux:description>{{ __('Instructions for the AI agent that resolves paint color names to hex values. This agent uses the PaintColorLookupTool to search your color database.') }}</flux:description>
            </flux:field>

            {{-- Image Prompt Template --}}
            <flux:field>
                <div class="flex items-center justify-between">
                    <flux:label>{{ __('Image Editing Prompt Template') }}</flux:label>
                    <flux:button variant="subtle" size="sm" wire:click="resetImagePrompt" type="button">
                        {{ __('Reset to Default') }}
                    </flux:button>
                </div>
                <flux:textarea wire:model="visualizer_image_prompt" rows="12" />
                <flux:description>
                    {{ __('Prompt sent to the image generation AI along with the uploaded photo. Use') }}
                    <code class="rounded bg-zinc-100 px-1 text-xs dark:bg-zinc-800">{color_instructions}</code>
                    {{ __('where you want the resolved color details inserted (e.g., "Paint the walls with Benjamin Moore White Dove (exact color: #F3EFE0)").') }}
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
