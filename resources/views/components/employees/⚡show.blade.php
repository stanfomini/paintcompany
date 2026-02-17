<?php

use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\PayStub;
use App\Models\Schedule;
use App\Models\TimeEntry;
use App\Notifications\DocumentRejected;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('layouts.app')]
#[Title('Employee Details')]
class extends Component
{
    use WithFileUploads;

    public function boot(): void
    {
        if (! auth()->user()?->can('employees.view')) {
            abort(403);
        }
    }

    public EmployeeProfile $employeeProfile;

    public string $activeTab = 'overview';

    // Edit profile modal
    public bool $showEditModal = false;

    public string $editName = '';

    public string $editJobTitle = '';

    public string $editDepartment = '';

    public string $editPayRate = '';

    public string $editPayType = 'hourly';

    public string $editPhone = '';

    public string $editStatus = 'active';

    // Address fields
    public string $editAddressLine1 = '';

    public string $editAddressLine2 = '';

    public string $editCity = '';

    public string $editState = '';

    public string $editZipCode = '';

    // Emergency contact fields
    public string $editEmergencyContactName = '';

    public string $editEmergencyContactPhone = '';

    public string $editEmergencyContactRelation = '';

    // Notes
    public string $editNotes = '';

    // Commission rules
    /** @var array<int, array{source: string, type: string, rate: string, amount: string, active: bool, auto_trigger: bool}> */
    public array $commissionRules = [];

    // Document review
    public bool $showRejectModal = false;
    public ?int $rejectDocumentId = null;
    public string $rejectNotes = '';

    // Document upload
    public bool $showUploadModal = false;

    public string $docName = '';

    public string $docType = 'other';

    public string $docNotes = '';

    public string $docExpiresAt = '';

    public $docFile = null;

    public function mount(EmployeeProfile $employeeProfile): void
    {
        $this->employeeProfile = $employeeProfile->load('user');
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;

        if ($tab === 'notes') {
            $this->editNotes = $this->employeeProfile->notes ?? '';
        }

        if ($tab === 'commission') {
            $this->loadCommissionRules();
        }
    }

    public function loadCommissionRules(): void
    {
        $rules = $this->employeeProfile->commission_rules ?? [];
        $this->commissionRules = collect($rules)->map(fn (array $rule) => [
            'source' => $rule['source'] ?? 'lead',
            'type' => $rule['type'] ?? 'flat',
            'rate' => (string) ($rule['rate'] ?? ''),
            'amount' => (string) ($rule['amount'] ?? ''),
            'active' => $rule['active'] ?? true,
            'auto_trigger' => $rule['auto_trigger'] ?? true,
        ])->values()->all();
    }

    public function addCommissionRule(): void
    {
        abort_unless(auth()->user()->can('payroll.manage'), 403);

        $this->commissionRules[] = [
            'source' => 'lead',
            'type' => 'flat',
            'rate' => '',
            'amount' => '25',
            'active' => true,
            'auto_trigger' => true,
        ];
    }

    public function removeCommissionRule(int $index): void
    {
        abort_unless(auth()->user()->can('payroll.manage'), 403);

        unset($this->commissionRules[$index]);
        $this->commissionRules = array_values($this->commissionRules);
    }

    public function toggleCommissionRule(int $index): void
    {
        abort_unless(auth()->user()->can('payroll.manage'), 403);

        if (isset($this->commissionRules[$index])) {
            $this->commissionRules[$index]['active'] = ! $this->commissionRules[$index]['active'];
        }
    }

    public function saveCommissionRules(): void
    {
        abort_unless(auth()->user()->can('payroll.manage'), 403);

        $this->validate([
            'commissionRules.*.source' => 'required|in:lead,estimate,job',
            'commissionRules.*.type' => 'required|in:flat,revenue_percentage,profit_percentage,tiered',
            'commissionRules.*.rate' => 'nullable|numeric|min:0',
            'commissionRules.*.amount' => 'nullable|numeric|min:0',
        ]);

        $rules = collect($this->commissionRules)->map(fn (array $rule) => [
            'source' => $rule['source'],
            'type' => $rule['type'],
            'rate' => $rule['rate'] !== '' ? (float) $rule['rate'] : null,
            'amount' => $rule['amount'] !== '' ? (float) $rule['amount'] : null,
            'active' => (bool) $rule['active'],
            'auto_trigger' => (bool) ($rule['auto_trigger'] ?? true),
        ])->values()->all();

        $this->employeeProfile->update(['commission_rules' => $rules ?: null]);
        $this->employeeProfile->refresh();
        session()->flash('success', __('Commission rules saved successfully.'));
    }

    public function openEditModal(): void
    {
        $profile = $this->employeeProfile;

        $this->editName = $profile->user->name;
        $this->editJobTitle = $profile->job_title ?? '';
        $this->editDepartment = $profile->department ?? '';
        $this->editPayRate = $profile->pay_rate ?? '';
        $this->editPayType = $profile->pay_type ?? 'hourly';
        $this->editPhone = $profile->phone ?? '';
        $this->editStatus = $profile->employment_status ?? 'active';
        $this->editAddressLine1 = $profile->address_line_1 ?? '';
        $this->editAddressLine2 = $profile->address_line_2 ?? '';
        $this->editCity = $profile->city ?? '';
        $this->editState = $profile->state ?? '';
        $this->editZipCode = $profile->zip_code ?? '';
        $this->editEmergencyContactName = $profile->emergency_contact_name ?? '';
        $this->editEmergencyContactPhone = $profile->emergency_contact_phone ?? '';
        $this->editEmergencyContactRelation = $profile->emergency_contact_relation ?? '';

        $this->showEditModal = true;
    }

