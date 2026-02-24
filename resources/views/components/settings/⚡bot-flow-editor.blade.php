<?php

use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $flowId = null;
    public string $flowName = '';
    public string $flowStatus = 'draft';
    public string $greetingMessage = '';
    public int $timeoutMinutes = 30;
    public bool $businessHoursOnly = false;
    public array $steps = [];

    public function mount(?int $flowId = null): void
    {
        if ($flowId) {
            $flow = MessengerBotFlow::findOrFail($flowId);
            $this->flowId = $flow->id;
            $this->flowName = $flow->name;
            $this->flowStatus = $flow->status;
            $this->greetingMessage = $flow->greeting_message ?? '';
            $this->timeoutMinutes = $flow->timeout_minutes;
            $this->businessHoursOnly = $flow->business_hours_only;
            $this->steps = $flow->steps->map(fn ($step) => [
                'id' => $step->id,
                'step_type' => $step->step_type,
                'message_text' => $step->message_text,
                'quick_reply_options' => $step->quick_reply_options ?? [],
                'field_mapping' => $step->field_mapping,
                'is_required' => $step->is_required,
                'delay_seconds' => $step->delay_seconds,
            ])->toArray();
        }
    }

    #[Computed]
    public function mappableFields(): array
    {
        return MessengerBotStep::getMappableFields();
    }

    public function saveFlow(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'flowName' => ['required', 'string', 'max:255'],
            'greetingMessage' => ['nullable', 'string', 'max:1000'],
            'timeoutMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'steps' => ['array'],
            'steps.*.step_type' => ['required', 'string', 'in:greeting,text_question,quick_reply,delay,handoff'],
            'steps.*.message_text' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () {
            $flow = MessengerBotFlow::updateOrCreate(
                ['id' => $this->flowId],
                [
                    'name' => $this->flowName,
                    'greeting_message' => $this->greetingMessage ?: null,
                    'timeout_minutes' => $this->timeoutMinutes,
                    'business_hours_only' => $this->businessHoursOnly,
                ]
            );

            $this->flowId = $flow->id;

            // Delete removed steps
            $existingStepIds = collect($this->steps)->pluck('id')->filter()->toArray();
            $flow->steps()->whereNotIn('id', $existingStepIds)->delete();

            // Create or update steps
            foreach ($this->steps as $index => $stepData) {
                $attributes = [
                    'flow_id' => $flow->id,
                    'sort_order' => $index + 1,
                    'step_type' => $stepData['step_type'],
                    'message_text' => $stepData['message_text'],
                    'quick_reply_options' => $stepData['step_type'] === 'quick_reply' ? ($stepData['quick_reply_options'] ?? []) : null,
                    'field_mapping' => $stepData['field_mapping'] ?? null,
                    'is_required' => $stepData['is_required'] ?? false,
                    'delay_seconds' => $stepData['step_type'] === 'delay' ? ($stepData['delay_seconds'] ?? 3) : null,
                ];

                if (! empty($stepData['id'])) {
                    MessengerBotStep::where('id', $stepData['id'])->update($attributes);
                } else {
                    $step = MessengerBotStep::create($attributes);
                    $this->steps[$index]['id'] = $step->id;
                }
            }
        });

        $this->dispatch('flow-saved');
        $this->dispatch('notify', message: __('Bot flow saved.'));
    }

    public function addStep(): void
    {
        $this->steps[] = [
            'id' => null,
            'step_type' => 'text_question',
            'message_text' => '',
            'quick_reply_options' => [],
            'field_mapping' => null,
            'is_required' => false,
            'delay_seconds' => 3,
        ];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function moveStepUp(int $index): void
    {
        if ($index <= 0) {
            return;
        }

        $temp = $this->steps[$index];
        $this->steps[$index] = $this->steps[$index - 1];
        $this->steps[$index - 1] = $temp;
    }

    public function moveStepDown(int $index): void
    {
        if ($index >= count($this->steps) - 1) {
            return;
        }

        $temp = $this->steps[$index];
        $this->steps[$index] = $this->steps[$index + 1];
        $this->steps[$index + 1] = $temp;
    }

    public function addQuickReplyOption(int $stepIndex): void
    {
        $this->steps[$stepIndex]['quick_reply_options'][] = [
            'label' => '',
            'value' => '',
        ];
    }

    public function removeQuickReplyOption(int $stepIndex, int $optionIndex): void
    {
        unset($this->steps[$stepIndex]['quick_reply_options'][$optionIndex]);
        $this->steps[$stepIndex]['quick_reply_options'] = array_values($this->steps[$stepIndex]['quick_reply_options']);
    }

    public function activateFlow(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (! $this->flowId) {
            return;
        }

        // Deactivate all other flows
        MessengerBotFlow::where('id', '!=', $this->flowId)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        MessengerBotFlow::where('id', $this->flowId)->update(['status' => 'active']);
        $this->flowStatus = 'active';

        $this->dispatch('flow-saved');
        $this->dispatch('notify', message: __('Bot flow activated.'));
    }

    public function deactivateFlow(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (! $this->flowId) {
            return;
        }

        MessengerBotFlow::where('id', $this->flowId)->update(['status' => 'inactive']);
        $this->flowStatus = 'inactive';

        $this->dispatch('flow-saved');
        $this->dispatch('notify', message: __('Bot flow deactivated.'));
    }
}; ?>

