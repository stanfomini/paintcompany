<?php

use App\Models\Applicant;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public bool $show = false;

    public ?Applicant $applicant = null;

    public string $job_title = '';

    public string $department = '';

    public string $pay_rate = '';

    public string $pay_type = 'hourly';

    public string $password = '';

    #[On('open-hire-modal')]
    public function open(int $applicantId): void
    {
        $this->reset(['job_title', 'department', 'pay_rate', 'pay_type', 'password']);
        $this->resetValidation();

        $this->applicant = Applicant::with('jobListing')->findOrFail($applicantId);

        // Pre-fill from offer if exists
        if ($this->applicant->offer_amount) {
            $this->pay_rate = (string) $this->applicant->offer_amount;
            $this->pay_type = $this->applicant->offer_type ?? 'hourly';
        }

        // Pre-fill job title from listing
        if ($this->applicant->jobListing) {
            $this->job_title = $this->applicant->jobListing->title;
        }

        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
        $this->applicant = null;
    }

    public function hire(): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $this->validate([
            'job_title' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'pay_rate' => [$this->pay_type === 'commission' ? 'nullable' : 'required', 'numeric', 'min:0'],
            'pay_type' => ['required', 'in:'.implode(',', array_keys(EmployeeProfile::PAY_TYPES))],
            'password' => ['required', Password::defaults()],
        ]);

        if (User::where('email', $this->applicant->email)->exists()) {
            $this->addError('password', __('A user with this email already exists.'));

            return;
        }

        app(EmployeeService::class)->hireApplicant($this->applicant, [
            'job_title' => $this->job_title,
            'department' => $this->department ?: null,
            'pay_rate' => $this->pay_rate,
            'pay_type' => $this->pay_type,
            'password' => $this->password,
        ]);

        $this->show = false;
        $this->dispatch('applicant-updated');
        session()->flash('success', __(':name has been hired successfully.', ['name' => $this->applicant->getFullName()]));
    }
}; ?>

<div>
    <flux:modal wire:model="show" class="max-w-lg">
        @if ($applicant)
            <form wire:submit="hire">
                <flux:heading size="lg" class="mb-1">{{ __('Hire Applicant') }}</flux:heading>
                <flux:text class="mb-6">{{ __('Convert :name into an employee with a user account.', ['name' => $applicant->getFullName()]) }}</flux:text>

                {{-- Applicant Summary --}}
                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4 mb-6">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <flux:text class="text-zinc-500">{{ __('Name') }}</flux:text>
                            <flux:text class="font-medium">{{ $applicant->getFullName() }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-zinc-500">{{ __('Email') }}</flux:text>
                            <flux:text class="font-medium">{{ $applicant->email }}</flux:text>
                        </div>
                        @if ($applicant->phone)
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Phone') }}</flux:text>
                                <flux:text class="font-medium">{{ $applicant->phone }}</flux:text>
                            </div>
                        @endif
                        @if ($applicant->jobListing)
                            <div>
                                <flux:text class="text-zinc-500">{{ __('Position') }}</flux:text>
                                <flux:text class="font-medium">{{ $applicant->jobListing->title }}</flux:text>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="space-y-4">
                    <flux:heading size="sm">{{ __('Employment Details') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('Job Title') }}</flux:label>
                        <flux:input wire:model="job_title" required />
                        <flux:error name="job_title" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Department') }}</flux:label>
                        <flux:input wire:model="department" placeholder="Operations" />
                        <flux:error name="department" />
                    </flux:field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Pay Rate') }}</flux:label>
                            <flux:input wire:model="pay_rate" type="number" step="0.01" min="0" icon="currency-dollar" :required="$pay_type !== 'commission'" />
                            <flux:error name="pay_rate" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Pay Type') }}</flux:label>
                            <flux:select wire:model.live="pay_type">
                                @foreach (\App\Models\EmployeeProfile::PAY_TYPES as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="pay_type" />
                        </flux:field>
                    </div>

                    <flux:separator />

                    <flux:heading size="sm">{{ __('Account Password') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('Password') }}</flux:label>
                        <flux:input wire:model="password" type="password" required />
                        <flux:error name="password" />
                        <flux:description>{{ __('The employee will use this password to sign in.') }}</flux:description>
                    </flux:field>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <flux:button variant="ghost" wire:click="close" type="button">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="submit" variant="primary">
                        {{ __('Hire Employee') }}
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
