<?php

use App\Models\CommissionEntry;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Models\PayStub;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('My Profile')]
class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->employeeProfile) {
            abort(403);
        }
    }

    public string $activeTab = 'personal';

    // Editable fields
    public string $phone = '';
    public string $email = '';
    public string $address_line_1 = '';
    public string $address_line_2 = '';
    public string $city = '';
    public string $state = '';
    public string $zip_code = '';
    public string $emergency_contact_name = '';
    public string $emergency_contact_phone = '';
    public string $emergency_contact_relation = '';

    public function mount(): void
    {
        $profile = auth()->user()->employeeProfile;
        $this->phone = $profile->phone ?? '';
        $this->email = auth()->user()->email ?? '';
        $this->address_line_1 = $profile->address_line_1 ?? '';
        $this->address_line_2 = $profile->address_line_2 ?? '';
        $this->city = $profile->city ?? '';
        $this->state = $profile->state ?? '';
        $this->zip_code = $profile->zip_code ?? '';
        $this->emergency_contact_name = $profile->emergency_contact_name ?? '';
        $this->emergency_contact_phone = $profile->emergency_contact_phone ?? '';
        $this->emergency_contact_relation = $profile->emergency_contact_relation ?? '';
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function savePersonalInfo(): void
    {
        $this->validate([
            'phone' => 'nullable|string|max:20',
            'email' => 'required|email|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:2',
            'zip_code' => 'nullable|string|max:10',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'emergency_contact_relation' => 'nullable|string|max:50',
        ]);

        auth()->user()->employeeProfile->update([
            'phone' => $this->phone,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'emergency_contact_relation' => $this->emergency_contact_relation,
        ]);

        auth()->user()->update(['email' => $this->email]);

        session()->flash('success', __('Profile updated successfully.'));
    }

    #[Computed]
    public function profile(): EmployeeProfile
    {
        return auth()->user()->employeeProfile;
    }

    #[Computed]
    public function contracts()
    {
        return EmployeeContract::query()
            ->where('employee_profile_id', $this->profile->id)
            ->latest()
            ->get();
    }

    #[Computed]
    public function ytdTotals(): array
    {
        $stubs = PayStub::query()
            ->where('employee_profile_id', $this->profile->id)
            ->whereHas('payPeriod', fn ($q) => $q->whereYear('start_date', now()->year))
            ->get();

        return [
            'gross' => $stubs->sum('gross_pay'),
            'taxes' => $stubs->sum('federal_tax') + $stubs->sum('state_tax') + $stubs->sum('social_security') + $stubs->sum('medicare'),
            'deductions' => $stubs->sum('total_deductions'),
            'net' => $stubs->sum('net_pay'),
        ];
    }
};
?>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="mb-6">
        <flux:heading size="xl">{{ __('My Profile') }}</flux:heading>
        <flux:text class="mt-1">{{ __('View and manage your personal information.') }}</flux:text>
    </div>

    {{-- Tabs --}}
    <div class="mb-6 border-b border-zinc-200 dark:border-zinc-700">
        <nav class="-mb-px flex gap-6 overflow-x-auto">
            @foreach (['personal' => __('Personal Info'), 'documents' => __('Documents & Contracts'), 'pay' => __('Pay & Compensation'), 'certifications' => __('Certifications')] as $tab => $label)
                <button
                    wire:click="setTab('{{ $tab }}')"
                    class="border-b-2 px-1 py-4 text-sm font-medium whitespace-nowrap {{ $activeTab === $tab ? 'border-zinc-800 text-zinc-900 dark:border-zinc-200 dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab: Personal Info --}}
    @if ($activeTab === 'personal')
        <div class="space-y-6">
            {{-- View-only Employee Details --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">{{ __('Employee Details') }}</flux:heading>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Full Name') }}</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ auth()->user()->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Employee Number') }}</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $this->profile->employee_number ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Date of Birth') }}</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $this->profile->date_of_birth?->format('M j, Y') ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('SSN') }}</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">
                            @if ($this->profile->ssn)
                                ***-**-{{ substr($this->profile->ssn, -4) }}
                            @else
                                -
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Hire Date') }}</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $this->profile->hire_date?->format('M j, Y') ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Department') }}</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ ucfirst($this->profile->department ?? '-') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Job Title') }}</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $this->profile->job_title ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Pay Rate') }}</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $this->profile->getPayRateLabel() }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Editable Contact Info --}}
            <form wire:submit="savePersonalInfo">
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="lg" class="mb-4">{{ __('Contact Information') }}</flux:heading>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="email" :label="__('Email')" type="email" required />
                        <flux:input wire:model="phone" :label="__('Phone')" />
                    </div>
                </div>

                <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="lg" class="mb-4">{{ __('Address') }}</flux:heading>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <flux:input wire:model="address_line_1" :label="__('Address Line 1')" />
                        </div>
                        <div class="sm:col-span-2">
                            <flux:input wire:model="address_line_2" :label="__('Address Line 2')" />
                        </div>
                        <flux:input wire:model="city" :label="__('City')" />
                        <div class="grid grid-cols-2 gap-4">
                            <flux:input wire:model="state" :label="__('State')" maxlength="2" />
                            <flux:input wire:model="zip_code" :label="__('ZIP Code')" />
                        </div>
                    </div>
                </div>

                <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="lg" class="mb-4">{{ __('Emergency Contact') }}</flux:heading>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="emergency_contact_name" :label="__('Name')" />
                        <flux:input wire:model="emergency_contact_phone" :label="__('Phone')" />
                        <flux:input wire:model="emergency_contact_relation" :label="__('Relationship')" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <flux:button type="submit" variant="primary">
                        {{ __('Save Changes') }}
                    </flux:button>
                </div>
            </form>
        </div>
    @endif

    {{-- Tab: Documents & Contracts --}}
    @if ($activeTab === 'documents')
        <div>
            @if ($this->contracts->isEmpty())
                <div class="rounded-xl border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="document-text" class="mx-auto mb-3 size-10 text-zinc-400 dark:text-zinc-500" />
                    <flux:heading size="lg">{{ __('No Contracts') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Your employment contracts will appear here.') }}</flux:text>
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($this->contracts as $contract)
                        <div wire:key="contract-{{ $contract->id }}" class="flex items-center justify-between rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $contract->title }}</div>
                                <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    @if ($contract->signed_at)
                                        {{ __('Signed') }} {{ $contract->signed_at->format('M j, Y') }}
                                    @elseif ($contract->sent_at)
                                        {{ __('Sent') }} {{ $contract->sent_at->format('M j, Y') }}
                                    @else
                                        {{ __('Created') }} {{ $contract->created_at->format('M j, Y') }}
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                @if ($contract->isSigned())
                                    <flux:badge color="green" size="sm">{{ __('Signed') }}</flux:badge>
                                @elseif ($contract->isExpired())
                                    <flux:badge color="red" size="sm">{{ __('Expired') }}</flux:badge>
                                @else
                                    <flux:badge color="amber" size="sm">{{ ucfirst($contract->status) }}</flux:badge>
                                @endif

                                @if ($contract->isSigned())
                                    <flux:button variant="subtle" size="sm" :href="route('contracts.show', $contract)" wire:navigate>
                                        {{ __('View') }}
                                    </flux:button>
                                @elseif (! $contract->isExpired() && $contract->token)
                                    <flux:button variant="primary" size="sm" :href="route('contracts.sign', $contract->token)" wire:navigate>
                                        {{ __('Sign') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Tab: Pay & Compensation --}}
    @if ($activeTab === 'pay')
        <div class="space-y-6">
            {{-- Current Pay --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">{{ __('Current Compensation') }}</flux:heading>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Pay Rate') }}</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $this->profile->getPayRateLabel() }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Pay Type') }}</dt>
                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ \App\Models\EmployeeProfile::PAY_TYPES[$this->profile->pay_type] ?? ucfirst($this->profile->pay_type ?? '-') }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Commission Rules --}}
            @if ($this->profile->hasCommissionRules())
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="lg" class="mb-4">{{ __('Commission Rules') }}</flux:heading>
                    <div class="space-y-2">
                        @foreach ($this->profile->commission_rules as $rule)
                            <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-700/50">
                                <div class="text-sm text-zinc-900 dark:text-zinc-100">
                                    <span class="font-medium">{{ ucfirst($rule['source'] ?? '') }}</span>
                                    &mdash;
                                    @if (($rule['type'] ?? '') === 'flat')
                                        ${{ number_format($rule['amount'] ?? 0, 2) }} {{ __('flat') }}
                                    @elseif (str_contains($rule['type'] ?? '', 'percentage'))
                                        {{ number_format(($rule['rate'] ?? 0) * 100, 1) }}% {{ str_replace('_', ' ', $rule['type'] ?? '') }}
                                    @else
                                        {{ str_replace('_', ' ', $rule['type'] ?? '') }}
                                    @endif
                                </div>
                                <flux:badge :color="($rule['active'] ?? true) ? 'green' : 'zinc'" size="sm">
                                    {{ ($rule['active'] ?? true) ? __('Active') : __('Inactive') }}
                                </flux:badge>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- YTD Earnings --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">{{ __('YTD Earnings') }} ({{ now()->year }})</flux:heading>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Gross') }}</div>
                        <div class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->ytdTotals['gross'], 2) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Taxes') }}</div>
                        <div class="mt-1 text-xl font-bold text-red-600 dark:text-red-400">${{ number_format($this->ytdTotals['taxes'], 2) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Deductions') }}</div>
                        <div class="mt-1 text-xl font-bold text-red-600 dark:text-red-400">${{ number_format($this->ytdTotals['deductions'], 2) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Net') }}</div>
                        <div class="mt-1 text-xl font-bold text-green-700 dark:text-green-400">${{ number_format($this->ytdTotals['net'], 2) }}</div>
                    </div>
                </div>
            </div>

            <div>
                <flux:button variant="subtle" :href="route('my.pay-stubs')" wire:navigate>
                    {{ __('View All Pay Stubs') }} &rarr;
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Tab: Certifications --}}
    @if ($activeTab === 'certifications')
        <div class="space-y-6">
            {{-- Lead Safe Certification --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">{{ __('Lead Safe Certification') }}</flux:heading>
                @if ($this->profile->lead_safe_cert_number)
                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Certificate Number') }}</dt>
                            <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">{{ $this->profile->lead_safe_cert_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Expiration') }}</dt>
                            <dd class="mt-1 flex items-center gap-2 text-sm text-zinc-900 dark:text-zinc-100">
                                {{ $this->profile->lead_safe_cert_expiration?->format('M j, Y') ?? '-' }}
                                @if ($this->profile->isLeadSafeCertExpired())
                                    <flux:badge color="red" size="sm">{{ __('Expired') }}</flux:badge>
                                @elseif ($this->profile->isLeadSafeCertExpiringSoon())
                                    <flux:badge color="amber" size="sm">{{ __('Expiring Soon') }}</flux:badge>
                                @else
                                    <flux:badge color="green" size="sm">{{ __('Valid') }}</flux:badge>
                                @endif
                            </dd>
                        </div>
                    </dl>
                @else
                    <flux:text>{{ __('No lead safe certification on file.') }}</flux:text>
                @endif
            </div>

            {{-- Onboarding Status --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">{{ __('Onboarding') }}</flux:heading>
                <div class="flex items-center gap-3">
                    @if ($this->profile->isOnboardingComplete())
                        <flux:badge color="green" size="sm">{{ __('Completed') }}</flux:badge>
                        <flux:text>{{ __('Completed on') }} {{ $this->profile->onboarding_completed_at->format('M j, Y') }}</flux:text>
                    @else
                        <flux:badge color="amber" size="sm">{{ __('In Progress') }}</flux:badge>
                        <flux:button variant="subtle" size="sm" :href="route('my.onboarding')" wire:navigate>
                            {{ __('View Progress') }} &rarr;
                        </flux:button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
