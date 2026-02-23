<?php

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('payroll.manage')) {
            abort(403);
        }
    }

    // Role-based rules
    public array $knockerRules = [];
    public array $estimatorRules = [];
    public array $managerRules = [];

    // Tiered brackets
    public string $tierPeriod = 'monthly';
    public array $tierBrackets = [];

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $defaults = CompanySetting::getInstance()->commission_defaults ?? [];

        $this->knockerRules = $defaults['knocker'] ?? [];
        $this->estimatorRules = $defaults['estimator'] ?? [];
        $this->managerRules = $defaults['manager_override'] ?? [];

        $tiers = $defaults['tiers'] ?? null;
        if ($tiers) {
            $this->tierPeriod = $tiers['period'] ?? 'monthly';
            $this->tierBrackets = $tiers['brackets'] ?? [];
        }
    }

    public function addRule(string $role): void
    {
        $blank = ['source' => '', 'type' => 'flat', 'amount' => 0, 'rate' => 0, 'auto_trigger' => true];

        match ($role) {
            'knocker' => $this->knockerRules[] = $blank,
            'estimator' => $this->estimatorRules[] = $blank,
            'manager' => $this->managerRules[] = $blank,
        };
    }

    public function removeRule(string $role, int $index): void
    {
        match ($role) {
            'knocker' => array_splice($this->knockerRules, $index, 1),
            'estimator' => array_splice($this->estimatorRules, $index, 1),
            'manager' => array_splice($this->managerRules, $index, 1),
        };
    }

    public function addBracket(): void
    {
        $lastMax = 0;
        if (! empty($this->tierBrackets)) {
            $last = end($this->tierBrackets);
            $lastMax = ($last['max'] ?? 0) + 1;
        }
        $this->tierBrackets[] = ['min' => $lastMax, 'max' => null, 'rate' => 0];
    }

    public function removeBracket(int $index): void
    {
        array_splice($this->tierBrackets, $index, 1);
    }

    public function save(): void
    {
        $this->validate([
            'knockerRules.*.source' => 'required|string',
            'knockerRules.*.type' => 'required|in:flat,revenue_percentage,profit_percentage',
            'estimatorRules.*.source' => 'required|string',
            'estimatorRules.*.type' => 'required|in:flat,revenue_percentage,profit_percentage',
            'managerRules.*.source' => 'required|string',
            'managerRules.*.type' => 'required|in:flat,revenue_percentage,profit_percentage',
            'tierPeriod' => 'required|in:monthly,quarterly,yearly',
            'tierBrackets.*.min' => 'required|numeric|min:0',
            'tierBrackets.*.rate' => 'required|numeric|min:0|max:1',
        ], [
            'knockerRules.*.source.required' => 'Source is required.',
            'estimatorRules.*.source.required' => 'Source is required.',
            'managerRules.*.source.required' => 'Source is required.',
            'tierBrackets.*.rate.max' => 'Rate must be a decimal (e.g. 0.08 for 8%).',
        ]);

        $defaults = [];

        if (! empty($this->knockerRules)) {
            $defaults['knocker'] = array_values($this->knockerRules);
        }
        if (! empty($this->estimatorRules)) {
            $defaults['estimator'] = array_values($this->estimatorRules);
        }
        if (! empty($this->managerRules)) {
            $defaults['manager_override'] = array_values($this->managerRules);
        }
        if (! empty($this->tierBrackets)) {
            $defaults['tiers'] = [
                'period' => $this->tierPeriod,
                'brackets' => array_values($this->tierBrackets),
            ];
        }

        CompanySetting::getInstance()->update(['commission_defaults' => $defaults]);

        session()->flash('commission-settings-saved', true);
    }

    #[Computed]
    public function sourceOptions(): array
    {
        return [
            'door_knock' => 'Door Knock (Appointment Set)',
            'lead' => 'Lead Assignment',
            'estimate' => 'Estimate Accepted',
            'job' => 'Job Completed',
            'territory_override' => 'Territory Override',
        ];
    }

    #[Computed]
    public function typeOptions(): array
    {
        return [
            'flat' => 'Flat Amount ($)',
            'revenue_percentage' => 'Revenue Percentage (%)',
            'profit_percentage' => 'Profit Percentage (%)',
        ];
    }
};

