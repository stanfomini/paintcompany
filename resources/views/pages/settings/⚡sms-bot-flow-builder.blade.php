<?php

use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.company')) {
            abort(403);
        }
    }

    public ?int $flowId = null;
    public string $flowName = '';
    public string $flowStatus = 'draft';
    public string $triggerType = 'new_lead';
    public string $triggerKeyword = '';
    public string $completionAction = 'none';
    public int $timeoutMinutes = 10080;
    public int $delayAfterLead = 5;
    public bool $businessHoursOnly = false;
    public array $steps = [];

    public ?int $expandedStep = null;

    public function mount(?int $flow = null): void
    {
        if ($flow) {
            $flowModel = SmsBotFlow::findOrFail($flow);
            $this->flowId = $flowModel->id;
            $this->flowName = $flowModel->name;
            $this->flowStatus = $flowModel->status;
            $this->triggerType = $flowModel->trigger_type;
            $this->triggerKeyword = $flowModel->trigger_keyword ?? '';
            $this->completionAction = $flowModel->completion_action ?? 'none';
            $this->timeoutMinutes = $flowModel->timeout_minutes;
            $this->delayAfterLead = $flowModel->delay_after_lead_minutes ?? 5;
            $this->businessHoursOnly = $flowModel->business_hours_only;

            $steps = $flowModel->steps->sortBy('sort_order')->values();
            $idToIndex = $steps->mapWithKeys(fn ($step, $idx) => [$step->id => $idx])->all();

            $this->steps = $steps->map(fn ($step) => [
                'id' => $step->id,
                'step_type' => $step->step_type,
                'message_text' => $step->message_text ?? '',
                'media_url' => $step->media_url ?? '',
                'source_step_index' => $step->source_step_id && isset($idToIndex[$step->source_step_id])
                    ? (string) $idToIndex[$step->source_step_id]
                    : '',
                'wait_for_media' => $step->wait_for_media ?? true,
                'quick_reply_options' => collect($step->quick_reply_options ?? [])->map(function (array $opt) use ($idToIndex) {
                    $opt['next_step_index'] = isset($opt['next_step_id']) && isset($idToIndex[$opt['next_step_id']])
                        ? (string) $idToIndex[$opt['next_step_id']]
                        : '';

                    return $opt;
                })->all(),
                'field_mapping' => $step->field_mapping,
                'is_required' => $step->is_required,
                'delay_seconds' => $step->delay_seconds ?? 3,
                'next_step_index' => $step->next_step_id && isset($idToIndex[$step->next_step_id])
                    ? (string) $idToIndex[$step->next_step_id]
                    : '',
            ])->toArray();
        }
    }

    #[Computed]
    public function mappableFields(): array
    {
        return SmsBotStep::getMappableFields();
    }

    public function stepIcon(string $type): string
    {
        return match ($type) {
            'greeting' => 'chat-bubble-left',
            'message' => 'chat-bubble-left-right',
            'media_message' => 'photo',
            'question' => 'question-mark-circle',
            'quick_reply' => 'list-bullet',
            'photo_prompt' => 'camera',
            'ai_visualization' => 'sparkles',
            'delay' => 'clock',
            'handoff' => 'user',
            default => 'chat-bubble-left',
        };
    }

    public function stepLabel(string $type): string
    {
        return match ($type) {
            'greeting' => __('Greeting'),
            'message' => __('Message'),
            'media_message' => __('Media Message'),
            'question' => __('Question'),
            'quick_reply' => __('Quick Reply'),
            'photo_prompt' => __('Photo Prompt'),
            'ai_visualization' => __('AI Visualization'),
            'delay' => __('Delay'),
            'handoff' => __('Handoff'),
            default => $type,
        };
    }

    public function branchTargetLabel(string $nextStepIndex): string
    {
        if ($nextStepIndex === '' || ! isset($this->steps[(int) $nextStepIndex])) {
            return __('Next');
        }

        $target = $this->steps[(int) $nextStepIndex];
        $num = (int) $nextStepIndex + 1;
        $preview = Str::limit($target['message_text'] ?: '(empty)', 30);

        return __('Step :num: :text', ['num' => $num, 'text' => $preview]);
    }

    public function toggleStep(int $index): void
    {
        $this->expandedStep = $this->expandedStep === $index ? null : $index;
    }

    public function saveFlow(): void
    {
        $rules = [
            'flowName' => ['required', 'string', 'max:255'],
            'triggerType' => ['required', 'in:new_lead,keyword,ad_lead,booking_lead'],
            'completionAction' => ['required', 'in:none,create_lead,create_deal,handoff'],
            'timeoutMinutes' => ['required', 'integer', 'min:1'],
            'steps' => ['array'],
            'steps.*.step_type' => ['required', 'string', 'in:greeting,message,media_message,question,quick_reply,photo_prompt,ai_visualization,delay,handoff'],
            'steps.*.message_text' => ['nullable', 'string', 'max:1600'],
        ];

        if ($this->triggerType === 'keyword') {
            $rules['triggerKeyword'] = ['required', 'string', 'max:50'];
        }

        $this->validate($rules);

        DB::transaction(function () {
            $attributes = [
                'name' => $this->flowName,
                'trigger_type' => $this->triggerType,
                'trigger_keyword' => $this->triggerType === 'keyword' ? strtoupper(trim($this->triggerKeyword)) : null,
                'completion_action' => $this->completionAction === 'none' ? null : $this->completionAction,
                'timeout_minutes' => $this->timeoutMinutes,
                'delay_after_lead_minutes' => $this->delayAfterLead,
                'business_hours_only' => $this->businessHoursOnly,
            ];

            // SQLite can't drop columns — old columns still have NOT NULL constraints in tests
            if (! $this->flowId && \DB::getDriverName() === 'sqlite') {
                $attributes['greeting_message'] = '';
                $attributes['photo_prompt_message'] = '';
                $attributes['visualization_message'] = '';
                $attributes['portal_link_message'] = '';
            }

            $flow = SmsBotFlow::updateOrCreate(
                ['id' => $this->flowId],
                $attributes
            );

            $this->flowId = $flow->id;

            // Delete removed steps
            $existingStepIds = collect($this->steps)->pluck('id')->filter()->toArray();
            $flow->steps()->whereNotIn('id', $existingStepIds)->delete();

            // First pass: create/update steps without branching references
            foreach ($this->steps as $index => $stepData) {
                $options = $stepData['step_type'] === 'quick_reply'
                    ? collect($stepData['quick_reply_options'] ?? [])->map(function (array $opt) {
                        return array_diff_key($opt, ['next_step_index' => '', 'next_step_id' => '']);
                    })->values()->all()
                    : null;

                $attributes = [
                    'flow_id' => $flow->id,
                    'sort_order' => $index + 1,
                    'step_type' => $stepData['step_type'],
                    'message_text' => $stepData['message_text'] ?: null,
                    'media_url' => in_array($stepData['step_type'], ['media_message']) ? ($stepData['media_url'] ?: null) : null,
                    'wait_for_media' => $stepData['step_type'] === 'photo_prompt' ? ($stepData['wait_for_media'] ?? true) : false,
                    'quick_reply_options' => $options,
                    'field_mapping' => $stepData['field_mapping'] ?? null,
                    'is_required' => $stepData['is_required'] ?? false,
                    'delay_seconds' => $stepData['step_type'] === 'delay' ? ($stepData['delay_seconds'] ?? 3) : null,
                    'next_step_id' => null,
                    'source_step_id' => null,
                ];

                if (! empty($stepData['id'])) {
                    SmsBotStep::where('id', $stepData['id'])->update($attributes);
                } else {
                    $step = SmsBotStep::create($attributes);
                    $this->steps[$index]['id'] = $step->id;
                }
            }

            // Second pass: resolve index-based references to real step IDs
            foreach ($this->steps as $index => $stepData) {
                $stepId = $this->steps[$index]['id'];
                $updates = [];

                if (($stepData['next_step_index'] ?? '') !== '' && isset($this->steps[(int) $stepData['next_step_index']])) {
                    $updates['next_step_id'] = $this->steps[(int) $stepData['next_step_index']]['id'];
                }

                if (($stepData['source_step_index'] ?? '') !== '' && isset($this->steps[(int) $stepData['source_step_index']])) {
                    $updates['source_step_id'] = $this->steps[(int) $stepData['source_step_index']]['id'];
                }

                if ($stepData['step_type'] === 'quick_reply') {
                    $options = collect($stepData['quick_reply_options'] ?? [])->map(function (array $opt) {
                        $result = array_diff_key($opt, ['next_step_index' => '']);
                        if (($opt['next_step_index'] ?? '') !== '' && isset($this->steps[(int) $opt['next_step_index']])) {
                            $result['next_step_id'] = $this->steps[(int) $opt['next_step_index']]['id'];
                        } else {
                            unset($result['next_step_id']);
                        }

                        return $result;
                    })->values()->all();

                    $updates['quick_reply_options'] = $options;
                }

                if (! empty($updates)) {
                    SmsBotStep::where('id', $stepId)->update($updates);
                }
            }
        });

        session()->flash('message', __('SMS bot flow saved.'));

        if (! request()->routeIs('settings.sms-bot.flows.edit')) {
            $this->redirect(route('settings.sms-bot.flows.edit', $this->flowId), navigate: true);
        }
    }

    public function addStepOfType(string $type): void
    {
        $this->steps[] = [
            'id' => null,
            'step_type' => $type,
            'message_text' => '',
            'media_url' => '',
            'source_step_index' => '',
            'wait_for_media' => $type === 'photo_prompt',
            'quick_reply_options' => $type === 'quick_reply' ? [
                ['label' => '', 'value' => '', 'next_step_index' => ''],
                ['label' => '', 'value' => '', 'next_step_index' => ''],
            ] : [],
            'field_mapping' => null,
            'is_required' => in_array($type, ['question', 'quick_reply']),
            'delay_seconds' => 3,
            'next_step_index' => '',
        ];

        $this->expandedStep = count($this->steps) - 1;
    }

    public function duplicateStep(int $index): void
    {
        $copy = $this->steps[$index];
        $copy['id'] = null;
        $copy['next_step_index'] = '';
        $copy['source_step_index'] = '';

        if (! empty($copy['quick_reply_options'])) {
            $copy['quick_reply_options'] = collect($copy['quick_reply_options'])->map(function ($opt) {
                $opt['next_step_index'] = '';

                return $opt;
            })->all();
        }

        array_splice($this->steps, $index + 1, 0, [$copy]);
        $this->expandedStep = $index + 1;
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);

        if ($this->expandedStep === $index) {
            $this->expandedStep = null;
        } elseif ($this->expandedStep !== null && $this->expandedStep > $index) {
            $this->expandedStep--;
        }
    }

    public function moveStepUp(int $index): void
    {
        if ($index <= 0) {
            return;
        }

        $temp = $this->steps[$index];
        $this->steps[$index] = $this->steps[$index - 1];
        $this->steps[$index - 1] = $temp;

        if ($this->expandedStep === $index) {
            $this->expandedStep = $index - 1;
        } elseif ($this->expandedStep === $index - 1) {
            $this->expandedStep = $index;
        }
    }

    public function moveStepDown(int $index): void
    {
        if ($index >= count($this->steps) - 1) {
            return;
        }

        $temp = $this->steps[$index];
        $this->steps[$index] = $this->steps[$index + 1];
        $this->steps[$index + 1] = $temp;

        if ($this->expandedStep === $index) {
            $this->expandedStep = $index + 1;
        } elseif ($this->expandedStep === $index + 1) {
            $this->expandedStep = $index;
        }
    }

    public function addQuickReplyOption(int $stepIndex): void
    {
        $this->steps[$stepIndex]['quick_reply_options'][] = [
            'label' => '',
            'value' => '',
            'next_step_index' => '',
        ];
    }

    public function removeQuickReplyOption(int $stepIndex, int $optionIndex): void
    {
        unset($this->steps[$stepIndex]['quick_reply_options'][$optionIndex]);
        $this->steps[$stepIndex]['quick_reply_options'] = array_values($this->steps[$stepIndex]['quick_reply_options']);
    }

    public function activateFlow(): void
    {
        if (! $this->flowId) {
            return;
        }

        SmsBotFlow::where('trigger_type', $this->triggerType)
            ->where('id', '!=', $this->flowId)
            ->where('status', 'active')
            ->update(['status' => 'disabled']);

        SmsBotFlow::where('id', $this->flowId)->update(['status' => 'active']);
        $this->flowStatus = 'active';
    }

    public function deactivateFlow(): void
    {
        if (! $this->flowId) {
            return;
        }

        SmsBotFlow::where('id', $this->flowId)->update(['status' => 'disabled']);
        $this->flowStatus = 'disabled';
    }
}; ?>

