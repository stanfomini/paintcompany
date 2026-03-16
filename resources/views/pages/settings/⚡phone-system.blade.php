<?php

use App\Models\CallGroup;
use App\Models\CallGroupMember;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public function boot(): void
    {
        if (! auth()->user()?->can('settings.phone_system')) {
            abort(403);
        }
    }

    // Section 1: General Settings
    public bool $phoneSystemEnabled = false;
    public string $phoneRoutingMode = 'ring_all';
    public bool $callRecordingEnabled = false;

    // Section 2: Call Groups
    public bool $showGroupModal = false;
    public ?int $editingGroupId = null;
    public string $groupName = '';
    public string $groupDescription = '';
    public int $ringTimeout = 30;
    public array $groupMembers = [];
    public bool $showDeleteGroupModal = false;
    public ?int $deletingGroupId = null;
    public string $deletingGroupName = '';

    // Call Group Defaults
    public ?int $defaultCallGroupId = null;
    public ?int $afterHoursCallGroupId = null;

    // Section 3: Business Hours
    public string $businessHoursStart = '08:00';
    public string $businessHoursEnd = '17:00';
    public array $businessDays = [1, 2, 3, 4, 5];
    public bool $alwaysOpen = false;

    // Section 4: IVR Menu
    public bool $ivrEnabled = false;
    public array $ivrOptions = [];
    public string $timeoutAction = 'ring_default';

    // Section 5: Voicemail
    public string $voicemailGreetingType = 'tts';
    public string $voicemailGreetingText = '';
    public int $voicemailTimeoutSeconds = 25;
    public $voicemailGreetingFile = null;
    public ?string $existingGreetingPath = null;

    // Section 6: Extensions — editing state
    public ?int $editingExtensionUserId = null;
    public string $editingExtension = '';
    public string $editingForwardingNumber = '';

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $settings = CompanySetting::getInstance();

        // General
        $this->phoneSystemEnabled = (bool) $settings->phone_system_enabled;
        $this->phoneRoutingMode = $settings->phone_routing_mode ?? 'ring_all';
        $this->callRecordingEnabled = (bool) $settings->call_recording_enabled;

        // Call Group Defaults
        $this->defaultCallGroupId = $settings->default_call_group_id;
        $this->afterHoursCallGroupId = $settings->after_hours_call_group_id;

        // Business Hours
        $this->businessHoursStart = $settings->phone_business_hours_start ?? '08:00';
        $this->businessHoursEnd = $settings->phone_business_hours_end ?? '17:00';
        $this->businessDays = $settings->phone_business_days ?? [1, 2, 3, 4, 5];
        $this->alwaysOpen = empty($settings->phone_business_hours_start) && empty($settings->phone_business_hours_end);

        // IVR
        $this->ivrEnabled = (bool) $settings->ivr_enabled;
        $ivrMenu = $settings->ivr_menu ?? [];
        $this->ivrOptions = $ivrMenu['options'] ?? [];
        $this->timeoutAction = $ivrMenu['timeout_action'] ?? 'ring_default';

        // Voicemail
        $this->voicemailGreetingType = $settings->voicemail_greeting_type ?? 'tts';
        $this->voicemailGreetingText = $settings->voicemail_greeting_text ?? '';
        $this->voicemailTimeoutSeconds = $settings->voicemail_timeout_seconds ?? 25;
        $this->existingGreetingPath = $settings->voicemail_greeting_path;
    }

    // --- Section 1: General ---

    public function saveGeneral(): void
    {
        $this->ensureInternalUser();

        CompanySetting::getInstance()->update([
            'phone_system_enabled' => $this->phoneSystemEnabled,
            'phone_routing_mode' => $this->phoneRoutingMode,
            'call_recording_enabled' => $this->callRecordingEnabled,
        ]);

        $this->dispatch('general-saved');
    }

    // --- Section 2: Call Groups ---

    #[Computed]
    public function callGroups(): \Illuminate\Database\Eloquent\Collection
    {
        return CallGroup::query()
            ->withCount('members')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function internalUsers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('type', 'internal')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'forwarding_number', 'extension']);
    }

    public function openAddGroupModal(): void
    {
        $this->resetGroupForm();
        $this->showGroupModal = true;
    }

    public function editGroup(int $id): void
    {
        $group = CallGroup::with('members')->findOrFail($id);

        $this->editingGroupId = $group->id;
        $this->groupName = $group->name;
        $this->groupDescription = $group->description ?? '';
        $this->ringTimeout = $group->ring_timeout_seconds ?? 30;
        $this->groupMembers = $group->members->map(fn (CallGroupMember $m) => [
            'user_id' => $m->user_id,
            'forwarding_number' => $m->forwarding_number ?? '',
            'is_active' => $m->is_active,
        ])->toArray();

        $this->showGroupModal = true;
    }

    public function addMember(int $userId): void
    {
        // Avoid duplicates
        foreach ($this->groupMembers as $member) {
            if ((int) $member['user_id'] === $userId) {
                return;
            }
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        $this->groupMembers[] = [
            'user_id' => $userId,
            'forwarding_number' => $user->forwarding_number ?? '',
            'is_active' => true,
        ];
    }

    public function removeMember(int $index): void
    {
        unset($this->groupMembers[$index]);
        $this->groupMembers = array_values($this->groupMembers);
    }

    public function saveGroup(): void
    {
        $this->ensureInternalUser();

        $validated = $this->validate([
            'groupName' => ['required', 'string', 'max:100'],
            'groupDescription' => ['nullable', 'string', 'max:500'],
            'ringTimeout' => ['required', 'integer', 'min:5', 'max:120'],
            'groupMembers' => ['array'],
            'groupMembers.*.user_id' => ['required', 'exists:users,id'],
            'groupMembers.*.forwarding_number' => ['nullable', 'string', 'max:20'],
            'groupMembers.*.is_active' => ['boolean'],
        ]);

        if ($this->editingGroupId) {
            $group = CallGroup::findOrFail($this->editingGroupId);
            $group->update([
                'name' => $validated['groupName'],
                'description' => $validated['groupDescription'] ?: null,
                'ring_timeout_seconds' => $validated['ringTimeout'],
            ]);
        } else {
            $maxOrder = CallGroup::max('sort_order') ?? 0;
            $group = CallGroup::create([
                'name' => $validated['groupName'],
                'description' => $validated['groupDescription'] ?: null,
                'ring_timeout_seconds' => $validated['ringTimeout'],
                'is_active' => true,
                'sort_order' => $maxOrder + 1,
            ]);
        }

        // Sync members
        $group->members()->delete();
        foreach ($validated['groupMembers'] as $i => $member) {
            $group->members()->create([
                'user_id' => $member['user_id'],
                'forwarding_number' => $member['forwarding_number'] ?: null,
                'is_active' => $member['is_active'] ?? true,
                'sort_order' => $i,
            ]);
        }

        $this->showGroupModal = false;
        $this->resetGroupForm();
        unset($this->callGroups);
        $this->dispatch('call-groups-saved');
    }

    public function confirmDeleteGroup(int $id): void
    {
        $group = CallGroup::findOrFail($id);
        $this->deletingGroupId = $id;
        $this->deletingGroupName = $group->name;
        $this->showDeleteGroupModal = true;
    }

    public function deleteGroup(): void
    {
        $this->ensureInternalUser();

        if (! $this->deletingGroupId) {
            return;
        }

        CallGroup::findOrFail($this->deletingGroupId)->delete();

        $this->showDeleteGroupModal = false;
        $this->deletingGroupId = null;
        $this->deletingGroupName = '';
        unset($this->callGroups);
        $this->dispatch('call-groups-saved');
    }

    public function cancelDeleteGroup(): void
    {
        $this->showDeleteGroupModal = false;
        $this->deletingGroupId = null;
        $this->deletingGroupName = '';
    }

    public function saveCallGroupDefaults(): void
    {
        $this->ensureInternalUser();

        $this->validate([
            'defaultCallGroupId' => ['nullable', 'exists:call_groups,id'],
            'afterHoursCallGroupId' => ['nullable', 'exists:call_groups,id'],
        ]);

        CompanySetting::getInstance()->update([
            'default_call_group_id' => $this->defaultCallGroupId,
            'after_hours_call_group_id' => $this->afterHoursCallGroupId,
        ]);

        $this->dispatch('call-group-defaults-saved');
    }

    protected function resetGroupForm(): void
    {
        $this->editingGroupId = null;
        $this->groupName = '';
        $this->groupDescription = '';
        $this->ringTimeout = 30;
        $this->groupMembers = [];
    }

    // --- Section 3: Business Hours ---

    public function saveBusinessHours(): void
    {
        $this->ensureInternalUser();

        if ($this->alwaysOpen) {
            CompanySetting::getInstance()->update([
                'phone_business_hours_start' => null,
                'phone_business_hours_end' => null,
                'phone_business_days' => null,
            ]);
        } else {
            $this->validate([
                'businessHoursStart' => ['required', 'date_format:H:i'],
                'businessHoursEnd' => ['required', 'date_format:H:i', 'after:businessHoursStart'],
                'businessDays' => ['required', 'array', 'min:1'],
                'businessDays.*' => ['integer', 'between:1,7'],
            ]);

            CompanySetting::getInstance()->update([
                'phone_business_hours_start' => $this->businessHoursStart,
                'phone_business_hours_end' => $this->businessHoursEnd,
                'phone_business_days' => $this->businessDays,
            ]);
        }

        $this->dispatch('business-hours-saved');
    }

    // --- Section 4: IVR ---

    public function addIvrOption(): void
    {
        $usedDigits = array_column($this->ivrOptions, 'digit');
        $nextDigit = '1';
        for ($i = 1; $i <= 9; $i++) {
            if (! in_array((string) $i, $usedDigits, true)) {
                $nextDigit = (string) $i;
                break;
            }
        }

        $this->ivrOptions[] = [
            'digit' => $nextDigit,
            'label' => '',
            'call_group_id' => null,
        ];
    }

    public function removeIvrOption(int $index): void
    {
        unset($this->ivrOptions[$index]);
        $this->ivrOptions = array_values($this->ivrOptions);
    }

    public function saveIvr(): void
    {
        $this->ensureInternalUser();

        $this->validate([
            'ivrEnabled' => ['boolean'],
            'ivrOptions' => ['array'],
            'ivrOptions.*.digit' => ['required', 'string', 'size:1'],
            'ivrOptions.*.label' => ['required', 'string', 'max:100'],
            'ivrOptions.*.call_group_id' => ['nullable', 'exists:call_groups,id'],
            'timeoutAction' => ['required', 'in:ring_default,voicemail'],
        ]);

        CompanySetting::getInstance()->update([
            'ivr_enabled' => $this->ivrEnabled,
            'ivr_menu' => [
                'options' => $this->ivrOptions,
                'timeout_action' => $this->timeoutAction,
            ],
        ]);

        $this->dispatch('ivr-saved');
    }

    // --- Section 5: Voicemail ---

    public function saveVoicemail(): void
    {
        $this->ensureInternalUser();

        $this->validate([
            'voicemailGreetingType' => ['required', 'in:tts,upload'],
            'voicemailGreetingText' => ['nullable', 'string', 'max:1000'],
            'voicemailTimeoutSeconds' => ['required', 'integer', 'min:10', 'max:60'],
            'voicemailGreetingFile' => ['nullable', 'file', 'mimes:mp3,wav,ogg,m4a', 'max:10240'],
        ]);

        $data = [
            'voicemail_greeting_type' => $this->voicemailGreetingType,
            'voicemail_greeting_text' => $this->voicemailGreetingText ?: null,
            'voicemail_timeout_seconds' => $this->voicemailTimeoutSeconds,
        ];

        if ($this->voicemailGreetingFile) {
            $path = $this->voicemailGreetingFile->store('voicemail-greetings', 'public');
            $data['voicemail_greeting_path'] = $path;
            $this->existingGreetingPath = $path;
        }

        CompanySetting::getInstance()->update($data);
        $this->voicemailGreetingFile = null;

        $this->dispatch('voicemail-saved');
    }

    // --- Section 6: Extensions ---

    #[Computed]
    public function extensions(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('type', 'internal')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'extension', 'forwarding_number']);
    }

    public function startEditExtension(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->editingExtensionUserId = $userId;
        $this->editingExtension = $user->extension ?? '';
        $this->editingForwardingNumber = $user->forwarding_number ?? '';
    }

    public function cancelEditExtension(): void
    {
        $this->editingExtensionUserId = null;
        $this->editingExtension = '';
        $this->editingForwardingNumber = '';
    }

    public function saveExtension(): void
    {
        $this->ensureInternalUser();

        $this->validate([
            'editingExtension' => [
                'nullable',
                'string',
                'regex:/^\d{3,4}$/',
                Rule::unique('users', 'extension')->ignore($this->editingExtensionUserId),
            ],
            'editingForwardingNumber' => ['nullable', 'string', 'max:20', 'regex:/^[\d\+\-\(\)\s]+$/'],
        ], [
            'editingExtension.regex' => 'Extension must be 3-4 digits.',
            'editingExtension.unique' => 'This extension is already assigned to another user.',
            'editingForwardingNumber.regex' => 'Please enter a valid phone number.',
        ]);

        User::where('id', $this->editingExtensionUserId)->update([
            'extension' => $this->editingExtension ?: null,
            'forwarding_number' => $this->editingForwardingNumber ?: null,
        ]);

        $this->cancelEditExtension();
        unset($this->extensions);
        $this->dispatch('extensions-saved');
    }

    // --- Helpers ---

    protected function ensureInternalUser(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    public function getUserName(int $userId): string
    {
        return $this->internalUsers->firstWhere('id', $userId)?->name ?? 'Unknown';
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Phone System') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Phone System')" :subheading="__('Configure your phone system, call groups, IVR menu, and voicemail')" :fullWidth="true">
        <div class="my-6 w-full space-y-8">

            {{-- Section 1: General Settings --}}
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:heading size="lg">{{ __('General Settings') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Enable and configure the core phone system.') }}</flux:text>
                <flux:separator class="my-4" />

                <div class="space-y-4">
                    <flux:switch wire:model="phoneSystemEnabled" :label="__('Phone System Enabled')" :description="__('Enable Twilio-powered phone system for inbound/outbound calls')" />

                    <flux:select wire:model="phoneRoutingMode" :label="__('Call Routing Mode')">
                        <flux:select.option value="ring_all">{{ __('Ring All - Ring all group members simultaneously') }}</flux:select.option>
                        <flux:select.option value="smart_routing">{{ __('Smart Routing - Route based on availability and rules') }}</flux:select.option>
                    </flux:select>

                    <flux:switch wire:model="callRecordingEnabled" :label="__('Call Recording')" :description="__('Record all calls for quality assurance and training')" />
                </div>

                <div class="mt-4 flex items-center gap-4">
                    <flux:button wire:click="saveGeneral" variant="primary">{{ __('Save General Settings') }}</flux:button>
                    <x-action-message on="general-saved">{{ __('Saved.') }}</x-action-message>
                </div>
            </div>

            {{-- Section 2: Call Groups --}}
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading size="lg">{{ __('Call Groups') }}</flux:heading>
                        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Organize team members into groups for call routing.') }}</flux:text>
                    </div>
                    <flux:button variant="primary" icon="plus" wire:click="openAddGroupModal" size="sm">
                        {{ __('Add Group') }}
                    </flux:button>
                </div>
                <flux:separator class="my-4" />

                <div class="space-y-3">
                    @forelse ($this->callGroups as $group)
                        <div wire:key="group-{{ $group->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ $group->name }}</span>
                                    <flux:badge size="sm">{{ $group->members_count }} {{ __('members') }}</flux:badge>
                                    @if (! $group->is_active)
                                        <flux:badge size="sm" color="amber">{{ __('Inactive') }}</flux:badge>
                                    @endif
                                </div>
                                @if ($group->description)
                                    <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $group->description }}</div>
                                @endif
                                <div class="mt-0.5 text-xs text-zinc-400">{{ __('Ring timeout') }}: {{ $group->ring_timeout_seconds }}s</div>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editGroup({{ $group->id }})" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDeleteGroup({{ $group->id }})" />
                            </div>
                        </div>
                    @empty
                        <div class="py-6 text-center text-zinc-500">
                            {{ __('No call groups yet. Create one to get started.') }}
                        </div>
                    @endforelse
                </div>

                <flux:separator class="my-4" />

                {{-- Call Group Defaults --}}
                <flux:heading size="sm">{{ __('Default Groups') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Assign default call groups for business hours and after hours.') }}</flux:text>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="defaultCallGroupId" :label="__('Business Hours Group')">
                        <flux:select.option value="">{{ __('None') }}</flux:select.option>
                        @foreach ($this->callGroups as $group)
                            <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="afterHoursCallGroupId" :label="__('After Hours Group')">
                        <flux:select.option value="">{{ __('None') }}</flux:select.option>
                        @foreach ($this->callGroups as $group)
                            <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="mt-4 flex items-center gap-4">
                    <flux:button wire:click="saveCallGroupDefaults" variant="primary">{{ __('Save Defaults') }}</flux:button>
                    <x-action-message on="call-group-defaults-saved">{{ __('Saved.') }}</x-action-message>
                </div>
            </div>

            {{-- Section 3: Business Hours --}}
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:heading size="lg">{{ __('Business Hours') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Define when your office is open for phone routing decisions.') }}</flux:text>
                <flux:separator class="my-4" />

                <div class="space-y-4">
                    <flux:switch wire:model.live="alwaysOpen" :label="__('Always Open (24/7)')" :description="__('When enabled, all calls use the business hours group regardless of time')" />

                    @if (! $alwaysOpen)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="businessHoursStart" :label="__('Start Time')" type="time" />
                            <flux:input wire:model="businessHoursEnd" :label="__('End Time')" type="time" />
                        </div>

                        <div>
                            <flux:text class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Business Days') }}</flux:text>
                            <div class="flex flex-wrap gap-3">
                                @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $dayNum => $dayLabel)
                                    <label class="flex items-center gap-2">
                                        <flux:checkbox wire:model="businessDays" value="{{ $dayNum }}" />
                                        <span class="text-sm">{{ __($dayLabel) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div class="mt-4 flex items-center gap-4">
                    <flux:button wire:click="saveBusinessHours" variant="primary">{{ __('Save Business Hours') }}</flux:button>
                    <x-action-message on="business-hours-saved">{{ __('Saved.') }}</x-action-message>
                </div>
            </div>

            {{-- Section 4: IVR Menu --}}
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:heading size="lg">{{ __('IVR Menu') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Configure an interactive voice response menu for callers.') }}</flux:text>
                <flux:separator class="my-4" />

                <div class="space-y-4">
                    <flux:switch wire:model.live="ivrEnabled" :label="__('Enable IVR Menu')" :description="__('When enabled, callers hear a menu before being routed')" />

                    @if ($ivrEnabled)
                        <div class="space-y-3">
                            @foreach ($ivrOptions as $index => $option)
                                <div wire:key="ivr-{{ $index }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                                    <flux:input wire:model="ivrOptions.{{ $index }}.digit" :label="__('Digit')" class="w-16" maxlength="1" />
                                    <div class="flex-1">
                                        <flux:input wire:model="ivrOptions.{{ $index }}.label" :label="__('Label')" :placeholder="__('e.g., Estimates, Scheduling')" />
                                    </div>
                                    <div class="w-48">
                                        <flux:select wire:model="ivrOptions.{{ $index }}.call_group_id" :label="__('Route To')">
                                            <flux:select.option value="">{{ __('Default Group') }}</flux:select.option>
                                            @foreach ($this->callGroups as $group)
                                                <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    </div>
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeIvrOption({{ $index }})" class="mt-5" />
                                </div>
                            @endforeach

                            @if (count($ivrOptions) < 9)
                                <flux:button variant="ghost" icon="plus" wire:click="addIvrOption" size="sm">
                                    {{ __('Add Menu Option') }}
                                </flux:button>
                            @endif
                        </div>

                        <flux:select wire:model="timeoutAction" :label="__('Timeout Action')" :description="__('What happens if the caller doesn\'t press a key')">
                            <flux:select.option value="ring_default">{{ __('Ring default call group') }}</flux:select.option>
                            <flux:select.option value="voicemail">{{ __('Go to voicemail') }}</flux:select.option>
                        </flux:select>
                    @endif
                </div>

                <div class="mt-4 flex items-center gap-4">
                    <flux:button wire:click="saveIvr" variant="primary">{{ __('Save IVR Settings') }}</flux:button>
                    <x-action-message on="ivr-saved">{{ __('Saved.') }}</x-action-message>
                </div>
            </div>

            {{-- Section 5: Voicemail --}}
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:heading size="lg">{{ __('Voicemail') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Configure voicemail greeting and behavior.') }}</flux:text>
                <flux:separator class="my-4" />

                <div class="space-y-4">
                    <flux:select wire:model.live="voicemailGreetingType" :label="__('Greeting Type')">
                        <flux:select.option value="tts">{{ __('Text-to-Speech') }}</flux:select.option>
                        <flux:select.option value="upload">{{ __('Upload Audio File') }}</flux:select.option>
                    </flux:select>

                    @if ($voicemailGreetingType === 'tts')
                        <flux:textarea wire:model="voicemailGreetingText" :label="__('Greeting Message')" :placeholder="__('Thank you for calling. Please leave a message after the beep and we will return your call as soon as possible.')" rows="3" />
                    @else
                        <flux:field>
                            <flux:label>{{ __('Upload Greeting') }}</flux:label>
                            <input type="file" wire:model="voicemailGreetingFile" accept=".mp3,.wav,.ogg,.m4a" class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:hover:file:bg-zinc-600" />
                            @if ($existingGreetingPath)
                                <flux:text class="mt-1 text-xs text-zinc-500">{{ __('Current file') }}: {{ basename($existingGreetingPath) }}</flux:text>
                            @endif
                            @error('voicemailGreetingFile')
                                <flux:text class="mt-1 text-xs text-red-500">{{ $message }}</flux:text>
                            @enderror
                        </flux:field>
                    @endif

                    <flux:input wire:model="voicemailTimeoutSeconds" :label="__('Ring Timeout (seconds)')" type="number" min="10" max="60" :description="__('How long to ring before going to voicemail')" />
                </div>

                <div class="mt-4 flex items-center gap-4">
                    <flux:button wire:click="saveVoicemail" variant="primary">{{ __('Save Voicemail Settings') }}</flux:button>
                    <x-action-message on="voicemail-saved">{{ __('Saved.') }}</x-action-message>
                </div>
            </div>

            {{-- Section 6: Extension Directory --}}
            <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:heading size="lg">{{ __('Extension Directory') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Assign extensions and forwarding numbers to team members.') }}</flux:text>
                <flux:separator class="my-4" />

                <div class="space-y-2">
                    {{-- Header --}}
                    <div class="hidden grid-cols-4 gap-4 px-4 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400 sm:grid">
                        <div>{{ __('Name') }}</div>
                        <div>{{ __('Email') }}</div>
                        <div>{{ __('Extension') }}</div>
                        <div>{{ __('Forwarding Number') }}</div>
                    </div>

                    @foreach ($this->extensions as $user)
                        <div wire:key="ext-{{ $user->id }}" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                            @if ($editingExtensionUserId === $user->id)
                                <div class="grid items-end gap-4 sm:grid-cols-4">
                                    <div>
                                        <flux:text class="text-sm font-medium">{{ $user->name }}</flux:text>
                                        <flux:text class="text-xs text-zinc-500">{{ $user->email }}</flux:text>
                                    </div>
                                    <div class="sm:col-span-1">
                                        <!-- Empty for email column alignment -->
                                    </div>
                                    <flux:input wire:model="editingExtension" :label="__('Extension')" :placeholder="__('e.g., 101')" maxlength="4" />
                                    <flux:input wire:model="editingForwardingNumber" :label="__('Forwarding Number')" :placeholder="__('e.g., (303) 555-1234')" />
                                </div>
                                @error('editingExtension')
                                    <flux:text class="mt-1 text-xs text-red-500">{{ $message }}</flux:text>
                                @enderror
                                @error('editingForwardingNumber')
                                    <flux:text class="mt-1 text-xs text-red-500">{{ $message }}</flux:text>
                                @enderror
                                <div class="mt-3 flex gap-2">
                                    <flux:button size="sm" variant="primary" wire:click="saveExtension">{{ __('Save') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="cancelEditExtension">{{ __('Cancel') }}</flux:button>
                                </div>
                            @else
                                <div class="grid items-center gap-4 sm:grid-cols-4">
                                    <div>
                                        <flux:text class="text-sm font-medium">{{ $user->name }}</flux:text>
                                    </div>
                                    <div>
                                        <flux:text class="text-xs text-zinc-500">{{ $user->email }}</flux:text>
                                    </div>
                                    <div>
                                        @if ($user->extension)
                                            <flux:badge size="sm">{{ $user->extension }}</flux:badge>
                                        @else
                                            <flux:text class="text-xs text-zinc-400">{{ __('Not set') }}</flux:text>
                                        @endif
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <flux:text class="text-sm">{{ $user->forwarding_number ?: __('Not set') }}</flux:text>
                                        <flux:button size="sm" variant="ghost" icon="pencil" wire:click="startEditExtension({{ $user->id }})" />
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if ($this->extensions->isEmpty())
                        <div class="py-6 text-center text-zinc-500">
                            {{ __('No internal users found.') }}
                        </div>
                    @endif
                </div>

                <x-action-message class="mt-4" on="extensions-saved">{{ __('Saved.') }}</x-action-message>
            </div>

        </div>
    </x-pages::settings.layout>

    {{-- Add/Edit Call Group Modal --}}
    <flux:modal wire:model="showGroupModal" variant="flyout" class="w-[500px]">
        <form wire:submit="saveGroup" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingGroupId ? __('Edit Call Group') : __('Add Call Group') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Configure the group name, timeout, and members.') }}</flux:text>
            </div>

            <flux:input wire:model="groupName" :label="__('Group Name')" :placeholder="__('e.g., Sales Team, Support')" required />
            <flux:textarea wire:model="groupDescription" :label="__('Description')" :placeholder="__('Optional description')" rows="2" />
            <flux:input wire:model="ringTimeout" :label="__('Ring Timeout (seconds)')" type="number" min="5" max="120" :description="__('How long to ring this group before timing out')" />

            <flux:separator />

            {{-- Members --}}
            <div>
                <flux:heading size="sm">{{ __('Members') }}</flux:heading>
                <div class="mt-3 space-y-2">
                    @foreach ($groupMembers as $index => $member)
                        <div wire:key="member-{{ $index }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="min-w-0 flex-1">
                                <flux:text class="text-sm font-medium">{{ $this->getUserName($member['user_id']) }}</flux:text>
                                <flux:input wire:model="groupMembers.{{ $index }}.forwarding_number" :placeholder="__('Forwarding number (optional)')" size="sm" class="mt-1" />
                            </div>
                            <flux:switch wire:model="groupMembers.{{ $index }}.is_active" size="sm" />
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeMember({{ $index }})" />
                        </div>
                    @endforeach
                </div>

                {{-- Add member select --}}
                <div class="mt-3">
                    <flux:select wire:change="addMember($event.target.value); $event.target.value = ''" :label="__('Add Member')">
                        <flux:select.option value="">{{ __('Select a team member...') }}</flux:select.option>
                        @foreach ($this->internalUsers as $user)
                            @if (! in_array($user->id, array_column($groupMembers, 'user_id')))
                                <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                            @endif
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showGroupModal', false)" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingGroupId ? __('Update Group') : __('Create Group') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Call Group Confirmation --}}
    <flux:modal wire:model="showDeleteGroupModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Call Group') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete') }} <strong>{{ $deletingGroupName }}</strong>{{ __('? This action cannot be undone.') }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelDeleteGroup" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteGroup" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
