<?php

use App\Models\Prospector\Contact;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceEnrollment;
use App\Models\Prospector\SequenceStep;
use App\Services\Prospector\SequenceEnrollmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Sequence Builder')]
class extends Component
{
    use WithPagination;

    #[Locked]
    public int $sequenceId;

    // Step form
    public bool $showStepModal = false;
    public ?int $editingStepId = null;
    public string $stepType = 'sms';
    public ?int $stepDelayValue = null;
    public ?string $stepDelayUnit = null;
    // SMS config
    public string $smsBody = '';
    public string $smsMediaUrl = '';
    // Email config
    public string $emailSubject = '';
    public string $emailBody = '';
    // Call config
    public string $callScript = '';
    // Wait config
    public int $waitValue = 1;
    public string $waitUnit = 'days';
    // Condition config
    public string $condField = 'last_disposition';
    public string $condOperator = 'equals';
    public string $condValue = '';

    // Delete step
    public bool $showDeleteStepModal = false;
    public ?int $deletingStepId = null;

    // Enrollment
    public bool $showEnrollModal = false;
    public string $enrollSearch = '';
    public array $selectedContactIds = [];

    // Enrollment filter
    #[Url]
    public string $enrollmentStatus = '';

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    public function mount(int $sequenceId): void
    {
        $this->sequenceId = $sequenceId;
        Sequence::findOrFail($sequenceId);
    }

    #[Computed]
    public function sequence(): Sequence
    {
        return Sequence::with('company')->findOrFail($this->sequenceId);
    }

    #[Computed]
    public function steps(): \Illuminate\Support\Collection
    {
        return SequenceStep::where('sequence_id', $this->sequenceId)
            ->ordered()
            ->get();
    }

    #[Computed]
    public function enrollments(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return SequenceEnrollment::where('sequence_id', $this->sequenceId)
            ->with(['contact', 'currentStep'])
            ->when($this->enrollmentStatus, fn ($q) => $q->where('status', $this->enrollmentStatus))
            ->orderByDesc('enrolled_at')
            ->paginate(20);
    }

    #[Computed]
    public function searchContacts(): \Illuminate\Support\Collection
    {
        if (strlen($this->enrollSearch) < 2) {
            return collect();
        }

        $companyId = $this->sequence->company_id;
        $existingIds = SequenceEnrollment::where('sequence_id', $this->sequenceId)
            ->pluck('contact_id')
            ->all();

        return Contact::where('company_id', $companyId)
            ->whereNotIn('id', $existingIds)
            ->search($this->enrollSearch)
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function activeEnrollmentCount(): int
    {
        return SequenceEnrollment::where('sequence_id', $this->sequenceId)
            ->where('status', 'active')
            ->count();
    }

    // ──────────────────────────────────────
    // Step Management
    // ──────────────────────────────────────

    public function openAddStepModal(): void
    {
        $this->resetStepForm();
        $this->showStepModal = true;
    }

    public function openEditStepModal(int $stepId): void
    {
        $step = SequenceStep::findOrFail($stepId);
        $this->editingStepId = $step->id;
        $this->stepType = $step->type;
        $this->stepDelayValue = $step->delay_value;
        $this->stepDelayUnit = $step->delay_unit;

        $config = $step->config ?? [];

        match ($step->type) {
            'sms' => $this->fillSmsConfig($config),
            'email' => $this->fillEmailConfig($config),
            'call' => $this->callScript = $config['script'] ?? '',
            'wait' => $this->fillWaitConfig($step),
            'condition' => $this->fillConditionConfig($config),
            default => null,
        };

        $this->showStepModal = true;
    }

    public function saveStep(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'stepType' => ['required', Rule::in(['sms', 'email', 'rvm', 'call', 'wait', 'condition'])],
            'stepDelayValue' => ['nullable', 'integer', 'min:0'],
            'stepDelayUnit' => ['nullable', Rule::in(['minutes', 'hours', 'days'])],
        ]);

        $config = $this->buildStepConfig();