    public function saveProfile(): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $this->validate([
            'editName' => 'required|string|max:255',
            'editJobTitle' => 'nullable|string|max:255',
            'editDepartment' => 'nullable|string|max:255',
            'editPayRate' => ($this->editPayType === 'commission' ? 'nullable' : 'required').'|numeric|min:0',
            'editPayType' => 'required|in:'.implode(',', array_keys(EmployeeProfile::PAY_TYPES)),
            'editPhone' => 'nullable|string|max:20',
            'editStatus' => 'required|in:active,on_leave,terminated',
            'editAddressLine1' => 'nullable|string|max:255',
            'editAddressLine2' => 'nullable|string|max:255',
            'editCity' => 'nullable|string|max:255',
            'editState' => 'nullable|string|max:2',
            'editZipCode' => 'nullable|string|max:10',
            'editEmergencyContactName' => 'nullable|string|max:255',
            'editEmergencyContactPhone' => 'nullable|string|max:20',
            'editEmergencyContactRelation' => 'nullable|string|max:255',
        ]);

        $this->employeeProfile->user->update(['name' => $this->editName]);

        $data = [
            'job_title' => $this->editJobTitle ?: null,
            'department' => $this->editDepartment ?: null,
            'pay_rate' => $this->editPayRate ?: null,
            'pay_type' => $this->editPayType,
            'phone' => $this->editPhone ?: null,
            'employment_status' => $this->editStatus,
            'address_line_1' => $this->editAddressLine1 ?: null,
            'address_line_2' => $this->editAddressLine2 ?: null,
            'city' => $this->editCity ?: null,
            'state' => $this->editState ?: null,
            'zip_code' => $this->editZipCode ?: null,
            'emergency_contact_name' => $this->editEmergencyContactName ?: null,
            'emergency_contact_phone' => $this->editEmergencyContactPhone ?: null,
            'emergency_contact_relation' => $this->editEmergencyContactRelation ?: null,
        ];

        if ($this->editStatus === 'terminated' && $this->employeeProfile->employment_status !== 'terminated') {
            $data['termination_date'] = now()->toDateString();
            $this->employeeProfile->user->update(['is_active' => false]);
        } elseif ($this->editStatus === 'active' && $this->employeeProfile->employment_status === 'terminated') {
            $data['termination_date'] = null;
            $this->employeeProfile->user->update(['is_active' => true]);
        }

