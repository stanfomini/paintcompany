<?php

use App\Models\StudioTemplate;
use App\Models\VideoProject;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $show = false;
    public ?int $selectedDealId = null;

    private const CATEGORY_ICONS = [
        'before_after' => 'arrow-path',
        'walkthrough' => 'video-camera',
        'testimonial' => 'chat-bubble-left-right',
        'estimate_tour' => 'clipboard-document-list',
        'team_highlight' => 'user-group',
        'promo' => 'megaphone',
    ];

    #[Computed]
    public function templates(): \Illuminate\Database\Eloquent\Collection
    {
        return StudioTemplate::active()->orderBy('sort_order')->get();
    }

    public function useTemplate(int $templateId): void
    {
        $template = StudioTemplate::findOrFail($templateId);

        $project = VideoProject::create([
            'user_id' => auth()->id(),
            'title' => $template->name.' Video',
            'status' => 'draft',
            'creation_mode' => 'template',
            'template_id' => $template->id,
            'aspect_ratio' => $template->structure_json['aspect_ratio'] ?? '9:16',
            'metadata' => $this->selectedDealId ? ['deal_id' => $this->selectedDealId] : [],
        ]);

        foreach ($template->structure_json['segments'] ?? [] as $index => $segment) {
            $project->scenes()->create([
                'sort_order' => $index,
                'name' => $segment['label'] ?? 'Scene '.($index + 1),
                'layout' => 'solo',
                'duration_ms' => $segment['duration_ms'] ?? null,
            ]);
        }

        $this->redirect(route('studio.create', $project), navigate: true);
    }

    public function getCategoryIcon(string $category): string
    {
        return self::CATEGORY_ICONS[$category] ?? 'squares-2x2';
    }
};
?>

<div>
    <flux:modal wire:model="show" class="max-w-2xl">
        <div class="p-4">
            <flux:heading size="lg" class="mb-4">{{ __('Choose a Template') }}</flux:heading>

            @if($this->templates->isNotEmpty())
                <div class="grid grid-cols-2 gap-3">
                    @foreach($this->templates as $template)
                        <button
                            wire:key="template-{{ $template->id }}"
                            wire:click="useTemplate({{ $template->id }})"
                            type="button"
                            class="text-left rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 hover:border-blue-500 dark:hover:border-blue-400 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <div class="flex items-start gap-3">
                                <div class="shrink-0 mt-0.5">
                                    <flux:icon
                                        :name="$this->getCategoryIcon($template->category)"
                                        class="h-6 w-6 text-zinc-500 dark:text-zinc-400"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ $template->name }}</p>
                                    @if($template->description)
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1 line-clamp-2">{{ $template->description }}</p>
                                    @endif
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            @else
                <div class="flex flex-col items-center justify-center py-12 text-zinc-500 dark:text-zinc-400">
                    <flux:icon name="squares-2x2" class="h-10 w-10 mb-2" />
                    <p>{{ __('No templates available.') }}</p>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