?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Commission Defaults')" :subheading="__('Set company-wide commission rules by territory role. Employees inherit these unless they have personal overrides.')" :fullWidth="true">
    @if (session('commission-settings-saved'))
        <flux:callout variant="success" class="mb-4">
            {{ __('Commission defaults saved successfully.') }}
        </flux:callout>
    @endif

    <div class="space-y-8">
        {{-- Door Knocker Rules --}}
        <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Door Knocker</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Rules applied to employees with the "knocker" territory role.</p>
                </div>
                <flux:button size="sm" wire:click="addRule('knocker')">+ Add Rule</flux:button>
            </div>

            @forelse ($knockerRules as $i => $rule)
                <div wire:key="knocker-{{ $i }}" class="mb-3 flex items-end gap-3 rounded-md bg-zinc-50 p-3 dark:bg-zinc-800">
                    <flux:field class="flex-1">
                        <flux:label>Source</flux:label>
                        <flux:select wire:model="knockerRules.{{ $i }}.source" size="sm">
                            <option value="">Select...</option>
                            @foreach ($this->sourceOptions as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    <flux:field class="w-44">
                        <flux:label>Type</flux:label>
                        <flux:select wire:model.live="knockerRules.{{ $i }}.type" size="sm">
                            @foreach ($this->typeOptions as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    @if (($knockerRules[$i]['type'] ?? 'flat') === 'flat')
                        <flux:field class="w-32">
                            <flux:label>Amount ($)</flux:label>
                            <flux:input type="number" wire:model="knockerRules.{{ $i }}.amount" size="sm" step="0.01" />
                        </flux:field>
                    @else
                        <flux:field class="w-32">
                            <flux:label>Rate (decimal)</flux:label>
                            <flux:input type="number" wire:model="knockerRules.{{ $i }}.rate" size="sm" step="0.01" placeholder="0.06" />
                        </flux:field>
                    @endif

                    <flux:field class="w-24">
                        <flux:label>Auto</flux:label>
                        <flux:switch wire:model="knockerRules.{{ $i }}.auto_trigger" />
                    </flux:field>

                    <flux:button variant="ghost" size="sm" wire:click="removeRule('knocker', {{ $i }})">
                        <flux:icon name="trash" class="size-4 text-red-500" />
                    </flux:button>
                </div>
            @empty
                <p class="text-sm text-zinc-400 dark:text-zinc-500">No rules configured. Click "+ Add Rule" to get started.</p>
            @endforelse
        </div>

        {{-- Estimator Rules --}}
        <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Estimator</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Rules applied to employees with the "estimator" territory role.</p>
                </div>
                <flux:button size="sm" wire:click="addRule('estimator')">+ Add Rule</flux:button>
            </div>

            @forelse ($estimatorRules as $i => $rule)
                <div wire:key="estimator-{{ $i }}" class="mb-3 flex items-end gap-3 rounded-md bg-zinc-50 p-3 dark:bg-zinc-800">
                    <flux:field class="flex-1">
                        <flux:label>Source</flux:label>
                        <flux:select wire:model="estimatorRules.{{ $i }}.source" size="sm">
                            <option value="">Select...</option>
                            @foreach ($this->sourceOptions as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    <flux:field class="w-44">
                        <flux:label>Type</flux:label>
                        <flux:select wire:model.live="estimatorRules.{{ $i }}.type" size="sm">
                            @foreach ($this->typeOptions as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    @if (($estimatorRules[$i]['type'] ?? 'flat') === 'flat')
                        <flux:field class="w-32">
                            <flux:label>Amount ($)</flux:label>
                            <flux:input type="number" wire:model="estimatorRules.{{ $i }}.amount" size="sm" step="0.01" />
                        </flux:field>
                    @else
                        <flux:field class="w-32">
                            <flux:label>Rate (decimal)</flux:label>
                            <flux:input type="number" wire:model="estimatorRules.{{ $i }}.rate" size="sm" step="0.01" placeholder="0.06" />
                        </flux:field>
                    @endif

                    <flux:field class="w-24">
                        <flux:label>Auto</flux:label>
                        <flux:switch wire:model="estimatorRules.{{ $i }}.auto_trigger" />
                    </flux:field>

                    <flux:button variant="ghost" size="sm" wire:click="removeRule('estimator', {{ $i }})">
                        <flux:icon name="trash" class="size-4 text-red-500" />
                    </flux:button>
                </div>
            @empty
                <p class="text-sm text-zinc-400 dark:text-zinc-500">No rules configured. Click "+ Add Rule" to get started.</p>
            @endforelse
        </div>

        {{-- Manager Override Rules --}}
        <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Territory Manager Override</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Rules applied to territory managers via hierarchy walk-up when door knock jobs complete.</p>
                </div>
                <flux:button size="sm" wire:click="addRule('manager')">+ Add Rule</flux:button>
            </div>

            @forelse ($managerRules as $i => $rule)
                <div wire:key="manager-{{ $i }}" class="mb-3 flex items-end gap-3 rounded-md bg-zinc-50 p-3 dark:bg-zinc-800">
                    <flux:field class="flex-1">
                        <flux:label>Source</flux:label>
                        <flux:select wire:model="managerRules.{{ $i }}.source" size="sm">
                            <option value="">Select...</option>
                            @foreach ($this->sourceOptions as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    <flux:field class="w-44">
                        <flux:label>Type</flux:label>
                        <flux:select wire:model.live="managerRules.{{ $i }}.type" size="sm">
                            @foreach ($this->typeOptions as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    @if (($managerRules[$i]['type'] ?? 'flat') === 'flat')
                        <flux:field class="w-32">
                            <flux:label>Amount ($)</flux:label>
                            <flux:input type="number" wire:model="managerRules.{{ $i }}.amount" size="sm" step="0.01" />
                        </flux:field>
                    @else
                        <flux:field class="w-32">
                            <flux:label>Rate (decimal)</flux:label>
                            <flux:input type="number" wire:model="managerRules.{{ $i }}.rate" size="sm" step="0.01" placeholder="0.02" />
                        </flux:field>
                    @endif

                    <flux:field class="w-24">
                        <flux:label>Auto</flux:label>
                        <flux:switch wire:model="managerRules.{{ $i }}.auto_trigger" />
                    </flux:field>

                    <flux:button variant="ghost" size="sm" wire:click="removeRule('manager', {{ $i }})">
                        <flux:icon name="trash" class="size-4 text-red-500" />
                    </flux:button>
                </div>
            @empty
                <p class="text-sm text-zinc-400 dark:text-zinc-500">No rules configured. Click "+ Add Rule" to get started.</p>
            @endforelse
        </div>

        {{-- Tiered Brackets --}}
        <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Tiered Brackets</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Graduated commission rates based on cumulative sales within a period.</p>
                </div>
                <flux:button size="sm" wire:click="addBracket">+ Add Bracket</flux:button>
            </div>

            <flux:field class="mb-4 w-48">
                <flux:label>Period</flux:label>
                <flux:select wire:model="tierPeriod" size="sm">
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="yearly">Yearly</option>
                </flux:select>
            </flux:field>

            @forelse ($tierBrackets as $i => $bracket)
                <div wire:key="bracket-{{ $i }}" class="mb-3 flex items-end gap-3 rounded-md bg-zinc-50 p-3 dark:bg-zinc-800">
                    <flux:field class="w-36">
                        <flux:label>Min ($)</flux:label>
                        <flux:input type="number" wire:model="tierBrackets.{{ $i }}.min" size="sm" step="1" />
                    </flux:field>

                    <flux:field class="w-36">
                        <flux:label>Max ($)</flux:label>
                        <flux:input type="number" wire:model="tierBrackets.{{ $i }}.max" size="sm" step="1" placeholder="No limit" />
                    </flux:field>

                    <flux:field class="w-32">
                        <flux:label>Rate (decimal)</flux:label>
                        <flux:input type="number" wire:model="tierBrackets.{{ $i }}.rate" size="sm" step="0.01" placeholder="0.08" />
                    </flux:field>

                    <span class="mb-1 text-sm text-zinc-500">
                        {{ number_format(($bracket['rate'] ?? 0) * 100, 1) }}%
                    </span>

                    <flux:button variant="ghost" size="sm" wire:click="removeBracket({{ $i }})">
                        <flux:icon name="trash" class="size-4 text-red-500" />
                    </flux:button>
                </div>
            @empty
                <p class="text-sm text-zinc-400 dark:text-zinc-500">No tiered brackets configured.</p>
            @endforelse
        </div>

        {{-- Save Button --}}
        <div class="flex justify-end">
            <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ __('Save Commission Defaults') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
            </flux:button>
        </div>
    </div>
    </x-pages::settings.layout>
</section>