        $this->employeeProfile->update($data);
        $this->employeeProfile->refresh();
        $this->employeeProfile->load('user');
        $this->showEditModal = false;
        session()->flash('success', __('Employee profile updated successfully.'));
    }

    public function saveNotes(): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $this->validate([
            'editNotes' => 'nullable|string|max:5000',
        ]);

        $this->employeeProfile->update(['notes' => $this->editNotes ?: null]);
        $this->employeeProfile->refresh();
        session()->flash('success', __('Notes saved successfully.'));
    }

    public function setStatus(string $status): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $data = ['employment_status' => $status];

        if ($status === 'terminated') {
            $data['termination_date'] = now()->toDateString();
            $this->employeeProfile->user->update(['is_active' => false]);
        } elseif ($status === 'active') {
            $data['termination_date'] = null;
            $this->employeeProfile->user->update(['is_active' => true]);
        }

        $this->employeeProfile->update($data);
        $this->employeeProfile->refresh();
        $this->employeeProfile->load('user');
        session()->flash('success', __('Employment status updated.'));
    }

    #[On('contract-assigned')]
    public function refreshContracts(): void
    {
        unset($this->contracts);
    }

    public function openUploadModal(): void
    {
        $this->docName = '';
        $this->docType = 'other';
        $this->docNotes = '';
        $this->docExpiresAt = '';
        $this->docFile = null;
        $this->showUploadModal = true;
    }

    public function uploadDocument(): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $this->validate([
            'docName' => 'required|string|max:255',
            'docType' => 'required|in:'.implode(',', array_keys(EmployeeDocument::TYPES)),
            'docFile' => 'required|file|max:10240',
            'docNotes' => 'nullable|string|max:1000',
            'docExpiresAt' => 'nullable|date',
        ]);

        $path = $this->docFile->store('employee-documents', config('filesystems.default'));

        EmployeeDocument::create([
            'employee_profile_id' => $this->employeeProfile->id,
            'uploaded_by' => auth()->id(),
            'name' => $this->docName,
            'type' => $this->docType,
            'file_path' => $path,
            'notes' => $this->docNotes ?: null,
            'expires_at' => $this->docExpiresAt ?: null,
        ]);

        $this->showUploadModal = false;
        unset($this->employeeDocuments);
        session()->flash('success', __('Document uploaded successfully.'));
    }

    public function downloadDocument(int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $document = EmployeeDocument::where('employee_profile_id', $this->employeeProfile->id)
            ->findOrFail($id);

        return \Illuminate\Support\Facades\Storage::download(
            $document->file_path,
            $document->name.'.'.\Illuminate\Support\Facades\Storage::mimeType($document->file_path) ? pathinfo($document->file_path, PATHINFO_EXTENSION) : 'pdf'
        );
    }

    public function deleteDocument(int $id): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $document = EmployeeDocument::where('employee_profile_id', $this->employeeProfile->id)
            ->findOrFail($id);

        \Illuminate\Support\Facades\Storage::delete($document->file_path);
        $document->delete();

        unset($this->employeeDocuments);
        session()->flash('success', __('Document deleted.'));
    }

    public function approveDocument(int $id): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $document = EmployeeDocument::where('employee_profile_id', $this->employeeProfile->id)
            ->findOrFail($id);

        $document->approve(auth()->user());

        unset($this->employeeDocuments);
        session()->flash('success', __('Document approved.'));
    }

    public function openRejectModal(int $id): void
    {
        $this->rejectDocumentId = $id;
        $this->rejectNotes = '';
        $this->showRejectModal = true;
    }

    public function rejectDocument(): void
    {
        abort_unless(auth()->user()->can('employees.edit'), 403);

        $this->validate([
            'rejectNotes' => 'required|string|max:1000',
        ]);

        $document = EmployeeDocument::where('employee_profile_id', $this->employeeProfile->id)
            ->findOrFail($this->rejectDocumentId);

        $document->reject(auth()->user(), $this->rejectNotes);

        // Notify the employee
        $employee = $this->employeeProfile->user;
        if ($employee) {
            $employee->notify(new DocumentRejected($document));
        }

        $this->showRejectModal = false;
        $this->rejectDocumentId = null;
        $this->rejectNotes = '';
        unset($this->employeeDocuments);
        session()->flash('success', __('Document rejected. The employee has been notified.'));
    }

    #[Computed]
    public function employeeDocuments()
    {
        return EmployeeDocument::where('employee_profile_id', $this->employeeProfile->id)
            ->with(['uploader', 'reviewer'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function contracts()
    {
        return EmployeeContract::where('employee_profile_id', $this->employeeProfile->id)
            ->with('template')
            ->latest()
            ->get();
    }

    #[Computed]
    public function recentTimeEntries()
    {
        return TimeEntry::where('employee_profile_id', $this->employeeProfile->id)
            ->latest('clock_in')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function upcomingSchedules()
    {
        return Schedule::where('employee_profile_id', $this->employeeProfile->id)
            ->where('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->limit(14)
            ->get();
    }

    #[Computed]
    public function payStubs()
    {
        return PayStub::where('employee_profile_id', $this->employeeProfile->id)
            ->with('payPeriod')
            ->latest()
            ->get();
    }

    #[Computed]
    public function statusColor(): string
    {
        return match ($this->employeeProfile->employment_status) {
            'active' => 'green',
            'on_leave' => 'yellow',
            'terminated' => 'red',
            default => 'zinc',
        };
    }

    #[Computed]
    public function statusLabel(): string
    {
        return match ($this->employeeProfile->employment_status) {
            'active' => __('Active'),
            'on_leave' => __('On Leave'),
            'terminated' => __('Terminated'),
            default => __('Unknown'),
        };
    }

    #[Computed]
    public function maskedSsn(): ?string
    {
        if (! $this->employeeProfile->ssn) {
            return null;
        }

        $ssn = $this->employeeProfile->ssn;

        return '***-**-'.substr($ssn, -4);
    }

    #[Computed]
    public function maskedBankAccount(): ?string
    {
        if (! $this->employeeProfile->bank_account_number) {
            return null;
        }

        $account = $this->employeeProfile->bank_account_number;

        return str_repeat('*', max(0, strlen($account) - 4)).substr($account, -4);
    }

    #[Computed]
    public function maskedRoutingNumber(): ?string
    {
        if (! $this->employeeProfile->bank_routing_number) {
            return null;
        }

        $routing = $this->employeeProfile->bank_routing_number;

        return str_repeat('*', max(0, strlen($routing) - 4)).substr($routing, -4);
    }
}; ?>

<div>
    <div class="mx-auto max-w-6xl">
        {{-- Header --}}
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('employees.index')" icon="arrow-left" wire:navigate>
                    {{ __('Back') }}
                </flux:button>
                <div class="flex items-center gap-3">
                    <flux:avatar name="{{ $employeeProfile->user->name }}" size="lg" />
                    <div>
                        <flux:heading size="xl">{{ $employeeProfile->user->name }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $employeeProfile->employee_number }}
                            @if ($employeeProfile->job_title)
                                &middot; {{ $employeeProfile->job_title }}
                            @endif
                        </flux:text>
                    </div>
                </div>
                <flux:badge size="sm" color="{{ $this->statusColor }}">{{ $this->statusLabel }}</flux:badge>
            </div>
            <div class="flex items-center gap-2">
                @can('employees.edit')
                    @if ($employeeProfile->employment_status === 'active')
                        <flux:button wire:click="setStatus('on_leave')" size="sm" variant="ghost" icon="pause-circle" :wire:confirm="__('Place this employee on leave?')">
                            {{ __('On Leave') }}
                        </flux:button>
                        <flux:button wire:click="setStatus('terminated')" size="sm" variant="ghost" icon="x-circle" :wire:confirm="__('Are you sure you want to terminate this employee? They will be unable to log in.')">
                            {{ __('Terminate') }}
                        </flux:button>
                    @elseif ($employeeProfile->employment_status === 'on_leave')
                        <flux:button wire:click="setStatus('active')" size="sm" variant="primary" icon="check-circle">
                            {{ __('Reactivate') }}
                        </flux:button>
                    @elseif ($employeeProfile->employment_status === 'terminated')
                        <flux:button wire:click="setStatus('active')" size="sm" variant="primary" icon="check-circle" :wire:confirm="__('Rehire this employee and restore access?')">
                            {{ __('Rehire') }}
                        </flux:button>
                    @endif
                    <flux:button wire:click="openEditModal" size="sm" icon="pencil">
                        {{ __('Edit') }}
                    </flux:button>
                @endcan
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- Stats Overview --}}
        <div class="mb-6 grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Department') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $employeeProfile->department ?? '-' }}</flux:heading>
            </div>
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Pay Rate') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $employeeProfile->getPayRateLabel() }}</flux:heading>
            </div>
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Hire Date') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $employeeProfile->hire_date?->format('M j, Y') ?? '-' }}</flux:heading>
            </div>
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Tenure') }}</flux:text>
                <flux:heading size="xl" class="mt-1">
                    @if ($employeeProfile->hire_date)
                        {{ $employeeProfile->hire_date->diffForHumans(now(), ['parts' => 1, 'short' => true]) }}
                    @else
                        -
                    @endif
                </flux:heading>
            </div>
        </div>

        {{-- Tab Navigation --}}
        <div class="mb-6 border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex space-x-8">
                @foreach ([
                    'overview' => __('Overview'),
                    'documents' => __('Documents'),
                    'time' => __('Time'),
                    'schedule' => __('Schedule'),
                    'payroll' => __('Payroll'),
                    'commission' => __('Commission'),
                    'notes' => __('Notes'),
                ] as $tab => $label)
                    @if ($tab === 'payroll' && ! auth()->user()->can('payroll.view'))
                        @continue
                    @endif
                    @if ($tab === 'commission' && ! auth()->user()->can('payroll.manage'))
                        @continue
                    @endif
                    <button
                        wire:click="setTab('{{ $tab }}')"
                        class="border-b-2 px-1 py-4 text-sm font-medium {{ $activeTab === $tab ? 'border-zinc-800 text-zinc-900 dark:border-zinc-200 dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Tab Content --}}
        @if ($activeTab === 'overview')
            <div class="grid gap-6 md:grid-cols-2">
                {{-- Contact Information --}}
                <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-4">{{ __('Contact Information') }}</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Email') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $employeeProfile->user->email }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Phone') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $employeeProfile->phone ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Date of Birth') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $employeeProfile->date_of_birth?->format('M j, Y') ?? '-' }}</flux:text>
                            </dd>
                        </div>
                    </dl>
                </div>

                {{-- Employment Details --}}
                <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-4">{{ __('Employment Details') }}</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Employee Number') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $employeeProfile->employee_number }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Job Title') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $employeeProfile->job_title ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Department') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $employeeProfile->department ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Pay') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $employeeProfile->getPayRateLabel() }}</flux:text>
                            </dd>
                        </div>
                        @if ($employeeProfile->termination_date)
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Termination Date') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $employeeProfile->termination_date->format('M j, Y') }}</flux:text>
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>

                {{-- Address --}}
                <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-4">{{ __('Address') }}</flux:heading>
                    @if ($employeeProfile->getFullAddress())
                        <flux:text>{{ $employeeProfile->getFullAddress() }}</flux:text>
                    @else
                        <flux:text class="text-zinc-400">{{ __('No address on file.') }}</flux:text>
                    @endif
                </div>

                {{-- Emergency Contact --}}
                <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-4">{{ __('Emergency Contact') }}</flux:heading>
                    @if ($employeeProfile->emergency_contact_name)
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Name') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $employeeProfile->emergency_contact_name }}</flux:text>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Phone') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $employeeProfile->emergency_contact_phone ?? '-' }}</flux:text>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Relationship') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $employeeProfile->emergency_contact_relation ?? '-' }}</flux:text>
                                </dd>
                            </div>
                        </dl>
                    @else
                        <flux:text class="text-zinc-400">{{ __('No emergency contact on file.') }}</flux:text>
                    @endif
                </div>

                {{-- Sensitive Information (permission-gated) --}}
                @can('employees.view_sensitive')
                    <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                        <flux:heading size="sm" class="mb-4">{{ __('Sensitive Information') }}</flux:heading>
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('SSN') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $this->maskedSsn ?? '-' }}</flux:text>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Bank Name') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $employeeProfile->bank_name ?? '-' }}</flux:text>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Routing Number') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $this->maskedRoutingNumber ?? '-' }}</flux:text>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Account Number') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $this->maskedBankAccount ?? '-' }}</flux:text>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Account Type') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $employeeProfile->bank_account_type ? ucfirst($employeeProfile->bank_account_type) : '-' }}</flux:text>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    {{-- W-4 Information --}}
                    <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                        <flux:heading size="sm" class="mb-4">{{ __('W-4 Information') }}</flux:heading>
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Filing Status') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $employeeProfile->w4_filing_status ? ucfirst(str_replace('_', ' ', $employeeProfile->w4_filing_status)) : '-' }}</flux:text>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Allowances') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $employeeProfile->w4_allowances ?? '-' }}</flux:text>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Additional Withholding') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>
                                        @if ($employeeProfile->w4_additional_withholding)
                                            ${{ number_format($employeeProfile->w4_additional_withholding, 2) }}
                                        @else
                                            -
                                        @endif
                                    </flux:text>
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endcan

                {{-- Lead Safe Certification --}}
                <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                    <div class="mb-4 flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Lead Safe Certification') }}</flux:heading>
                        @if ($employeeProfile->isLeadSafeCertExpired())
                            <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                        @elseif ($employeeProfile->isLeadSafeCertExpiringSoon())
                            <flux:badge size="sm" color="yellow">{{ __('Expiring Soon') }}</flux:badge>
                        @elseif ($employeeProfile->lead_safe_cert_expiration)
                            <flux:badge size="sm" color="green">{{ __('Valid') }}</flux:badge>
                        @endif
                    </div>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Certificate Number') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $employeeProfile->lead_safe_cert_number ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Expiration Date') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $employeeProfile->lead_safe_cert_expiration?->format('M j, Y') ?? '-' }}</flux:text>
                            </dd>
                        </div>
                    </dl>
                </div>

                {{-- Onboarding Status --}}
                <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                    <div class="mb-4 flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Onboarding') }}</flux:heading>
                        @if ($employeeProfile->isOnboardingComplete())
                            <flux:badge size="sm" color="green">{{ __('Complete') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                        @endif
                    </div>
                    @if ($employeeProfile->onboarding_completed_at)
                        <flux:text class="text-zinc-500">
                            {{ __('Completed') }} {{ $employeeProfile->onboarding_completed_at->format('M j, Y \a\t g:ia') }}
                        </flux:text>
                    @else
                        <flux:text class="text-zinc-500">{{ __('Onboarding has not been completed yet.') }}</flux:text>
                    @endif
                </div>
            </div>
        @endif

        @if ($activeTab === 'documents')
            <div class="space-y-8">
                {{-- Contracts Section --}}
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">{{ __('Contracts') }}</flux:heading>
                        @can('employees.edit')
                            <flux:button wire:click="$dispatch('open-assign-contract')" size="sm" icon="plus" variant="primary">
                                {{ __('Assign Contract') }}
                            </flux:button>
                        @endcan
                    </div>

                    @if ($this->contracts->isEmpty())
                        <div class="rounded-lg border border-zinc-200 p-8 text-center dark:border-zinc-700">
                            <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-400" />
                            <flux:heading size="sm" class="mt-4">{{ __('No Contracts') }}</flux:heading>
                            <flux:text class="mt-2 text-zinc-500">{{ __('No contracts have been assigned to this employee yet.') }}</flux:text>
                        </div>
                    @else
                        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                <thead class="bg-zinc-50 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Title') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Sent') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Signed') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                                    @foreach ($this->contracts as $contract)
                                        <tr wire:key="contract-{{ $contract->id }}" class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50" onclick="window.Livewire.navigate('{{ route('contracts.show', $contract) }}')">
                                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">{{ $contract->title }}</td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <flux:badge size="sm" color="{{ match($contract->status) { 'draft' => 'zinc', 'sent' => 'blue', 'viewed' => 'amber', 'signed' => 'green', 'voided' => 'red', default => 'zinc' } }}">
                                                    {{ ucfirst($contract->status) }}
                                                </flux:badge>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $contract->sent_at?->format('M j, Y') ?? '-' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $contract->signed_at?->format('M j, Y') ?? '-' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right" @click.stop>
                                                <flux:button :href="route('contracts.show', $contract)" size="xs" variant="ghost" icon="eye" wire:navigate>
                                                    {{ __('View') }}
                                                </flux:button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <flux:separator />

                {{-- General Documents Section --}}
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">{{ __('Uploaded Documents') }}</flux:heading>
                        @can('employees.edit')
                            <flux:button wire:click="openUploadModal" size="sm" icon="arrow-up-tray" variant="primary">
                                {{ __('Upload Document') }}
                            </flux:button>
                        @endcan
                    </div>

                    @if ($this->employeeDocuments->isEmpty())
                        <div class="rounded-lg border border-zinc-200 p-8 text-center dark:border-zinc-700">
                            <flux:icon name="folder-open" class="mx-auto h-12 w-12 text-zinc-400" />
                            <flux:heading size="sm" class="mt-4">{{ __('No Documents') }}</flux:heading>
                            <flux:text class="mt-2 text-zinc-500">{{ __('Upload W-9s, licenses, certifications, and other employee documents.') }}</flux:text>
                        </div>
                    @else
                        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                <thead class="bg-zinc-50 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Name') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Uploaded') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Expires') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                                    @foreach ($this->employeeDocuments as $document)
                                        <tr wire:key="doc-{{ $document->id }}">
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <div>
                                                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $document->name }}</flux:text>
                                                    @if ($document->notes)
                                                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ Str::limit($document->notes, 40) }}</flux:text>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <flux:badge size="sm" color="{{ match($document->type) { 'w9' => 'blue', 'i9' => 'blue', 'direct_deposit' => 'green', 'insurance' => 'amber', 'license' => 'purple', 'certification' => 'cyan', default => 'zinc' } }}">
                                                    {{ $document->getTypeLabel() }}
                                                </flux:badge>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <flux:badge size="sm" color="{{ match($document->status) { 'pending' => 'amber', 'approved' => 'green', 'rejected' => 'red', default => 'zinc' } }}">
                                                    {{ $document->getStatusLabel() }}
                                                </flux:badge>
                                                @if ($document->reviewer)
                                                    <flux:text class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                                        {{ __('by') }} {{ $document->reviewer->name }}
                                                    </flux:text>
                                                @endif
                                                @if ($document->isRejected() && $document->review_notes)
                                                    <flux:text class="mt-0.5 text-xs text-red-600 dark:text-red-400" title="{{ $document->review_notes }}">
                                                        {{ Str::limit($document->review_notes, 30) }}
                                                    </flux:text>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                                                {{ $document->created_at->format('M j, Y') }}
                                                @if ($document->uploader)
                                                    <br><span class="text-xs">{{ __('by') }} {{ $document->uploader->name }}</span>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-sm">
                                                @if ($document->expires_at)
                                                    <span class="{{ $document->isExpired() ? 'text-red-600 dark:text-red-400 font-medium' : ($document->isExpiringSoon() ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-500 dark:text-zinc-400') }}">
                                                        {{ $document->expires_at->format('M j, Y') }}
                                                    </span>
                                                    @if ($document->isExpired())
                                                        <flux:badge size="sm" color="red" class="ml-1">{{ __('Expired') }}</flux:badge>
                                                    @elseif ($document->isExpiringSoon())
                                                        <flux:badge size="sm" color="amber" class="ml-1">{{ __('Soon') }}</flux:badge>
                                                    @endif
                                                @else
                                                    <span class="text-zinc-400">-</span>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                                <div class="flex items-center justify-end gap-1">
                                                    <flux:button wire:click="downloadDocument({{ $document->id }})" size="xs" variant="ghost" icon="arrow-down-tray">
                                                        {{ __('Download') }}
                                                    </flux:button>
                                                    @can('employees.edit')
                                                        @if ($document->isPending())
                                                            <flux:button wire:click="approveDocument({{ $document->id }})" size="xs" variant="primary" icon="check">
                                                                {{ __('Approve') }}
                                                            </flux:button>
                                                            <flux:button wire:click="openRejectModal({{ $document->id }})" size="xs" variant="ghost" icon="x-mark" class="text-red-600 dark:text-red-400">
                                                                {{ __('Reject') }}
                                                            </flux:button>
                                                        @endif
                                                        <flux:button wire:click="deleteDocument({{ $document->id }})" wire:confirm="{{ __('Are you sure you want to delete this document?') }}" size="xs" variant="ghost" icon="trash">
                                                            {{ __('Delete') }}
                                                        </flux:button>
                                                    @endcan
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <livewire:contracts.assign-modal :employeeProfile="$employeeProfile" />

            {{-- Reject Document Modal --}}
            <flux:modal wire:model="showRejectModal" variant="flyout" class="max-w-lg">
                <div class="space-y-6">
                    <flux:heading size="lg">{{ __('Reject Document') }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ __('Please provide a reason for rejecting this document. The employee will be notified.') }}
                    </flux:text>

                    <flux:field>
                        <flux:label>{{ __('Reason for Rejection') }}</flux:label>
                        <flux:textarea wire:model="rejectNotes" rows="3" placeholder="{{ __('e.g. Document is illegible, wrong form version, missing signature...') }}" />
                        <flux:error name="rejectNotes" />
                    </flux:field>

                    <div class="flex justify-end gap-3 pt-4">
                        <flux:button wire:click="$set('showRejectModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                        <flux:button wire:click="rejectDocument" variant="danger">{{ __('Reject Document') }}</flux:button>
                    </div>
                </div>
            </flux:modal>

            {{-- Upload Document Modal --}}
            <flux:modal wire:model="showUploadModal" variant="flyout" class="max-w-lg">
                <div class="space-y-6">
                    <flux:heading size="lg">{{ __('Upload Document') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('Document Name') }}</flux:label>
                        <flux:input wire:model="docName" placeholder="{{ __('e.g. W-9 Form 2026') }}" />
                        <flux:error name="docName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Document Type') }}</flux:label>
                        <flux:select wire:model="docType">
                            @foreach (\App\Models\EmployeeDocument::TYPES as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="docType" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('File') }}</flux:label>
                        <input type="file" wire:model="docFile" class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300" />
                        <flux:description>{{ __('Max 10MB. PDF, images, and common document formats accepted.') }}</flux:description>
                        <flux:error name="docFile" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Expiration Date') }}</flux:label>
                        <flux:input wire:model="docExpiresAt" type="date" />
                        <flux:description>{{ __('Optional. Set if the document has an expiry date (e.g. licenses, certifications).') }}</flux:description>
                        <flux:error name="docExpiresAt" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Notes') }}</flux:label>
                        <flux:textarea wire:model="docNotes" rows="2" placeholder="{{ __('Optional notes about this document...') }}" />
                        <flux:error name="docNotes" />
                    </flux:field>

                    <div class="flex justify-end gap-3 pt-4">
                        <flux:button wire:click="$set('showUploadModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                        <flux:button wire:click="uploadDocument" variant="primary">
                            <div wire:loading.remove wire:target="uploadDocument">{{ __('Upload') }}</div>
                            <div wire:loading wire:target="uploadDocument">{{ __('Uploading...') }}</div>
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        @if ($activeTab === 'time')
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Recent Time Entries') }}</flux:heading>
                    <flux:button :href="route('time-tracking.timesheet', $employeeProfile)" size="sm" icon="arrow-top-right-on-square" variant="ghost" wire:navigate>
                        {{ __('Full Timesheet') }}
                    </flux:button>
                </div>

                @if ($this->recentTimeEntries->isEmpty())
                    <div class="rounded-lg border border-zinc-200 p-8 text-center dark:border-zinc-700">
                        <flux:icon name="clock" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="sm" class="mt-4">{{ __('No Time Entries') }}</flux:heading>
                        <flux:text class="mt-2 text-zinc-500">{{ __('No time entries have been recorded for this employee yet.') }}</flux:text>
                    </div>
                @else
                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Clock In') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Clock Out') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Total Hours') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                                @foreach ($this->recentTimeEntries as $entry)
                                    <tr wire:key="time-{{ $entry->id }}">
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">{{ $entry->clock_in->format('M j, Y') }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $entry->clock_in->format('g:ia') }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $entry->clock_out?->format('g:ia') ?? __('Active') }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">{{ $entry->total_hours ?? '-' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <flux:badge size="sm" color="{{ match($entry->status) { 'pending' => 'zinc', 'approved' => 'green', 'disputed' => 'red', 'active' => 'blue', default => 'zinc' } }}">
                                                {{ ucfirst($entry->status) }}
                                            </flux:badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        @if ($activeTab === 'schedule')
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Upcoming Schedule') }}</flux:heading>
                    <flux:button :href="route('scheduling.index')" size="sm" icon="arrow-top-right-on-square" variant="ghost" wire:navigate>
                        {{ __('Full Schedule') }}
                    </flux:button>
                </div>

                @if ($this->upcomingSchedules->isEmpty())
                    <div class="rounded-lg border border-zinc-200 p-8 text-center dark:border-zinc-700">
                        <flux:icon name="calendar-days" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="sm" class="mt-4">{{ __('No Upcoming Schedules') }}</flux:heading>
                        <flux:text class="mt-2 text-zinc-500">{{ __('No upcoming shifts are scheduled for this employee.') }}</flux:text>
                    </div>
                @else
                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Start') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('End') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Hours') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                                @foreach ($this->upcomingSchedules as $schedule)
                                    <tr wire:key="schedule-{{ $schedule->id }}">
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">{{ $schedule->date->format('M j, Y (D)') }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ \Carbon\Carbon::parse($schedule->start_time)->format('g:ia') }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ \Carbon\Carbon::parse($schedule->end_time)->format('g:ia') }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">{{ $schedule->getScheduledHours() }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <flux:badge size="sm" color="{{ match($schedule->status) { 'scheduled' => 'blue', 'confirmed' => 'green', 'cancelled' => 'red', default => 'zinc' } }}">
                                                {{ ucfirst($schedule->status) }}
                                            </flux:badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        @if ($activeTab === 'payroll')
            @can('payroll.view')
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">{{ __('Payroll') }}</flux:heading>
                        <div class="flex items-center gap-2">
                            @can('payroll.manage')
                                <flux:button :href="route('payroll.deductions', $employeeProfile)" size="sm" icon="adjustments-horizontal" variant="ghost" wire:navigate>
                                    {{ __('Deductions') }}
                                </flux:button>
                            @endcan
                            <flux:button :href="route('payroll.index')" size="sm" icon="arrow-top-right-on-square" variant="ghost" wire:navigate>
                                {{ __('Payroll Dashboard') }}
                            </flux:button>
                        </div>
                    </div>

                    @if ($this->payStubs->isEmpty())
                        <div class="rounded-lg border border-zinc-200 p-8 text-center dark:border-zinc-700">
                            <flux:icon name="banknotes" class="mx-auto h-12 w-12 text-zinc-400" />
                            <flux:heading size="sm" class="mt-4">{{ __('No Pay Stubs') }}</flux:heading>
                            <flux:text class="mt-2 text-zinc-500">{{ __('No pay stubs have been generated for this employee yet.') }}</flux:text>
                        </div>
                    @else
                        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                <thead class="bg-zinc-50 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Pay Period') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Gross Pay') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Deductions') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Net Pay') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Check #') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                                    @foreach ($this->payStubs as $stub)
                                        <tr wire:key="stub-{{ $stub->id }}">
                                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">
                                                @if ($stub->payPeriod)
                                                    {{ $stub->payPeriod->start_date->format('M j') }} - {{ $stub->payPeriod->end_date->format('M j, Y') }}
                                                @else
                                                    -
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($stub->gross_pay, 2) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($stub->total_deductions, 2) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($stub->net_pay, 2) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $stub->check_number ?? '-' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <flux:badge size="sm" color="{{ $stub->status === 'finalized' ? 'green' : 'zinc' }}">
                                                    {{ ucfirst($stub->status) }}
                                                </flux:badge>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                                <flux:button :href="route('payroll.stub', $stub)" size="xs" variant="ghost" icon="eye" wire:navigate>
                                                    {{ __('View') }}
                                                </flux:button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-zinc-50 dark:bg-zinc-800">
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('YTD Totals') }}</td>
                                        <td class="px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($this->payStubs->sum('gross_pay'), 2) }}</td>
                                        <td class="px-4 py-3 text-right text-sm font-medium text-red-600 dark:text-red-400">${{ number_format($this->payStubs->sum('total_deductions'), 2) }}</td>
                                        <td class="px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($this->payStubs->sum('net_pay'), 2) }}</td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
            @endcan
        @endif

        @if ($activeTab === 'commission')
            @can('payroll.manage')
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">{{ __('Commission Rules') }}</flux:heading>
                        <flux:button wire:click="addCommissionRule" size="sm" icon="plus" variant="primary">
                            {{ __('Add Rule') }}
                        </flux:button>
                    </div>

                    @if (empty($commissionRules))
                        <div class="rounded-lg border border-zinc-200 p-8 text-center dark:border-zinc-700">
                            <flux:icon name="currency-dollar" class="mx-auto h-12 w-12 text-zinc-400" />
                            <flux:heading size="sm" class="mt-4">{{ __('No Commission Rules') }}</flux:heading>
                            <flux:text class="mt-2 text-zinc-500">{{ __('Add commission rules to automatically calculate commissions for this employee.') }}</flux:text>
                        </div>
                    @else
                        <div class="space-y-3">
                            @foreach ($commissionRules as $index => $rule)
                                <div wire:key="rule-{{ $index }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700 {{ ! $rule['active'] ? 'opacity-50' : '' }}">
                                    <div class="flex items-start gap-4">
                                        <div class="grid flex-1 gap-3 sm:grid-cols-4">
                                            <flux:field>
                                                <flux:label>{{ __('Source') }}</flux:label>
                                                <flux:select wire:model="commissionRules.{{ $index }}.source">
                                                    <option value="lead">{{ __('Lead') }}</option>
                                                    <option value="estimate">{{ __('Estimate') }}</option>
                                                    <option value="job">{{ __('Job') }}</option>
                                                </flux:select>
                                            </flux:field>

                                            <flux:field>
                                                <flux:label>{{ __('Type') }}</flux:label>
                                                <flux:select wire:model="commissionRules.{{ $index }}.type">
                                                    <option value="flat">{{ __('Flat') }}</option>
                                                    <option value="revenue_percentage">{{ __('Revenue %') }}</option>
                                                    <option value="profit_percentage">{{ __('Profit %') }}</option>
                                                    <option value="tiered">{{ __('Tiered') }}</option>
                                                </flux:select>
                                            </flux:field>

                                            <flux:field>
                                                <flux:label>{{ __('Rate') }}</flux:label>
                                                <flux:input wire:model="commissionRules.{{ $index }}.rate" type="number" step="0.0001" min="0" placeholder="{{ __('e.g. 0.05') }}" />
                                            </flux:field>

                                            <flux:field>
                                                <flux:label>{{ __('Amount ($)') }}</flux:label>
                                                <flux:input wire:model="commissionRules.{{ $index }}.amount" type="number" step="0.01" min="0" prefix="$" />
                                            </flux:field>
                                        </div>

                                        <div class="flex items-center gap-3 pt-6">
                                            <div class="flex items-center gap-1.5" title="{{ __('Auto-trigger on lifecycle events') }}">
                                                <flux:checkbox wire:model="commissionRules.{{ $index }}.auto_trigger" />
                                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Auto') }}</span>
                                            </div>
                                            <flux:switch wire:click="toggleCommissionRule({{ $index }})" :checked="$rule['active']" />
                                            <flux:button wire:click="removeCommissionRule({{ $index }})" size="xs" variant="ghost" icon="trash" wire:confirm="{{ __('Remove this commission rule?') }}" />
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex justify-end">
                            <flux:button wire:click="saveCommissionRules" variant="primary" icon="check">
                                {{ __('Save Rules') }}
                            </flux:button>
                        </div>
                    @endif

                    {{-- Commission History --}}
                    @if ($employeeProfile->commissionEntries()->exists())
                        <flux:separator class="my-6" />
                        <div class="flex items-center justify-between">
                            <flux:heading size="lg">{{ __('Recent Commission Entries') }}</flux:heading>
                            <flux:button :href="route('payroll.commissions')" size="sm" variant="ghost" icon="arrow-top-right-on-square" wire:navigate>
                                {{ __('All Commissions') }}
                            </flux:button>
                        </div>
                        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                <thead class="bg-zinc-50 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Source') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                                    @foreach ($employeeProfile->commissionEntries()->latest('earned_date')->limit(10)->get() as $entry)
                                        <tr wire:key="commission-{{ $entry->id }}">
                                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">{{ $entry->earned_date?->format('M j, Y') }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $entry->source_label ?? '-' }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ ucfirst(str_replace('_', ' ', $entry->commission_type)) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($entry->amount, 2) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <flux:badge size="sm" color="{{ match($entry->status) { 'pending' => 'yellow', 'approved' => 'green', 'paid' => 'blue', 'reversed' => 'red', default => 'zinc' } }}">
                                                    {{ ucfirst($entry->status) }}
                                                </flux:badge>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endcan
        @endif

        @if ($activeTab === 'notes')
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:heading size="sm" class="mb-4">{{ __('Employee Notes') }}</flux:heading>
                @can('employees.edit')
                    <div class="space-y-4">
                        <flux:textarea wire:model="editNotes" rows="6" placeholder="{{ __('Add notes about this employee...') }}" />
                        <div class="flex justify-end">
                            <flux:button wire:click="saveNotes" variant="primary" size="sm" icon="check">
                                {{ __('Save Notes') }}
                            </flux:button>
                        </div>
                    </div>
                @else
                    <flux:text class="whitespace-pre-line">{{ $employeeProfile->notes ?? __('No notes added.') }}</flux:text>
                @endcan
            </div>
        @endif
    </div>

    {{-- Edit Profile Modal --}}
    <flux:modal wire:model="showEditModal" variant="flyout" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Edit Employee') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="editName" />
                <flux:error name="editName" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Job Title') }}</flux:label>
                    <flux:input wire:model="editJobTitle" />
                    <flux:error name="editJobTitle" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Department') }}</flux:label>
                    <flux:input wire:model="editDepartment" />
                    <flux:error name="editDepartment" />
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Pay Rate') }}</flux:label>
                    <flux:input wire:model="editPayRate" type="number" step="0.01" min="0" prefix="$" />
                    <flux:error name="editPayRate" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Pay Type') }}</flux:label>
                    <flux:select wire:model.live="editPayType">
                        @foreach (\App\Models\EmployeeProfile::PAY_TYPES as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="editPayType" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Phone') }}</flux:label>
                <flux:input wire:model="editPhone" type="tel" />
                <flux:error name="editPhone" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Status') }}</flux:label>
                <flux:select wire:model="editStatus">
                    <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                    <flux:select.option value="on_leave">{{ __('On Leave') }}</flux:select.option>
                    <flux:select.option value="terminated">{{ __('Terminated') }}</flux:select.option>
                </flux:select>
                <flux:error name="editStatus" />
            </flux:field>

            <flux:separator />

            <flux:heading size="sm">{{ __('Address') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Address Line 1') }}</flux:label>
                <flux:input wire:model="editAddressLine1" />
                <flux:error name="editAddressLine1" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Address Line 2') }}</flux:label>
                <flux:input wire:model="editAddressLine2" />
                <flux:error name="editAddressLine2" />
            </flux:field>

            <div class="grid grid-cols-3 gap-4">
                <flux:field>
                    <flux:label>{{ __('City') }}</flux:label>
                    <flux:input wire:model="editCity" />
                    <flux:error name="editCity" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('State') }}</flux:label>
                    <flux:input wire:model="editState" maxlength="2" placeholder="CO" />
                    <flux:error name="editState" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('ZIP') }}</flux:label>
                    <flux:input wire:model="editZipCode" />
                    <flux:error name="editZipCode" />
                </flux:field>
            </div>

            <flux:separator />

            <flux:heading size="sm">{{ __('Emergency Contact') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Contact Name') }}</flux:label>
                <flux:input wire:model="editEmergencyContactName" />
                <flux:error name="editEmergencyContactName" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Contact Phone') }}</flux:label>
                    <flux:input wire:model="editEmergencyContactPhone" type="tel" />
                    <flux:error name="editEmergencyContactPhone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Relationship') }}</flux:label>
                    <flux:input wire:model="editEmergencyContactRelation" placeholder="{{ __('e.g. Spouse, Parent') }}" />
                    <flux:error name="editEmergencyContactRelation" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-3 pt-4">
                <flux:button wire:click="$set('showEditModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="saveProfile" variant="primary">{{ __('Save Changes') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
