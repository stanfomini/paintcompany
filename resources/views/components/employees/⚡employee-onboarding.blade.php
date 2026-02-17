<?php

use App\Models\EmployeeProfile;
use App\Services\EmployeeService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Employee Onboarding')]
class extends Component {
    public int $step = 1;

    // Step 1: Personal Info
    public string $phone = '';

    public string $dateOfBirth = '';

    // Step 2: Address
    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $state = '';

    public string $zipCode = '';

    // Step 3: Emergency Contact
    public string $emergencyContactName = '';

    public string $emergencyContactPhone = '';

    public string $emergencyContactRelation = '';

    public function mount(): void
    {
        $employee = auth()->user()->employeeProfile;

        // No employee profile - redirect to dashboard
        if (! $employee) {
            $this->redirect(route('dashboard'));

            return;
        }

        // Already onboarded - redirect to dashboard
        if ($employee->onboarding_completed_at) {
            $this->redirect(route('dashboard'));

            return;
        }

        // Pre-fill from existing profile data
        $this->phone = $employee->phone ?? '';
        $this->dateOfBirth = $employee->date_of_birth?->format('Y-m-d') ?? '';
        $this->addressLine1 = $employee->address_line_1 ?? '';
        $this->addressLine2 = $employee->address_line_2 ?? '';
        $this->city = $employee->city ?? '';
        $this->state = $employee->state ?? '';
        $this->zipCode = $employee->zip_code ?? '';
        $this->emergencyContactName = $employee->emergency_contact_name ?? '';
        $this->emergencyContactPhone = $employee->emergency_contact_phone ?? '';
        $this->emergencyContactRelation = $employee->emergency_contact_relation ?? '';
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'phone' => ['required', 'string', 'max:50'],
                'dateOfBirth' => ['required', 'date', 'before:today'],
            ]);
        }

        if ($this->step === 2) {
            $this->validate([
                'addressLine1' => ['required', 'string', 'max:255'],
                'addressLine2' => ['nullable', 'string', 'max:255'],
                'city' => ['required', 'string', 'max:100'],
                'state' => ['required', 'string', 'max:2'],
                'zipCode' => ['required', 'string', 'max:10'],
            ]);
        }

        if ($this->step === 3) {
            $this->validate([
                'emergencyContactName' => ['required', 'string', 'max:255'],
                'emergencyContactPhone' => ['required', 'string', 'max:50'],
                'emergencyContactRelation' => ['required', 'string', 'in:Spouse,Parent,Sibling,Friend,Other'],
            ]);
        }

        $this->step = min($this->step + 1, 4);
    }

    public function previousStep(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function completeOnboarding(): void
    {
        $employee = auth()->user()->employeeProfile;

        if (! $employee) {
            return;
        }

        $employee->update([
            'phone' => $this->phone,
            'date_of_birth' => $this->dateOfBirth,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2 ?: null,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zipCode,
            'emergency_contact_name' => $this->emergencyContactName,
            'emergency_contact_phone' => $this->emergencyContactPhone,
            'emergency_contact_relation' => $this->emergencyContactRelation,
        ]);

        app(EmployeeService::class)->initializeOnboarding($employee);

        session()->flash('success', __('Profile saved! Complete your onboarding tasks to get started.'));

        $this->redirect(route('my.onboarding'));
    }

    /**
     * @return array<string, string>
     */
    public function usStates(): array
    {
        return [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
            'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
            'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
            'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
            'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
            'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
            'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
            'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
            'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
            'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
            'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        ];
    }
};
?>

<div class="mx-auto max-w-2xl px-4 py-8">
    <div class="mb-8 text-center">
        <flux:heading size="xl" class="mb-2">{{ __('Welcome to the Team!') }}</flux:heading>
        <flux:text class="text-zinc-500">{{ __('Let\'s get your profile set up. This should only take a minute.') }}</flux:text>
    </div>

    {{-- Progress Steps --}}
    <div class="mb-8 flex items-center justify-center gap-2">
        @for ($i = 1; $i <= 4; $i++)
            <div class="flex items-center">
                <div class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium {{ $step >= $i ? 'bg-blue-500 text-white' : 'bg-zinc-200 text-zinc-500 dark:bg-zinc-700' }}">
                    @if ($step > $i)
                        <flux:icon name="check" class="h-4 w-4" />
                    @else
                        {{ $i }}
                    @endif
                </div>
                @if ($i < 4)
                    <div class="h-0.5 w-12 {{ $step > $i ? 'bg-blue-500' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
                @endif
            </div>
        @endfor
    </div>

    <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
        {{-- Step 1: Personal Info --}}
        @if ($step === 1)
            <flux:heading size="lg" class="mb-1">{{ __('Personal Info') }}</flux:heading>
            <flux:text size="sm" class="mb-6 text-zinc-500">{{ __('Confirm your basic information.') }}</flux:text>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Full Name') }}</flux:label>
                    <flux:input value="{{ auth()->user()->name }}" disabled />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Phone Number') }}</flux:label>
                    <flux:input wire:model="phone" placeholder="(555) 123-4567" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Date of Birth') }}</flux:label>
                    <flux:input wire:model="dateOfBirth" type="date" />
                    <flux:error name="dateOfBirth" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end">
                <flux:button variant="primary" wire:click="nextStep">{{ __('Continue') }}</flux:button>
            </div>
        @endif

        {{-- Step 2: Address --}}
        @if ($step === 2)
            <flux:heading size="lg" class="mb-1">{{ __('Address') }}</flux:heading>
            <flux:text size="sm" class="mb-6 text-zinc-500">{{ __('Where do you live?') }}</flux:text>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Address Line 1') }}</flux:label>
                    <flux:input wire:model="addressLine1" placeholder="123 Main St" />
                    <flux:error name="addressLine1" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Address Line 2') }}</flux:label>
                    <flux:input wire:model="addressLine2" placeholder="Apt, Suite, Unit (optional)" />
                    <flux:error name="addressLine2" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('City') }}</flux:label>
                        <flux:input wire:model="city" placeholder="Denver" />
                        <flux:error name="city" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('State') }}</flux:label>
                        <flux:select wire:model="state">
                            <option value="">{{ __('Select...') }}</option>
                            @foreach ($this->usStates() as $abbr => $name)
                                <option value="{{ $abbr }}">{{ $abbr }} - {{ $name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('ZIP Code') }}</flux:label>
                        <flux:input wire:model="zipCode" placeholder="80202" />
                        <flux:error name="zipCode" />
                    </flux:field>
                </div>
            </div>

            <div class="mt-6 flex justify-between">
                <flux:button variant="ghost" wire:click="previousStep">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" wire:click="nextStep">{{ __('Continue') }}</flux:button>
            </div>
        @endif

        {{-- Step 3: Emergency Contact --}}
        @if ($step === 3)
            <flux:heading size="lg" class="mb-1">{{ __('Emergency Contact') }}</flux:heading>
            <flux:text size="sm" class="mb-6 text-zinc-500">{{ __('Who should we contact in case of emergency?') }}</flux:text>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Contact Name') }}</flux:label>
                    <flux:input wire:model="emergencyContactName" placeholder="Jane Doe" />
                    <flux:error name="emergencyContactName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Contact Phone') }}</flux:label>
                    <flux:input wire:model="emergencyContactPhone" placeholder="(555) 987-6543" />
                    <flux:error name="emergencyContactPhone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Relationship') }}</flux:label>
                    <flux:select wire:model="emergencyContactRelation">
                        <option value="">{{ __('Select...') }}</option>
                        <option value="Spouse">{{ __('Spouse') }}</option>
                        <option value="Parent">{{ __('Parent') }}</option>
                        <option value="Sibling">{{ __('Sibling') }}</option>
                        <option value="Friend">{{ __('Friend') }}</option>
                        <option value="Other">{{ __('Other') }}</option>
                    </flux:select>
                    <flux:error name="emergencyContactRelation" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-between">
                <flux:button variant="ghost" wire:click="previousStep">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" wire:click="nextStep">{{ __('Continue') }}</flux:button>
            </div>
        @endif

        {{-- Step 4: Confirmation --}}
        @if ($step === 4)
            <flux:heading size="lg" class="mb-1">{{ __('Review & Confirm') }}</flux:heading>
            <flux:text size="sm" class="mb-6 text-zinc-500">{{ __('Please review your information before completing onboarding.') }}</flux:text>

            <div class="space-y-6">
                {{-- Personal Info Summary --}}
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Personal Info') }}</span>
                        <button wire:click="$set('step', 1)" class="text-sm text-blue-600 hover:underline dark:text-blue-400">{{ __('Edit') }}</button>
                    </div>
                    <div class="space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                        <div>{{ auth()->user()->name }}</div>
                        <div>{{ $phone }}</div>
                        @if ($dateOfBirth)
                            <div>{{ __('DOB:') }} {{ \Carbon\Carbon::parse($dateOfBirth)->format('M j, Y') }}</div>
                        @endif
                    </div>
                </div>

                {{-- Address Summary --}}
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Address') }}</span>
                        <button wire:click="$set('step', 2)" class="text-sm text-blue-600 hover:underline dark:text-blue-400">{{ __('Edit') }}</button>
                    </div>
                    <div class="space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                        <div>{{ $addressLine1 }}</div>
                        @if ($addressLine2)
                            <div>{{ $addressLine2 }}</div>
                        @endif
                        <div>{{ $city }}, {{ $state }} {{ $zipCode }}</div>
                    </div>
                </div>

                {{-- Emergency Contact Summary --}}
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Emergency Contact') }}</span>
                        <button wire:click="$set('step', 3)" class="text-sm text-blue-600 hover:underline dark:text-blue-400">{{ __('Edit') }}</button>
                    </div>
                    <div class="space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                        <div>{{ $emergencyContactName }} ({{ $emergencyContactRelation }})</div>
                        <div>{{ $emergencyContactPhone }}</div>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-between">
                <flux:button variant="ghost" wire:click="previousStep">{{ __('Back') }}</flux:button>
                <flux:button variant="primary" wire:click="completeOnboarding">{{ __('Complete Onboarding') }}</flux:button>
            </div>
        @endif
    </div>
</div>