<div class="space-y-6">
    {{-- Flow Info --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-6 space-y-4 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg">{{ $flowId ? __('Edit Bot Flow') : __('Create Bot Flow') }}</flux:heading>

        <flux:input wire:model="flowName" :label="__('Flow Name')" :placeholder="__('e.g. Lead Qualification')" />

        <flux:textarea wire:model="greetingMessage" :label="__('Greeting Message')" rows="2" :hint="__('First message sent when a new conversation starts')" />

        <flux:input type="number" wire:model="timeoutMinutes" :label="__('Timeout (minutes)')" min="1" max="1440" />

        <flux:switch wire:model="businessHoursOnly" :label="__('Business Hours Only')" />

        {{-- Status badge --}}
        @if ($flowId)
            <div class="flex items-center gap-3">
                <flux:text class="text-sm font-medium">{{ __('Status:') }}</flux:text>
                <flux:badge :color="match($flowStatus) { 'active' => 'green', 'inactive' => 'red', default => 'zinc' }">
                    {{ ucfirst($flowStatus) }}
                </flux:badge>
                @if ($flowStatus !== 'active')
                    <flux:button size="sm" variant="primary" wire:click="activateFlow">{{ __('Activate') }}</flux:button>
                @else
                    <flux:button size="sm" variant="danger" wire:click="deactivateFlow">{{ __('Deactivate') }}</flux:button>
                @endif
            </div>
        @endif
    </div>

    {{-- Steps --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-6 space-y-4 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg">{{ __('Steps') }}</flux:heading>

        @forelse ($steps as $index => $step)
            <div wire:key="step-{{ $index }}" class="rounded-lg border border-zinc-200 p-4 space-y-3 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Step :number', ['number' => $index + 1]) }}</span>
                    <div class="flex gap-1">
                        @if ($index > 0)
                            <flux:button size="xs" variant="ghost" wire:click="moveStepUp({{ $index }})" icon="arrow-up" />
                        @endif
                        @if ($index < count($steps) - 1)
                            <flux:button size="xs" variant="ghost" wire:click="moveStepDown({{ $index }})" icon="arrow-down" />
                        @endif
                        <flux:button size="xs" variant="ghost" wire:click="removeStep({{ $index }})" icon="x-mark" class="text-red-500 hover:text-red-700" />
                    </div>
                </div>

                {{-- Step Type --}}
                <flux:select :label="__('Type')" wire:model.live="steps.{{ $index }}.step_type">
                    <flux:select.option value="greeting">{{ __('Greeting') }}</flux:select.option>
                    <flux:select.option value="text_question">{{ __('Text Question') }}</flux:select.option>
                    <flux:select.option value="quick_reply">{{ __('Quick Reply') }}</flux:select.option>
                    <flux:select.option value="delay">{{ __('Delay') }}</flux:select.option>
                    <flux:select.option value="handoff">{{ __('Handoff') }}</flux:select.option>
                </flux:select>

                {{-- Message Text --}}
                <flux:textarea :label="__('Message')" wire:model="steps.{{ $index }}.message_text" rows="2" />

                {{-- Quick Reply Options (only for quick_reply type) --}}
                @if ($step['step_type'] === 'quick_reply')
                    <div class="space-y-2">
                        <flux:text class="text-sm font-medium">{{ __('Quick Reply Options') }}</flux:text>
                        @foreach ($step['quick_reply_options'] as $optIndex => $option)
                            <div class="flex gap-2" wire:key="option-{{ $index }}-{{ $optIndex }}">
                                <flux:input :placeholder="__('Label')" wire:model="steps.{{ $index }}.quick_reply_options.{{ $optIndex }}.label" size="sm" />
                                <flux:input :placeholder="__('Value')" wire:model="steps.{{ $index }}.quick_reply_options.{{ $optIndex }}.value" size="sm" />
                                <flux:button size="sm" variant="ghost" wire:click="removeQuickReplyOption({{ $index }}, {{ $optIndex }})" icon="x-mark" class="text-red-500 shrink-0" />
                            </div>
                        @endforeach
                        <flux:button size="sm" variant="ghost" wire:click="addQuickReplyOption({{ $index }})" icon="plus">
                            {{ __('Add Option') }}
                        </flux:button>
                    </div>
                @endif

                {{-- Field Mapping (for questions) --}}
                @if (in_array($step['step_type'], ['text_question', 'quick_reply']))
                    <flux:select :label="__('Map Answer To')" wire:model="steps.{{ $index }}.field_mapping">
                        <flux:select.option value="">{{ __('-- No Mapping --') }}</flux:select.option>
                        @foreach ($this->mappableFields as $group => $fields)
                            <optgroup label="{{ $group }}">
                                @foreach ($fields as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </flux:select>

                    <flux:switch :label="__('Required')" wire:model="steps.{{ $index }}.is_required" />
                @endif

                {{-- Delay seconds --}}
                @if ($step['step_type'] === 'delay')
                    <flux:input type="number" :label="__('Delay (seconds)')" wire:model="steps.{{ $index }}.delay_seconds" min="1" max="300" />
                @endif
            </div>
        @empty
            <div class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('No steps yet. Add a step to define the conversation flow.') }}
            </div>
        @endforelse

        <flux:button wire:click="addStep" variant="ghost" icon="plus">{{ __('Add Step') }}</flux:button>
    </div>

    {{-- Save --}}
    <div class="flex justify-end gap-3">
        <flux:button variant="primary" wire:click="saveFlow" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="saveFlow">{{ __('Save Flow') }}</span>
            <span wire:loading wire:target="saveFlow">{{ __('Saving...') }}</span>
        </flux:button>
    </div>
</div>