@php
    $stepBorderColor = fn(string $type) => match($type) {
        'greeting' => 'border-l-green-500',
        'message' => 'border-l-green-500',
        'media_message' => 'border-l-purple-500',
        'question' => 'border-l-blue-500',
        'quick_reply' => 'border-l-purple-500',
        'photo_prompt' => 'border-l-amber-500',
        'ai_visualization' => 'border-l-pink-500',
        'delay' => 'border-l-amber-500',
        'handoff' => 'border-l-red-500',
        default => 'border-l-zinc-400',
    };

    $smsVariables = [
        'company' => __('Company name'),
        'first_name' => __('Customer first name'),
        'last_name' => __('Customer last name'),
        'booking_link' => __('Booking form link'),
        'portal_link' => __('Project portal link'),
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8" x-data="{ settingsOpen: {{ $flowId ? 'false' : 'true' }} }">

    {{-- Header bar --}}
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <a href="{{ route('settings.sms-bot') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            <flux:icon name="arrow-left" class="size-4" />
            {{ __('SMS Bot Settings') }}
        </a>

        <flux:separator vertical class="h-5" />

        <flux:heading size="lg" class="!mb-0">{{ $flowName ?: __('New Flow') }}</flux:heading>

        <div class="flex-1"></div>

        @if ($flowId)
            <flux:badge size="sm" :color="match($flowStatus) { 'active' => 'green', 'disabled' => 'red', default => 'zinc' }">
                {{ ucfirst($flowStatus) }}
            </flux:badge>
            @if ($flowStatus !== 'active')
                <flux:button size="sm" wire:click="activateFlow" icon="bolt">{{ __('Activate') }}</flux:button>
            @else
                <flux:button size="sm" wire:click="deactivateFlow" icon="pause">{{ __('Deactivate') }}</flux:button>
            @endif
        @endif

        <flux:button variant="primary" wire:click="saveFlow" wire:loading.attr="disabled" icon="check">
            <span wire:loading.remove wire:target="saveFlow">{{ __('Save Flow') }}</span>
            <span wire:loading wire:target="saveFlow">{{ __('Saving...') }}</span>
        </flux:button>
    </div>

    @if (session('message'))
        <flux:callout variant="success" class="mb-4">{{ session('message') }}</flux:callout>
    @endif

    {{-- Collapsible flow settings panel --}}
    <div class="mb-6 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <button x-on:click="settingsOpen = !settingsOpen" class="flex w-full items-center justify-between px-5 py-3 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
            <div class="flex items-center gap-3">
                <flux:icon name="cog-6-tooth" class="size-5 text-zinc-400" />
                <flux:heading size="sm" class="!mb-0">{{ __('Flow Settings') }}</flux:heading>
                <span x-show="!settingsOpen" x-cloak class="text-sm text-zinc-400">
                    &mdash; {{ $flowName ?: __('Unnamed') }}
                    &middot; {{ ucfirst(str_replace('_', ' ', $triggerType)) }}
                    @if ($triggerType === 'keyword' && $triggerKeyword)
                        ({{ $triggerKeyword }})
                    @endif
                    &middot; {{ $timeoutMinutes }}{{ __('min timeout') }}
                    @if ($businessHoursOnly) &middot; {{ __('Business hours') }} @endif
                </span>
            </div>
            <flux:icon x-show="settingsOpen" name="chevron-up" class="size-4 shrink-0 text-zinc-400" />
            <flux:icon x-show="!settingsOpen" x-cloak name="chevron-down" class="size-4 shrink-0 text-zinc-400" />
        </button>

        <div x-show="settingsOpen" x-collapse>
            <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <flux:input wire:model="flowName" :label="__('Flow Name')" :placeholder="__('e.g. COLOR Visualization Flow')" />

                    <flux:select wire:model.live="triggerType" :label="__('Trigger Type')">
                        <flux:select.option value="new_lead">{{ __('New Lead') }}</flux:select.option>
                        <flux:select.option value="keyword">{{ __('Keyword') }}</flux:select.option>
                        <flux:select.option value="ad_lead">{{ __('Ad Lead') }}</flux:select.option>
                        <flux:select.option value="booking_lead">{{ __('Booking Lead') }}</flux:select.option>
                    </flux:select>

                    @if ($triggerType === 'keyword')
                        <flux:input wire:model="triggerKeyword" :label="__('Trigger Keyword')" placeholder="COLOR" />
                    @endif

                    <flux:select wire:model="completionAction" :label="__('Completion Action')">
                        <flux:select.option value="none">{{ __('None') }}</flux:select.option>
                        <flux:select.option value="create_lead">{{ __('Create Lead') }}</flux:select.option>
                        <flux:select.option value="create_deal">{{ __('Create Deal') }}</flux:select.option>
                        <flux:select.option value="handoff">{{ __('Handoff to Human') }}</flux:select.option>
                    </flux:select>

                    <flux:input type="number" wire:model="timeoutMinutes" :label="__('Timeout (minutes)')" min="1" />

                    @if ($triggerType === 'new_lead')
                        <flux:input type="number" wire:model="delayAfterLead" :label="__('Delay After Lead (min)')" min="0" />
                    @endif

                    <div class="flex items-end pb-1">
                        <flux:switch wire:model="businessHoursOnly" :label="__('Business Hours Only')" />
                    </div>
                </div>

                {{-- Flow summary stats --}}
                @if (count($steps) > 0)
                    <div class="mt-4 flex flex-wrap gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <div class="flex items-center gap-2 text-sm">
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ count($steps) }}</span>
                            <span class="text-zinc-400">{{ __('Steps') }}</span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ collect($steps)->where('step_type', 'quick_reply')->count() }}</span>
                            <span class="text-zinc-400">{{ __('Branch Points') }}</span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ collect($steps)->filter(fn($s) => in_array($s['step_type'], ['question', 'quick_reply']) && $s['field_mapping'])->count() }}</span>
                            <span class="text-zinc-400">{{ __('Mapped Fields') }}</span>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Main content: Steps + Preview --}}
    <div class="flex gap-6">
        {{-- Steps editor --}}
        <div class="min-w-0 flex-1 space-y-3">

            @forelse ($steps as $index => $step)
                <div wire:key="step-{{ $index }}" class="group rounded-xl border border-l-4 transition-all {{ $stepBorderColor($step['step_type']) }}
                    {{ $expandedStep === $index
                        ? 'border-blue-300 bg-white shadow-md dark:border-blue-700 dark:bg-zinc-800'
                        : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600' }}">

                    {{-- Step header --}}
                    <div class="flex cursor-pointer items-center gap-3 px-4 py-3" wire:click="toggleStep({{ $index }})">
                        <div class="shrink-0 cursor-grab text-zinc-300 dark:text-zinc-600">
                            <svg class="size-5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 10.001 4.001A2 2 0 007 2zm0 6a2 2 0 10.001 4.001A2 2 0 007 8zm0 6a2 2 0 10.001 4.001A2 2 0 007 14zm6-8a2 2 0 10-.001-4.001A2 2 0 0013 6zm0 2a2 2 0 10.001 4.001A2 2 0 0013 8zm0 6a2 2 0 10.001 4.001A2 2 0 0013 14z"/></svg>
                        </div>

                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg
                            {{ $expandedStep === $index ? 'bg-blue-100 dark:bg-blue-900/40' : 'bg-zinc-100 dark:bg-zinc-700' }}">
                            <flux:icon :name="$this->stepIcon($step['step_type'])" class="size-4 {{ $expandedStep === $index ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-500' }}" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ __('Step :num', ['num' => $index + 1]) }}</span>
                                <flux:badge size="sm" color="zinc">{{ $this->stepLabel($step['step_type']) }}</flux:badge>
                                @if ($step['field_mapping'] ?? null)
                                    <flux:badge size="sm" color="purple" icon="arrow-path">{{ $step['field_mapping'] }}</flux:badge>
                                @endif
                                @if (! empty($step['next_step_index']))
                                    <flux:badge size="sm" color="amber" icon="arrow-right">{{ __('Jump') }}</flux:badge>
                                @endif
                            </div>
                            <p class="mt-0.5 truncate text-sm text-zinc-700 dark:text-zinc-300">
                                {{ $step['message_text'] ?: __('(no message)') }}
                            </p>

                            @if ($step['step_type'] === 'quick_reply' && ! empty($step['quick_reply_options']) && $expandedStep !== $index)
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($step['quick_reply_options'] as $opt)
                                        @if (! empty($opt['label']))
                                            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs
                                                {{ ! empty($opt['next_step_index']) && $opt['next_step_index'] !== ''
                                                    ? 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-400'
                                                    : 'border-zinc-200 bg-zinc-50 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                                {{ $opt['label'] }}
                                                <flux:icon name="arrow-right" class="size-3 opacity-50" />
                                                <span class="font-medium">{{ $this->branchTargetLabel($opt['next_step_index'] ?? '') }}</span>
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-0.5" x-on:click.stop>
                            @if ($index > 0)
                                <flux:button size="xs" variant="ghost" wire:click="moveStepUp({{ $index }})" icon="arrow-up" />
                            @endif
                            @if ($index < count($steps) - 1)
                                <flux:button size="xs" variant="ghost" wire:click="moveStepDown({{ $index }})" icon="arrow-down" />
                            @endif
                            <flux:button size="xs" variant="ghost" wire:click="duplicateStep({{ $index }})" icon="document-duplicate" />
                            <flux:button size="xs" variant="ghost" wire:click="removeStep({{ $index }})" icon="trash" class="text-red-500 hover:text-red-700"
                                wire:confirm="{{ __('Delete this step?') }}" />
                        </div>

                        <flux:icon name="{{ $expandedStep === $index ? 'chevron-up' : 'chevron-down' }}" class="size-4 shrink-0 text-zinc-400" />
                    </div>

                    {{-- Expanded step editor --}}
                    @if ($expandedStep === $index)
                        <div class="space-y-4 border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                            {{-- Step Type --}}
                            <flux:select :label="__('Type')" wire:model.live="steps.{{ $index }}.step_type">
                                <flux:select.option value="greeting">{{ __('Greeting (auto-advance)') }}</flux:select.option>
                                <flux:select.option value="message">{{ __('Message (auto-advance)') }}</flux:select.option>
                                <flux:select.option value="media_message">{{ __('Media Message (image + text)') }}</flux:select.option>
                                <flux:select.option value="question">{{ __('Question (wait for reply)') }}</flux:select.option>
                                <flux:select.option value="quick_reply">{{ __('Quick Reply (options)') }}</flux:select.option>
                                <flux:select.option value="photo_prompt">{{ __('Photo Prompt (wait for photo)') }}</flux:select.option>
                                <flux:select.option value="ai_visualization">{{ __('AI Visualization (color render)') }}</flux:select.option>
                                <flux:select.option value="delay">{{ __('Delay') }}</flux:select.option>
                                <flux:select.option value="handoff">{{ __('Handoff to Human') }}</flux:select.option>
                            </flux:select>

                            {{-- Message Text --}}
                            <flux:textarea :label="__('Message')" wire:model="steps.{{ $index }}.message_text" rows="2"
                                :placeholder="match($step['step_type']) {
                                    'greeting' => __('e.g. Hi! Thanks for texting us.'),
                                    'message' => __('e.g. Here is some info...'),
                                    'media_message' => __('e.g. Check out this tutorial!'),
                                    'question' => __('e.g. What color are you interested in?'),
                                    'quick_reply' => __('e.g. What type of painting do you need?'),
                                    'photo_prompt' => __('e.g. Send a photo of the area you want painted!'),
                                    'ai_visualization' => __('e.g. Generating your color preview...'),
                                    'delay' => __('e.g. One moment please...'),
                                    'handoff' => __('e.g. Let me connect you with a team member.'),
                                    default => '',
                                }" />

                            {{-- Variable Insertion Helper --}}
                            <div class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 dark:border-blue-800 dark:bg-blue-950/30">
                                <div class="mb-1.5 flex items-center gap-1.5">
                                    <flux:icon name="code-bracket" class="size-3.5 text-blue-600 dark:text-blue-400" />
                                    <span class="text-xs font-medium text-blue-700 dark:text-blue-300">{{ __('Variables') }}</span>
                                    <span class="text-xs text-blue-500 dark:text-blue-400">{{ __('(click to insert)') }}</span>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($smsVariables as $var => $desc)
                                        @php $varPlaceholder = '{{ ' . $var . ' }}'; @endphp
                                        <flux:tooltip :content="$desc">
                                            <button type="button"
                                                x-on:click="
                                                    const container = $el.closest('.space-y-4');
                                                    const textarea = container.querySelector('textarea');
                                                    if (!textarea) return;
                                                    const start = textarea.selectionStart;
                                                    const end = textarea.selectionEnd;
                                                    const text = textarea.value;
                                                    const insert = @js($varPlaceholder);
                                                    textarea.value = text.substring(0, start) + insert + text.substring(end);
                                                    textarea.selectionStart = textarea.selectionEnd = start + insert.length;
                                                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                                                    textarea.focus();
                                                "
                                                class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-blue-200 bg-white px-2 py-0.5 font-mono text-xs text-blue-700 transition-colors hover:bg-blue-100 dark:border-blue-700 dark:bg-zinc-800 dark:text-blue-300 dark:hover:bg-blue-900/50"
                                            >
                                                {{ $varPlaceholder }}
                                            </button>
                                        </flux:tooltip>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Media Message: ShowcaseMedia slug --}}
                            @if ($step['step_type'] === 'media_message')
                                <flux:input wire:model="steps.{{ $index }}.media_url" :label="__('ShowcaseMedia Slug')"
                                    placeholder="color-tutorial"
                                    description="{{ __('Enter the slug from Media Hub > Showcase > Bot Media') }}" />
                            @endif

                            {{-- Photo Prompt: wait_for_media toggle --}}
                            @if ($step['step_type'] === 'photo_prompt')
                                <flux:switch wire:model="steps.{{ $index }}.wait_for_media" :label="__('Wait for Photo')"
                                    description="{{ __('Pause the flow until the customer sends a photo.') }}" />
                            @endif

                            {{-- AI Visualization: source step dropdown --}}
                            @if ($step['step_type'] === 'ai_visualization')
                                <flux:select wire:model="steps.{{ $index }}.source_step_index" :label="__('Color Source Step')"
                                    description="{{ __('Which question step provides the color/prompt text?') }}">
                                    <flux:select.option value="">{{ __('-- Select Source Step --') }}</flux:select.option>
                                    @foreach ($steps as $srcIdx => $srcStep)
                                        @if ($srcIdx !== $index && $srcStep['step_type'] === 'question')
                                            <flux:select.option value="{{ $srcIdx }}">
                                                {{ __('Step :num: :text', ['num' => $srcIdx + 1, 'text' => Str::limit($srcStep['message_text'] ?: '(empty)', 40)]) }}
                                            </flux:select.option>
                                        @endif
                                    @endforeach
                                </flux:select>
                            @endif

                            {{-- Quick Reply Options --}}
                            @if ($step['step_type'] === 'quick_reply')
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <flux:text class="text-sm font-medium">{{ __('Reply Options') }}</flux:text>
                                        <flux:button size="xs" variant="ghost" wire:click="addQuickReplyOption({{ $index }})" icon="plus">
                                            {{ __('Add Option') }}
                                        </flux:button>
                                    </div>

                                    @foreach ($step['quick_reply_options'] as $optIndex => $option)
                                        <div wire:key="option-{{ $index }}-{{ $optIndex }}"
                                            class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                            <div class="flex items-center justify-between">
                                                <flux:text size="xs" class="font-medium text-zinc-500">{{ __('Option :num', ['num' => $optIndex + 1]) }}</flux:text>
                                                <flux:button size="xs" variant="ghost" wire:click="removeQuickReplyOption({{ $index }}, {{ $optIndex }})"
                                                    icon="x-mark" class="text-red-500" />
                                            </div>

                                            <div class="grid grid-cols-2 gap-2">
                                                <flux:input :placeholder="__('Button Label')" wire:model="steps.{{ $index }}.quick_reply_options.{{ $optIndex }}.label" size="sm" />
                                                <flux:input :placeholder="__('Value/Payload')" wire:model="steps.{{ $index }}.quick_reply_options.{{ $optIndex }}.value" size="sm" />
                                            </div>

                                            <div class="flex items-center gap-2">
                                                <flux:icon name="arrow-right" class="size-4 shrink-0 text-blue-500" />
                                                <flux:select wire:model="steps.{{ $index }}.quick_reply_options.{{ $optIndex }}.next_step_index" size="sm" class="flex-1">
                                                    <flux:select.option value="">{{ __('Continue to next step') }}</flux:select.option>
                                                    @foreach ($steps as $targetIdx => $targetStep)
                                                        @if ($targetIdx !== $index)
                                                            <flux:select.option value="{{ $targetIdx }}">
                                                                {{ __('Step :num: :text', ['num' => $targetIdx + 1, 'text' => Str::limit($targetStep['message_text'] ?: '(empty)', 30)]) }}
                                                            </flux:select.option>
                                                        @endif
                                                    @endforeach
                                                </flux:select>
                                            </div>
                                        </div>
                                    @endforeach

                                    @if (empty($step['quick_reply_options']))
                                        <div class="rounded-lg border border-dashed border-zinc-300 py-3 text-center dark:border-zinc-600">
                                            <flux:text size="sm" class="text-zinc-400">{{ __('No options yet. Add at least one reply option.') }}</flux:text>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- Field Mapping --}}
                            @if (in_array($step['step_type'], ['question', 'quick_reply']))
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <flux:select :label="__('Save Answer As')" wire:model="steps.{{ $index }}.field_mapping">
                                        <flux:select.option value="">{{ __('-- No Mapping --') }}</flux:select.option>
                                        @foreach ($this->mappableFields as $group => $fields)
                                            <optgroup label="{{ $group }}">
                                                @foreach ($fields as $value => $label)
                                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </flux:select>

                                    <div class="flex items-end pb-1">
                                        <flux:switch :label="__('Required')" wire:model="steps.{{ $index }}.is_required" />
                                    </div>
                                </div>
                            @endif

                            {{-- Delay seconds --}}
                            @if ($step['step_type'] === 'delay')
                                <flux:input type="number" :label="__('Delay (seconds)')" wire:model="steps.{{ $index }}.delay_seconds" min="1" max="300" />
                            @endif

                            {{-- After this step, go to --}}
                            @if ($step['step_type'] !== 'handoff')
                                <flux:select :label="__('After this step, go to')" wire:model="steps.{{ $index }}.next_step_index">
                                    <flux:select.option value="">{{ __('Next step in order') }}</flux:select.option>
                                    @foreach ($steps as $targetIdx => $targetStep)
                                        @if ($targetIdx !== $index)
                                            <flux:select.option value="{{ $targetIdx }}">
                                                {{ __('Step :num: :text', ['num' => $targetIdx + 1, 'text' => Str::limit($targetStep['message_text'] ?: '(empty)', 30)]) }}
                                            </flux:select.option>
                                        @endif
                                    @endforeach
                                </flux:select>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Connector between steps --}}
                @if ($index < count($steps) - 1)
                    <div class="flex justify-center py-0.5">
                        <div class="h-4 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                    </div>
                @endif
            @empty
                <div class="rounded-xl border-2 border-dashed border-zinc-300 py-12 text-center dark:border-zinc-600">
                    <flux:icon name="chat-bubble-left-right" class="mx-auto size-10 text-zinc-300 dark:text-zinc-600" />
                    <flux:heading size="sm" class="mt-3 text-zinc-500">{{ __('No steps yet') }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-400">{{ __('Add your first step to start building the SMS conversation flow.') }}</flux:text>
                </div>
            @endforelse

            {{-- Add step buttons --}}
            <div class="rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-600">
                <flux:text size="xs" class="mb-2 font-medium text-zinc-400">{{ __('Add Step') }}</flux:text>
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="addStepOfType('greeting')" icon="chat-bubble-left">
                        {{ __('Greeting') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="addStepOfType('message')" icon="chat-bubble-left-right">
                        {{ __('Message') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="addStepOfType('media_message')" icon="photo">
                        {{ __('Media Message') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="addStepOfType('question')" icon="question-mark-circle">
                        {{ __('Question') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="addStepOfType('quick_reply')" icon="list-bullet">
                        {{ __('Quick Reply') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="addStepOfType('photo_prompt')" icon="camera">
                        {{ __('Photo Prompt') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="addStepOfType('ai_visualization')" icon="sparkles">
                        {{ __('AI Visualization') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="addStepOfType('delay')" icon="clock">
                        {{ __('Delay') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="addStepOfType('handoff')" icon="user">
                        {{ __('Handoff') }}
                    </flux:button>
                </div>
            </div>
        </div>

        {{-- SMS Preview (xl+ screens only) --}}
        <div class="hidden w-80 shrink-0 xl:block">
            <div class="sticky top-20">
                <div class="overflow-hidden rounded-2xl border-2 border-zinc-300 bg-zinc-100 shadow-lg dark:border-zinc-600 dark:bg-zinc-900">
                    {{-- Phone header --}}
                    <div class="flex items-center gap-3 bg-green-600 px-4 py-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-white/20">
                            <flux:icon name="chat-bubble-left-ellipsis" class="size-4 text-white" />
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-white">{{ $flowName ?: __('SMS Bot') }}</div>
                            <div class="text-xs text-green-200">{{ __('SMS Preview') }}</div>
                        </div>
                    </div>

                    {{-- Chat area --}}
                    <div class="h-96 space-y-3 overflow-y-auto p-3">
                        @foreach ($steps as $index => $step)
                            @if ($step['step_type'] === 'delay')
                                <div class="flex items-start gap-2">
                                    <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                                        <flux:icon name="clock" class="size-3 text-green-600 dark:text-green-400" />
                                    </div>
                                    <div class="rounded-2xl rounded-tl-sm border border-zinc-200 bg-white px-4 py-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                                        <div class="flex items-center gap-1">
                                            <div class="h-2 w-2 animate-bounce rounded-full bg-zinc-400" style="animation-delay: 0ms"></div>
                                            <div class="h-2 w-2 animate-bounce rounded-full bg-zinc-400" style="animation-delay: 150ms"></div>
                                            <div class="h-2 w-2 animate-bounce rounded-full bg-zinc-400" style="animation-delay: 300ms"></div>
                                        </div>
                                    </div>
                                </div>
                            @elseif ($step['step_type'] === 'handoff')
                                <div class="flex justify-center">
                                    <div class="rounded-full border border-red-200 bg-red-50 px-3 py-1 text-xs text-red-600 dark:border-red-800 dark:bg-red-900/30 dark:text-red-400">
                                        <flux:icon name="user" class="inline size-3 -mt-0.5" /> {{ __('Handoff to human') }}
                                    </div>
                                </div>
                            @elseif ($step['step_type'] === 'ai_visualization')
                                <div class="flex items-start gap-2">
                                    <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-pink-100 dark:bg-pink-900">
                                        <flux:icon name="sparkles" class="size-3 text-pink-600 dark:text-pink-400" />
                                    </div>
                                    <div class="max-w-[85%]">
                                        <div class="rounded-2xl rounded-tl-sm border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                            {{ $step['message_text'] ?: __('Generating your color preview...') }}
                                        </div>
                                        <div class="mt-1 flex items-center gap-1 rounded-lg border border-pink-200 bg-pink-50 px-2 py-1 text-xs text-pink-600 dark:border-pink-800 dark:bg-pink-900/30 dark:text-pink-400">
                                            <flux:icon name="sparkles" class="size-3" /> {{ __('AI render') }}
                                        </div>
                                    </div>
                                </div>
                            @elseif ($step['step_type'] === 'media_message')
                                <div class="flex items-start gap-2">
                                    <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-purple-100 dark:bg-purple-900">
                                        <flux:icon name="photo" class="size-3 text-purple-600 dark:text-purple-400" />
                                    </div>
                                    <div class="max-w-[85%]">
                                        <div class="rounded-2xl rounded-tl-sm border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                            {{ $step['message_text'] ?: __('...') }}
                                        </div>
                                        @if ($step['media_url'])
                                            <div class="mt-1 flex items-center gap-1 rounded-lg border border-purple-200 bg-purple-50 px-2 py-1 text-xs text-purple-600 dark:border-purple-800 dark:bg-purple-900/30 dark:text-purple-400">
                                                <flux:icon name="photo" class="size-3" /> {{ $step['media_url'] }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @else
                                {{-- Bot message bubble --}}
                                <div class="flex items-start gap-2">
                                    <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                                        <flux:icon name="chat-bubble-left" class="size-3 text-green-600 dark:text-green-400" />
                                    </div>
                                    <div class="max-w-[85%]">
                                        <div class="rounded-2xl rounded-tl-sm border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                            {{ $step['message_text'] ?: __('...') }}
                                        </div>

                                        @if ($step['step_type'] === 'quick_reply' && ! empty($step['quick_reply_options']))
                                            <div class="mt-1.5 flex flex-wrap gap-1">
                                                @foreach ($step['quick_reply_options'] as $opt)
                                                    @if (! empty($opt['label']))
                                                        <span class="rounded-full border border-green-300 bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-600 dark:border-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                            {{ $opt['label'] }}
                                                        </span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                {{-- Placeholder user response --}}
                                @if (in_array($step['step_type'], ['question', 'quick_reply', 'photo_prompt']))
                                    <div class="flex justify-end">
                                        <div class="max-w-[75%] rounded-2xl rounded-tr-sm bg-green-600 px-3 py-2 text-sm text-white shadow-sm">
                                            @if ($step['step_type'] === 'photo_prompt')
                                                <span class="flex items-center gap-1 italic opacity-75"><flux:icon name="camera" class="size-3" /> {{ __('Photo') }}</span>
                                            @elseif ($step['step_type'] === 'quick_reply' && ! empty($step['quick_reply_options'][0]['label']))
                                                {{ $step['quick_reply_options'][0]['label'] }}
                                            @else
                                                <span class="italic opacity-75">{{ $step['field_mapping'] ? Str::headline(str_replace(['customer.', 'project.', 'deal.'], '', $step['field_mapping'])) : __('User reply') }}...</span>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            @endif
                        @endforeach

                        @if (empty($steps))
                            <div class="py-8 text-center text-xs text-zinc-400">
                                {{ __('Add steps to preview the SMS conversation') }}
                            </div>
                        @endif
                    </div>

                    {{-- Phone footer --}}
                    <div class="flex items-center gap-2 border-t border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800">
                        <div class="flex-1 rounded-full bg-zinc-100 px-3 py-1.5 text-xs text-zinc-400 dark:bg-zinc-700">
                            {{ __('iMessage') }}
                        </div>
                        <div class="flex h-7 w-7 items-center justify-center rounded-full bg-green-600">
                            <flux:icon name="arrow-up" class="size-3.5 text-white" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