        if ($this->editingStepId) {
            $step = SequenceStep::findOrFail($this->editingStepId);
            $step->update([
                'type' => $this->stepType,
                'delay_value' => $this->stepType === 'wait' ? $this->waitValue : $this->stepDelayValue,
                'delay_unit' => $this->stepType === 'wait' ? $this->waitUnit : $this->stepDelayUnit,
                'config' => $config,
            ]);
        } else {
            $maxOrder = SequenceStep::where('sequence_id', $this->sequenceId)->max('sort_order') ?? -1;

            SequenceStep::create([
                'sequence_id' => $this->sequenceId,
                'sort_order' => $maxOrder + 1,
                'type' => $this->stepType,
                'delay_value' => $this->stepType === 'wait' ? $this->waitValue : $this->stepDelayValue,
                'delay_unit' => $this->stepType === 'wait' ? $this->waitUnit : $this->stepDelayUnit,
                'config' => $config,
            ]);
        }

        $this->showStepModal = false;
        $this->resetStepForm();
        unset($this->steps);
    }

    public function moveStepUp(int $stepId): void
    {
        $steps = $this->steps;
        $index = $steps->search(fn ($s) => $s->id === $stepId);

        if ($index <= 0) {
            return;
        }

        $steps[$index]->update(['sort_order' => $index - 1]);
        $steps[$index - 1]->update(['sort_order' => $index]);
        unset($this->steps);
    }

    public function moveStepDown(int $stepId): void
    {
        $steps = $this->steps;
        $index = $steps->search(fn ($s) => $s->id === $stepId);

        if ($index === false || $index >= $steps->count() - 1) {
            return;
        }

        $steps[$index]->update(['sort_order' => $index + 1]);
        $steps[$index + 1]->update(['sort_order' => $index]);
        unset($this->steps);
    }

    public function confirmDeleteStep(int $stepId): void
    {
        $this->deletingStepId = $stepId;
        $this->showDeleteStepModal = true;
    }

    public function deleteStep(): void
    {
        if (! $this->deletingStepId) {
            return;
        }

        $step = SequenceStep::findOrFail($this->deletingStepId);
        $step->delete();

        // Re-index sort orders
        SequenceStep::where('sequence_id', $this->sequenceId)
            ->orderBy('sort_order')
            ->get()
            ->each(fn ($s, $i) => $s->update(['sort_order' => $i]));

        $this->showDeleteStepModal = false;
        $this->deletingStepId = null;
        unset($this->steps);
    }

    // ──────────────────────────────────────
    // Enrollment Management
    // ──────────────────────────────────────

    public function openEnrollModal(): void
    {
        $this->enrollSearch = '';
        $this->selectedContactIds = [];
        $this->showEnrollModal = true;
    }

    public function toggleContact(int $contactId): void
    {
        if (in_array($contactId, $this->selectedContactIds)) {
            $this->selectedContactIds = array_values(array_diff($this->selectedContactIds, [$contactId]));
        } else {
            $this->selectedContactIds[] = $contactId;
        }
    }

    public function enrollSelected(): void
    {
        if (empty($this->selectedContactIds)) {
            return;
        }

        $service = app(SequenceEnrollmentService::class);
        $sequence = Sequence::findOrFail($this->sequenceId);

        $service->bulkEnroll($this->selectedContactIds, $sequence, Auth::user());

        $this->showEnrollModal = false;
        $this->selectedContactIds = [];
        $this->enrollSearch = '';
        unset($this->enrollments, $this->activeEnrollmentCount, $this->searchContacts);
    }

    public function pauseEnrollment(int $enrollmentId): void
    {
        $enrollment = SequenceEnrollment::findOrFail($enrollmentId);
        app(SequenceEnrollmentService::class)->pauseEnrollment($enrollment);
        unset($this->enrollments, $this->activeEnrollmentCount);
    }

    public function resumeEnrollment(int $enrollmentId): void
    {
        $enrollment = SequenceEnrollment::findOrFail($enrollmentId);
        app(SequenceEnrollmentService::class)->resumeEnrollment($enrollment);
        unset($this->enrollments, $this->activeEnrollmentCount);
    }

    public function unenrollEnrollment(int $enrollmentId): void
    {
        $enrollment = SequenceEnrollment::findOrFail($enrollmentId);
        app(SequenceEnrollmentService::class)->unenroll($enrollment, 'manual');
        unset($this->enrollments, $this->activeEnrollmentCount);
    }

    public function updatedEnrollmentStatus(): void
    {
        $this->resetPage();
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    protected function buildStepConfig(): array
    {
        return match ($this->stepType) {
            'sms' => array_filter([
                'body' => $this->smsBody,
                'media_url' => $this->smsMediaUrl ?: null,
            ]),
            'email' => [
                'subject' => $this->emailSubject,
                'template' => $this->emailBody,
            ],
            'rvm' => ['audio_file_id' => null],
            'call' => ['script' => $this->callScript],
            'wait' => [],
            'condition' => [
                'field' => $this->condField,
                'operator' => $this->condOperator,
                'value' => $this->condValue,
            ],
            default => [],
        };
    }

    protected function fillSmsConfig(array $config): void
    {
        $this->smsBody = $config['body'] ?? '';
        $this->smsMediaUrl = $config['media_url'] ?? '';
    }

    protected function fillEmailConfig(array $config): void
    {
        $this->emailSubject = $config['subject'] ?? '';
        $this->emailBody = $config['template'] ?? '';
    }

    protected function fillWaitConfig(SequenceStep $step): void
    {
        $this->waitValue = $step->delay_value ?? 1;
        $this->waitUnit = $step->delay_unit ?? 'days';
    }

    protected function fillConditionConfig(array $config): void
    {
        $this->condField = $config['field'] ?? 'last_disposition';
        $this->condOperator = $config['operator'] ?? 'equals';
        $this->condValue = $config['value'] ?? '';
    }

    protected function resetStepForm(): void
    {
        $this->editingStepId = null;
        $this->stepType = 'sms';
        $this->stepDelayValue = null;
        $this->stepDelayUnit = null;
        $this->smsBody = '';
        $this->smsMediaUrl = '';
        $this->emailSubject = '';
        $this->emailBody = '';
        $this->callScript = '';
        $this->waitValue = 1;
        $this->waitUnit = 'days';
        $this->condField = 'last_disposition';
        $this->condOperator = 'equals';
        $this->condValue = '';
    }

    protected function getStepSummary(SequenceStep $step): string
    {
        return match ($step->type) {
            'sms' => Str::limit($step->config['body'] ?? 'No message', 60),
            'email' => $step->config['subject'] ?? 'No subject',
            'rvm' => 'Ringless voicemail drop',
            'call' => 'Add to call queue',
            'wait' => ($step->delay_value ?? 0).' '.($step->delay_unit ?? 'days'),
            'condition' => ($step->config['field'] ?? '').' '.($step->config['operator'] ?? '').' '.($step->config['value'] ?? ''),
            default => $step->type,
        };
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="mb-6">
        <div class="mb-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('prospector.sequences')" size="sm">
                {{ __('Back to Sequences') }}
            </flux:button>
        </div>

        <div class="flex items-start justify-between">
            <div>
                <flux:heading size="xl">{{ $this->sequence->name }}</flux:heading>
                <div class="mt-1 flex items-center gap-3">
                    @if ($this->sequence->company)
                        <flux:text>{{ $this->sequence->company->name }}</flux:text>
                    @endif
                    @switch($this->sequence->status)
                        @case('draft')
                            <flux:badge size="sm" color="zinc">{{ __('Draft') }}</flux:badge>
                            @break
                        @case('active')
                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                            @break
                        @case('paused')
                            <flux:badge size="sm" color="yellow">{{ __('Paused') }}</flux:badge>
                            @break
                    @endswitch
                    <flux:text class="text-sm">{{ $this->activeEnrollmentCount }} {{ __('active enrollments') }}</flux:text>
                    <flux:text class="text-sm">{{ $this->sequence->getCompletionRate() }}% {{ __('completion') }}</flux:text>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
        {{-- Steps Panel --}}
        <div>
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Steps') }}</flux:heading>
                <flux:button variant="primary" icon="plus" size="sm" wire:click="openAddStepModal">
                    {{ __('Add Step') }}
                </flux:button>
            </div>

            @if ($this->steps->isEmpty())
                <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="queue-list" class="mx-auto mb-2 size-10 text-zinc-400" />
                    <flux:text>{{ __('No steps yet. Add your first step to build the sequence.') }}</flux:text>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($this->steps as $index => $step)
                        <div wire:key="step-{{ $step->id }}" class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                            {{-- Step number --}}
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-sm font-semibold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                {{ $index + 1 }}
                            </div>

                            {{-- Type badge --}}
                            <div class="shrink-0">
                                @switch($step->type)
                                    @case('sms')
                                        <flux:badge size="sm" color="blue">{{ __('SMS') }}</flux:badge>
                                        @break
                                    @case('email')
                                        <flux:badge size="sm" color="purple">{{ __('Email') }}</flux:badge>
                                        @break
                                    @case('rvm')
                                        <flux:badge size="sm" color="orange">{{ __('RVM') }}</flux:badge>
                                        @break
                                    @case('call')
                                        <flux:badge size="sm" color="green">{{ __('Call') }}</flux:badge>
                                        @break
                                    @case('wait')
                                        <flux:badge size="sm" color="zinc">{{ __('Wait') }}</flux:badge>
                                        @break
                                    @case('condition')
                                        <flux:badge size="sm" color="yellow">{{ __('Condition') }}</flux:badge>
                                        @break
                                @endswitch
                            </div>

                            {{-- Summary --}}
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm text-zinc-700 dark:text-zinc-300">
                                    @switch($step->type)
                                        @case('sms')
                                            {{ Str::limit($step->config['body'] ?? 'No message', 50) }}
                                            @break
                                        @case('email')
                                            {{ $step->config['subject'] ?? 'No subject' }}
                                            @break
                                        @case('rvm')
                                            {{ __('Ringless voicemail drop') }}
                                            @break
                                        @case('call')
                                            {{ __('Add to call queue') }}
                                            @break
                                        @case('wait')
                                            {{ __('Wait') }} {{ $step->delay_value }} {{ $step->delay_unit }}
                                            @break
                                        @case('condition')
                                            {{ $step->config['field'] ?? '' }} {{ $step->config['operator'] ?? '' }} {{ $step->config['value'] ?? '' }}
                                            @break
                                    @endswitch
                                </div>
                                @if ($step->type !== 'wait' && $step->delay_value)
                                    <div class="text-xs text-zinc-500">{{ __('After') }} {{ $step->delay_value }} {{ $step->delay_unit }}</div>
                                @endif
                            </div>

                            {{-- Actions --}}
                            <div class="flex shrink-0 items-center gap-0.5">
                                @if ($index > 0)
                                    <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="moveStepUp({{ $step->id }})" title="{{ __('Move Up') }}" />
                                @endif
                                @if ($index < $this->steps->count() - 1)
                                    <flux:button size="sm" variant="ghost" icon="chevron-down" wire:click="moveStepDown({{ $step->id }})" title="{{ __('Move Down') }}" />
                                @endif
                                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditStepModal({{ $step->id }})" title="{{ __('Edit') }}" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDeleteStep({{ $step->id }})" title="{{ __('Delete') }}" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Enrollments Panel --}}
        <div>
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Enrollments') }}</flux:heading>
                <flux:button variant="primary" icon="user-plus" size="sm" wire:click="openEnrollModal">
                    {{ __('Enroll Contacts') }}
                </flux:button>
            </div>

            <div class="mb-3">
                <flux:select wire:model.live="enrollmentStatus" class="max-w-[160px]">
                    <flux:select.option value="">{{ __('All Statuses') }}</flux:select.option>
                    <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                    <flux:select.option value="completed">{{ __('Completed') }}</flux:select.option>
                    <flux:select.option value="paused">{{ __('Paused') }}</flux:select.option>
                    <flux:select.option value="exited">{{ __('Exited') }}</flux:select.option>
                    <flux:select.option value="failed">{{ __('Failed') }}</flux:select.option>
                </flux:select>
            </div>

            @if ($this->enrollments->isEmpty())
                <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="users" class="mx-auto mb-2 size-10 text-zinc-400" />
                    <flux:text>{{ __('No enrollments. Enroll contacts to start the sequence.') }}</flux:text>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Contact') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Step') }}</flux:table.column>
                        <flux:table.column>{{ __('Enrolled') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->enrollments as $enrollment)
                            <flux:table.row wire:key="enr-{{ $enrollment->id }}">
                                <flux:table.cell>
                                    @if ($enrollment->contact)
                                        <div class="font-medium text-sm">{{ $enrollment->contact->getFullName() }}</div>
                                        <div class="text-xs text-zinc-500">{{ $enrollment->contact->phone }}</div>
                                    @else
                                        <span class="text-zinc-400">{{ __('Deleted') }}</span>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell>
                                    @switch($enrollment->status)
                                        @case('active')
                                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                            @break
                                        @case('completed')
                                            <flux:badge size="sm" color="blue">{{ __('Completed') }}</flux:badge>
                                            @break
                                        @case('paused')
                                            <flux:badge size="sm" color="yellow">{{ __('Paused') }}</flux:badge>
                                            @break
                                        @case('exited')
                                            <flux:badge size="sm" color="zinc">{{ __('Exited') }}</flux:badge>
                                            @break
                                        @case('failed')
                                            <flux:badge size="sm" color="red">{{ __('Failed') }}</flux:badge>
                                            @break
                                    @endswitch
                                </flux:table.cell>

                                <flux:table.cell>
                                    @if ($enrollment->currentStep)
                                        <span class="text-sm capitalize">{{ $enrollment->currentStep->type }}</span>
                                        <span class="text-xs text-zinc-500">#{{ $enrollment->currentStep->sort_order + 1 }}</span>
                                    @elseif ($enrollment->status === 'completed')
                                        <span class="text-zinc-400 text-sm">{{ __('Done') }}</span>
                                    @else
                                        <span class="text-zinc-400 text-sm">--</span>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell>
                                    <span class="text-sm">{{ $enrollment->enrolled_at?->diffForHumans() }}</span>
                                </flux:table.cell>

                                <flux:table.cell class="text-right">
                                    <div class="flex items-center justify-end gap-0.5">
                                        @if ($enrollment->status === 'active')
                                            <flux:button size="sm" variant="ghost" icon="pause" wire:click="pauseEnrollment({{ $enrollment->id }})" title="{{ __('Pause') }}" />
                                        @endif
                                        @if ($enrollment->status === 'paused')
                                            <flux:button size="sm" variant="ghost" icon="play" wire:click="resumeEnrollment({{ $enrollment->id }})" title="{{ __('Resume') }}" />
                                        @endif
                                        @if (in_array($enrollment->status, ['active', 'paused']))
                                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="unenrollEnrollment({{ $enrollment->id }})" title="{{ __('Unenroll') }}" />
                                        @endif
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                <div class="mt-4">
                    {{ $this->enrollments->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Add/Edit Step Modal --}}
    <flux:modal wire:model="showStepModal" class="max-w-lg">
        <form wire:submit="saveStep" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingStepId ? __('Edit Step') : __('Add Step') }}</flux:heading>
            </div>

            <flux:select wire:model.live="stepType" :label="__('Step Type')" required>
                <flux:select.option value="sms">{{ __('SMS') }}</flux:select.option>
                <flux:select.option value="email">{{ __('Email') }}</flux:select.option>
                <flux:select.option value="rvm">{{ __('Ringless Voicemail') }}</flux:select.option>
                <flux:select.option value="call">{{ __('Call') }}</flux:select.option>
                <flux:select.option value="wait">{{ __('Wait / Delay') }}</flux:select.option>
                <flux:select.option value="condition">{{ __('Condition') }}</flux:select.option>
            </flux:select>

            {{-- Delay before step (not for wait type) --}}
            @if ($stepType !== 'wait')
                <div class="grid grid-cols-2 gap-3">
                    <flux:input
                        wire:model="stepDelayValue"
                        :label="__('Delay Before')"
                        type="number"
                        min="0"
                        :placeholder="__('0')"
                    />
                    <flux:select wire:model="stepDelayUnit" :label="__('Unit')">
                        <flux:select.option value="">{{ __('None') }}</flux:select.option>
                        <flux:select.option value="minutes">{{ __('Minutes') }}</flux:select.option>
                        <flux:select.option value="hours">{{ __('Hours') }}</flux:select.option>
                        <flux:select.option value="days">{{ __('Days') }}</flux:select.option>
                    </flux:select>
                </div>
            @endif

            {{-- Type-specific config --}}
            @switch($stepType)
                @case('sms')
                    <div class="space-y-3">
                        <flux:textarea
                            wire:model="smsBody"
                            :label="__('Message')"
                            :placeholder="__('Hi {first_name}, ...')"
                            rows="4"
                            required
                        />
                        <div class="flex flex-wrap gap-1">
                            <flux:badge size="sm" color="zinc" class="cursor-pointer" x-on:click="$wire.smsBody += '{first_name}'">{first_name}</flux:badge>
                            <flux:badge size="sm" color="zinc" class="cursor-pointer" x-on:click="$wire.smsBody += '{last_name}'">{last_name}</flux:badge>
                            <flux:badge size="sm" color="zinc" class="cursor-pointer" x-on:click="$wire.smsBody += '{company_name}'">{company_name}</flux:badge>
                            <flux:badge size="sm" color="zinc" class="cursor-pointer" x-on:click="$wire.smsBody += '{phone}'">{phone}</flux:badge>
                            <flux:badge size="sm" color="zinc" class="cursor-pointer" x-on:click="$wire.smsBody += '{email}'">{email}</flux:badge>
                        </div>
                        <flux:input
                            wire:model="smsMediaUrl"
                            :label="__('Media URL (optional)')"
                            :placeholder="__('https://example.com/image.jpg')"
                        />
                    </div>
                    @break

                @case('email')
                    <flux:input
                        wire:model="emailSubject"
                        :label="__('Subject')"
                        :placeholder="__('Follow-up from {company_name}')"
                        required
                    />
                    <flux:textarea
                        wire:model="emailBody"
                        :label="__('Body')"
                        :placeholder="__('Hi {first_name}, ...')"
                        rows="6"
                        required
                    />
                    <div class="flex flex-wrap gap-1">
                        <flux:badge size="sm" color="zinc" class="cursor-pointer" x-on:click="$wire.emailBody += '{first_name}'">{first_name}</flux:badge>
                        <flux:badge size="sm" color="zinc" class="cursor-pointer" x-on:click="$wire.emailBody += '{last_name}'">{last_name}</flux:badge>
                        <flux:badge size="sm" color="zinc" class="cursor-pointer" x-on:click="$wire.emailBody += '{company_name}'">{company_name}</flux:badge>
                    </div>
                    @break

                @case('rvm')
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                        <flux:text class="text-amber-700 dark:text-amber-400">
                            {{ __('Ringless voicemail configuration will be available in Phase 8.') }}
                        </flux:text>
                    </div>
                    @break

                @case('call')
                    <flux:textarea
                        wire:model="callScript"
                        :label="__('Call Script')"
                        :placeholder="__('Agent talking points for this call...')"
                        rows="4"
                    />
                    @break

                @case('wait')
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input
                            wire:model="waitValue"
                            :label="__('Wait Duration')"
                            type="number"
                            min="1"
                            required
                        />
                        <flux:select wire:model="waitUnit" :label="__('Unit')" required>
                            <flux:select.option value="minutes">{{ __('Minutes') }}</flux:select.option>
                            <flux:select.option value="hours">{{ __('Hours') }}</flux:select.option>
                            <flux:select.option value="days">{{ __('Days') }}</flux:select.option>
                        </flux:select>
                    </div>
                    @break

                @case('condition')
                    <div class="space-y-3">
                        <flux:select wire:model="condField" :label="__('Field')" required>
                            <flux:select.option value="last_disposition">{{ __('Last Disposition') }}</flux:select.option>
                            <flux:select.option value="tags">{{ __('Tags') }}</flux:select.option>
                            <flux:select.option value="do_not_call">{{ __('Do Not Call') }}</flux:select.option>
                            <flux:select.option value="total_calls">{{ __('Total Calls') }}</flux:select.option>
                            <flux:select.option value="total_sms">{{ __('Total SMS') }}</flux:select.option>
                            <flux:select.option value="city">{{ __('City') }}</flux:select.option>
                            <flux:select.option value="state">{{ __('State') }}</flux:select.option>
                        </flux:select>

                        <flux:select wire:model="condOperator" :label="__('Operator')" required>
                            <flux:select.option value="equals">{{ __('Equals') }}</flux:select.option>
                            <flux:select.option value="not_equals">{{ __('Not Equals') }}</flux:select.option>
                            <flux:select.option value="contains">{{ __('Contains') }}</flux:select.option>
                            <flux:select.option value="not_contains">{{ __('Not Contains') }}</flux:select.option>
                            <flux:select.option value="greater_than">{{ __('Greater Than') }}</flux:select.option>
                            <flux:select.option value="less_than">{{ __('Less Than') }}</flux:select.option>
                            <flux:select.option value="is_empty">{{ __('Is Empty') }}</flux:select.option>
                            <flux:select.option value="is_not_empty">{{ __('Is Not Empty') }}</flux:select.option>
                        </flux:select>

                        <flux:input
                            wire:model="condValue"
                            :label="__('Value')"
                            :placeholder="__('e.g., interested')"
                        />
                    </div>
                    @break
            @endswitch

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showStepModal', false)" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingStepId ? __('Update Step') : __('Add Step') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Step Confirmation --}}
    <flux:modal wire:model="showDeleteStepModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Step') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete this step? This cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteStepModal', false)" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteStep" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Enroll Contacts Modal --}}
    <flux:modal wire:model="showEnrollModal" class="max-w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Enroll Contacts') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Search and select contacts to enroll in this sequence.') }}</flux:text>
            </div>

            <flux:input
                wire:model.live.debounce.300ms="enrollSearch"
                icon="magnifying-glass"
                :placeholder="__('Search by name, phone, or email...')"
            />

            @if ($this->searchContacts->isNotEmpty())
                <div class="max-h-64 space-y-1 overflow-y-auto">
                    @foreach ($this->searchContacts as $contact)
                        <label wire:key="enroll-{{ $contact->id }}" class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 p-2 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                            <input
                                type="checkbox"
                                value="{{ $contact->id }}"
                                @checked(in_array($contact->id, $selectedContactIds))
                                wire:click="toggleContact({{ $contact->id }})"
                                class="rounded border-zinc-300"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">{{ $contact->getFullName() }}</div>
                                <div class="truncate text-xs text-zinc-500">{{ $contact->phone }} {{ $contact->email ? '- '.$contact->email : '' }}</div>
                            </div>
                        </label>
                    @endforeach
                </div>
            @elseif (strlen($enrollSearch) >= 2)
                <flux:text class="py-4 text-center">{{ __('No matching contacts found.') }}</flux:text>
            @else
                <flux:text class="py-4 text-center text-zinc-400">{{ __('Type at least 2 characters to search...') }}</flux:text>
            @endif

            @if (count($selectedContactIds) > 0)
                <flux:text class="text-sm font-medium">
                    {{ count($selectedContactIds) }} {{ __('contact(s) selected') }}
                </flux:text>
            @endif

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showEnrollModal', false)" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="enrollSelected" class="flex-1" :disabled="empty($selectedContactIds)">
                    {{ __('Enroll Selected') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
