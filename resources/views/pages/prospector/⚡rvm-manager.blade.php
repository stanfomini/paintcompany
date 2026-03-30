<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\RvmDrop;
use App\Models\Prospector\RvmTemplate;
use App\Services\Prospector\RvmService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Ringless Voicemail')]
class extends Component
{
    use WithFileUploads, WithPagination;

    // Template form
    public bool $showTemplateModal = false;
    public ?int $editingTemplateId = null;
    public string $templateName = '';
    public $audioFile = null;
    public ?int $templateCompanyId = null;

    // Send RVM modal
    public bool $showSendModal = false;
    public ?int $sendTemplateId = null;
    public ?int $sendCompanyId = null;
    public string $sendMethod = 'twilio_amd';
    public array $selectedContactIds = [];
    public string $contactSearch = '';

    // Delete
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingName = '';

    // Drop filters
    #[Url]
    public string $dropCompanyFilter = '';

    #[Url]
    public string $dropTemplateFilter = '';

    #[Url]
    public string $dropStatusFilter = '';

    #[Url]
    public string $dropMethodFilter = '';

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    // ──────────────────────────────────────
    // Computed properties
    // ──────────────────────────────────────

    #[Computed]
    public function templates(): \Illuminate\Support\Collection
    {
        return RvmTemplate::query()
            ->with(['company', 'createdBy'])
            ->withCount('drops')
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function drops(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return RvmDrop::query()
            ->with(['company', 'contact', 'template'])
            ->when($this->dropCompanyFilter, fn ($q) => $q->where('company_id', $this->dropCompanyFilter))
            ->when($this->dropTemplateFilter, fn ($q) => $q->where('template_id', $this->dropTemplateFilter))
            ->when($this->dropStatusFilter, fn ($q) => $q->where('status', $this->dropStatusFilter))
            ->when($this->dropMethodFilter, fn ($q) => $q->where('method', $this->dropMethodFilter))
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    #[Computed]
    public function companies(): \Illuminate\Support\Collection
    {
        return Company::query()->orderBy('name')->get();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'templates' => RvmTemplate::where('is_active', true)->count(),
            'total_drops' => RvmDrop::count(),
            'delivered' => RvmDrop::where('status', 'delivered')->count(),
            'failed' => RvmDrop::where('status', 'failed')->count(),
        ];
    }

    #[Computed]
    public function searchedContacts(): \Illuminate\Support\Collection
    {
        if (! $this->sendCompanyId || strlen($this->contactSearch) < 2) {
            return collect();
        }

        return Contact::query()
            ->where('company_id', $this->sendCompanyId)
            ->where('do_not_call', false)
            ->where(function ($q) {
                $q->where('first_name', 'like', "%{$this->contactSearch}%")
                    ->orWhere('last_name', 'like', "%{$this->contactSearch}%")
                    ->orWhere('phone', 'like', "%{$this->contactSearch}%");
            })
            ->limit(20)
            ->get();
    }

    // ──────────────────────────────────────
    // Template actions
    // ──────────────────────────────────────

    public function openTemplateModal(): void
    {
        $this->resetTemplateForm();
        $this->showTemplateModal = true;
    }

    public function closeTemplateModal(): void
    {
        $this->showTemplateModal = false;
        $this->resetTemplateForm();
    }

    public function saveTemplate(): void
    {
        $rules = [
            'templateName' => ['required', 'string', 'max:100'],
            'templateCompanyId' => ['required', 'exists:prospector_companies,id'],
        ];

        if (! $this->editingTemplateId) {
            $rules['audioFile'] = ['required', 'file', 'mimes:mp3,wav', 'max:10240'];
        } else {
            $rules['audioFile'] = ['nullable', 'file', 'mimes:mp3,wav', 'max:10240'];
        }

        $this->validate($rules);

        $data = [
            'company_id' => $this->templateCompanyId,
            'name' => $this->templateName,
            'created_by' => Auth::id(),
        ];

        if ($this->audioFile) {
            $path = $this->audioFile->store('rvm-templates', 'public');
            $data['audio_path'] = $path;

            // Get duration via getID3 or estimate
            $data['duration_seconds'] = $this->estimateAudioDuration($this->audioFile);
        }

        if ($this->editingTemplateId) {
            $template = RvmTemplate::findOrFail($this->editingTemplateId);
            $template->update($data);
        } else {
            RvmTemplate::create($data);
        }

        $this->closeTemplateModal();
        $this->clearCaches();
    }

    public function editTemplate(int $id): void
    {
        $template = RvmTemplate::findOrFail($id);
        $this->editingTemplateId = $template->id;
        $this->templateName = $template->name;
        $this->templateCompanyId = $template->company_id;
        $this->audioFile = null;
        $this->showTemplateModal = true;
    }

    public function toggleTemplateActive(int $id): void
    {
        $template = RvmTemplate::findOrFail($id);
        $template->update(['is_active' => ! $template->is_active]);
        $this->clearCaches();
    }

    public function confirmDeleteTemplate(int $id): void
    {
        $template = RvmTemplate::findOrFail($id);
        $this->deletingId = $id;
        $this->deletingName = $template->name;
        $this->showDeleteModal = true;
    }

    public function deleteTemplate(): void
    {
        if (! $this->deletingId) {
            return;
        }

        RvmTemplate::findOrFail($this->deletingId)->delete();
        $this->cancelDelete();
        $this->clearCaches();
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingName = '';
    }

    // ──────────────────────────────────────
    // Send RVM actions
    // ──────────────────────────────────────

    public function openSendModal(?int $templateId = null): void
    {
        $this->resetSendForm();
        $this->sendTemplateId = $templateId;
        $this->showSendModal = true;
    }

    public function closeSendModal(): void
    {
        $this->showSendModal = false;
        $this->resetSendForm();
    }

    public function toggleContact(int $contactId): void
    {
        if (in_array($contactId, $this->selectedContactIds)) {
            $this->selectedContactIds = array_values(array_diff($this->selectedContactIds, [$contactId]));
        } else {
            $this->selectedContactIds[] = $contactId;
        }
    }

    public function sendRvm(): void
    {
        $this->validate([
            'sendTemplateId' => ['required', 'exists:prospector_rvm_templates,id'],
            'sendCompanyId' => ['required', 'exists:prospector_companies,id'],
            'sendMethod' => ['required', 'in:twilio_amd,slybroadcast'],
            'selectedContactIds' => ['required', 'array', 'min:1'],
        ]);

        $rvmService = app(RvmService::class);
        $company = Company::findOrFail($this->sendCompanyId);
        $template = RvmTemplate::findOrFail($this->sendTemplateId);
        $contacts = Contact::whereIn('id', $this->selectedContactIds)->get();

        $sent = 0;
        $failed = 0;

        foreach ($contacts as $contact) {
            try {
                $rvmService->drop($company, $contact, $template, $this->sendMethod);
                $sent++;
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $this->closeSendModal();
        $this->clearCaches();

        if ($failed > 0) {
            session()->flash('warning', "Sent {$sent} RVM(s), {$failed} failed.");
        } else {
            session()->flash('success', "Sent {$sent} RVM(s) successfully.");
        }
    }

    // ──────────────────────────────────────
    // Filter helpers
    // ──────────────────────────────────────

    public function updatedDropCompanyFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDropTemplateFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDropStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDropMethodFilter(): void
    {
        $this->resetPage();
    }

    public function clearDropFilters(): void
    {
        $this->dropCompanyFilter = '';
        $this->dropTemplateFilter = '';
        $this->dropStatusFilter = '';
        $this->dropMethodFilter = '';
        $this->resetPage();
    }

    // ──────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────

    protected function resetTemplateForm(): void
    {
        $this->editingTemplateId = null;
        $this->templateName = '';
        $this->audioFile = null;
        $this->templateCompanyId = null;
    }

    protected function resetSendForm(): void
    {
        $this->sendTemplateId = null;
        $this->sendCompanyId = null;
        $this->sendMethod = 'twilio_amd';
        $this->selectedContactIds = [];
        $this->contactSearch = '';
    }

    protected function clearCaches(): void
    {
        unset($this->templates, $this->drops, $this->stats);
    }

    protected function estimateAudioDuration($file): int
    {
        $size = $file->getSize();
        // Rough estimate: MP3 at 128kbps = ~16KB/sec
        return max(1, (int) round($size / 16000));
    }

    protected function getStatusBadge(string $status): array
    {
        return match ($status) {
            'queued' => ['color' => 'zinc', 'label' => 'Queued'],
            'sent' => ['color' => 'blue', 'label' => 'Sent'],
            'delivered' => ['color' => 'green', 'label' => 'Delivered'],
            'failed' => ['color' => 'red', 'label' => 'Failed'],
            default => ['color' => 'zinc', 'label' => ucfirst($status)],
        };
    }

    protected function getMethodBadge(string $method): array
    {
        return match ($method) {
            'twilio_amd' => ['color' => 'blue', 'label' => 'Twilio AMD'],
            'slybroadcast' => ['color' => 'amber', 'label' => 'Slybroadcast'],
            default => ['color' => 'zinc', 'label' => ucfirst($method)],
        };
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Ringless Voicemail') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage voicemail templates and send RVM drops to contacts.') }}</flux:text>
        </div>

        <div class="flex gap-2">
            <flux:button variant="primary" icon="plus" wire:click="openTemplateModal">
                {{ __('New Template') }}
            </flux:button>
            <flux:button variant="filled" icon="paper-airplane" wire:click="openSendModal()">
                {{ __('Send RVM') }}
            </flux:button>
        </div>
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Active Templates') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['templates'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total Drops') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['total_drops'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Delivered') }}</flux:text>
            <flux:heading size="xl" class="mt-1 text-green-600">{{ $this->stats['delivered'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Failed') }}</flux:text>
            <flux:heading size="xl" class="mt-1 text-red-600">{{ $this->stats['failed'] }}</flux:heading>
        </div>
    </div>

    {{-- Flash messages --}}
    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400">
            {{ session('success') }}
        </div>
    @endif
    @if (session('warning'))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-700 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-400">
            {{ session('warning') }}
        </div>
    @endif

    {{-- Templates Section --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-4">{{ __('RVM Templates') }}</flux:heading>

        @if ($this->templates->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="speaker-wave" class="mx-auto mb-3 size-12 text-zinc-400" />
                <flux:heading size="lg">{{ __('No templates yet') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Upload your first voicemail audio template.') }}</flux:text>
                <div class="mt-4">
                    <flux:button variant="primary" icon="plus" wire:click="openTemplateModal">
                        {{ __('New Template') }}
                    </flux:button>
                </div>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Company') }}</flux:table.column>
                    <flux:table.column>{{ __('Duration') }}</flux:table.column>
                    <flux:table.column>{{ __('Active') }}</flux:table.column>
                    <flux:table.column>{{ __('Created By') }}</flux:table.column>
                    <flux:table.column>{{ __('Drops') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->templates as $template)
                        <flux:table.row wire:key="template-{{ $template->id }}">
                            <flux:table.cell>
                                <span class="font-medium">{{ $template->name }}</span>
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $template->company?->name ?? '-' }}
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $template->duration_seconds }}s
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:switch
                                    wire:click="toggleTemplateActive({{ $template->id }})"
                                    :checked="$template->is_active"
                                    size="sm"
                                />
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $template->createdBy?->name ?? '-' }}
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ $template->drops_count }}</flux:badge>
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <flux:button size="sm" variant="ghost" icon="paper-airplane" wire:click="openSendModal({{ $template->id }})" title="{{ __('Send RVM') }}" />
                                    <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editTemplate({{ $template->id }})" title="{{ __('Edit') }}" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDeleteTemplate({{ $template->id }})" title="{{ __('Delete') }}" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    {{-- RVM Drops Section --}}
    <div>
        <flux:heading size="lg" class="mb-4">{{ __('Recent RVM Drops') }}</flux:heading>

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap items-end gap-4">
            <flux:select wire:model.live="dropCompanyFilter" class="w-48" placeholder="{{ __('All Companies') }}">
                <flux:select.option value="">{{ __('All Companies') }}</flux:select.option>
                @foreach ($this->companies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="dropTemplateFilter" class="w-48" placeholder="{{ __('All Templates') }}">
                <flux:select.option value="">{{ __('All Templates') }}</flux:select.option>
                @foreach ($this->templates as $template)
                    <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="dropStatusFilter" class="w-36" placeholder="{{ __('All Statuses') }}">
                <flux:select.option value="">{{ __('All Statuses') }}</flux:select.option>
                <flux:select.option value="queued">{{ __('Queued') }}</flux:select.option>
                <flux:select.option value="sent">{{ __('Sent') }}</flux:select.option>
                <flux:select.option value="delivered">{{ __('Delivered') }}</flux:select.option>
                <flux:select.option value="failed">{{ __('Failed') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="dropMethodFilter" class="w-40" placeholder="{{ __('All Methods') }}">
                <flux:select.option value="">{{ __('All Methods') }}</flux:select.option>
                <flux:select.option value="twilio_amd">{{ __('Twilio AMD') }}</flux:select.option>
                <flux:select.option value="slybroadcast">{{ __('Slybroadcast') }}</flux:select.option>
            </flux:select>

            @if ($dropCompanyFilter || $dropTemplateFilter || $dropStatusFilter || $dropMethodFilter)
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearDropFilters">
                    {{ __('Clear') }}
                </flux:button>
            @endif
        </div>

        @if ($this->drops->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="phone-arrow-up-right" class="mx-auto mb-3 size-12 text-zinc-400" />
                <flux:heading size="lg">{{ __('No RVM drops yet') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Send your first ringless voicemail to get started.') }}</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Contact') }}</flux:table.column>
                    <flux:table.column>{{ __('Template') }}</flux:table.column>
                    <flux:table.column>{{ __('Method') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('From') }}</flux:table.column>
                    <flux:table.column>{{ __('To') }}</flux:table.column>
                    <flux:table.column>{{ __('Error') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->drops as $drop)
                        <flux:table.row wire:key="drop-{{ $drop->id }}">
                            <flux:table.cell>
                                <span class="text-sm" title="{{ $drop->created_at->format('M j, Y g:i A') }}">
                                    {{ $drop->created_at->diffForHumans() }}
                                </span>
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($drop->contact)
                                    <span class="font-medium">{{ $drop->contact->first_name }} {{ $drop->contact->last_name }}</span>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $drop->template?->name ?? '-' }}
                            </flux:table.cell>

                            <flux:table.cell>
                                @php $mb = $this->getMethodBadge($drop->method); @endphp
                                <flux:badge size="sm" :color="$mb['color']">{{ $mb['label'] }}</flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>
                                @php $sb = $this->getStatusBadge($drop->status); @endphp
                                <flux:badge size="sm" :color="$sb['color']">{{ $sb['label'] }}</flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>
                                <span class="font-mono text-sm">{{ $drop->from_number }}</span>
                            </flux:table.cell>

                            <flux:table.cell>
                                <span class="font-mono text-sm">{{ $drop->to_number }}</span>
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($drop->error)
                                    <span class="text-sm text-red-600 dark:text-red-400" title="{{ $drop->error }}">
                                        {{ Str::limit($drop->error, 30) }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="mt-6">
                {{ $this->drops->links() }}
            </div>
        @endif
    </div>

    {{-- Template Modal --}}
    <flux:modal wire:model="showTemplateModal" variant="flyout" class="w-[420px]">
        <form wire:submit="saveTemplate" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingTemplateId ? __('Edit Template') : __('New Template') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $editingTemplateId
                        ? __('Update the voicemail template.')
                        : __('Upload a new voicemail audio template.')
                    }}
                </flux:text>
            </div>

            <flux:select wire:model="templateCompanyId" :label="__('Company')" required>
                <flux:select.option value="">{{ __('Select a company...') }}</flux:select.option>
                @foreach ($this->companies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="templateName"
                :label="__('Template Name')"
                :placeholder="__('Intro Voicemail')"
                required
            />

            <div>
                <flux:label>{{ __('Audio File') }} (MP3 / WAV, max 10MB)</flux:label>
                <input
                    type="file"
                    wire:model="audioFile"
                    accept="audio/mp3,audio/wav,audio/mpeg,.mp3,.wav"
                    class="mt-1 block w-full text-sm text-zinc-500 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                />
                @error('audioFile')
                    <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text>
                @enderror

                <div wire:loading wire:target="audioFile" class="mt-2">
                    <flux:text class="text-sm text-blue-600">{{ __('Uploading...') }}</flux:text>
                </div>
            </div>

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeTemplateModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingTemplateId ? __('Update') : __('Create Template') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Send RVM Modal --}}
    <flux:modal wire:model="showSendModal" variant="flyout" class="w-[480px]">
        <form wire:submit="sendRvm" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Send RVM') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Select contacts and a template to send ringless voicemail.') }}</flux:text>
            </div>

            <flux:select wire:model.live="sendCompanyId" :label="__('Company')" required>
                <flux:select.option value="">{{ __('Select a company...') }}</flux:select.option>
                @foreach ($this->companies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="sendTemplateId" :label="__('Template')" required>
                <flux:select.option value="">{{ __('Select a template...') }}</flux:select.option>
                @foreach ($this->templates->where('is_active', true) as $template)
                    <flux:select.option value="{{ $template->id }}">{{ $template->name }} ({{ $template->duration_seconds }}s)</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="sendMethod" :label="__('Method')">
                <flux:select.option value="twilio_amd">{{ __('Twilio AMD') }}</flux:select.option>
                <flux:select.option value="slybroadcast">{{ __('Slybroadcast') }}</flux:select.option>
            </flux:select>

            {{-- Contact search --}}
            <div>
                <flux:label>{{ __('Contacts') }}</flux:label>
                @if ($sendCompanyId)
                    <flux:input
                        wire:model.live.debounce.300ms="contactSearch"
                        icon="magnifying-glass"
                        :placeholder="__('Search contacts by name or phone...')"
                        class="mt-1"
                    />

                    @if ($this->searchedContacts->isNotEmpty())
                        <div class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                            @foreach ($this->searchedContacts as $contact)
                                <label
                                    class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                    wire:key="search-contact-{{ $contact->id }}"
                                >
                                    <input
                                        type="checkbox"
                                        class="rounded border-zinc-300 text-blue-600 dark:border-zinc-600"
                                        @checked(in_array($contact->id, $selectedContactIds))
                                        wire:click="toggleContact({{ $contact->id }})"
                                    />
                                    <div>
                                        <div class="text-sm font-medium">{{ $contact->first_name }} {{ $contact->last_name }}</div>
                                        <div class="font-mono text-xs text-zinc-500">{{ $contact->phone }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @endif
                @else
                    <flux:text class="mt-1 text-sm text-zinc-500">{{ __('Select a company first.') }}</flux:text>
                @endif

                @if (count($selectedContactIds) > 0)
                    <flux:badge size="sm" color="blue" class="mt-2">
                        {{ count($selectedContactIds) }} {{ __('contact(s) selected') }}
                    </flux:badge>
                @endif

                @error('selectedContactIds')
                    <flux:text class="mt-1 text-sm text-red-600">{{ $message }}</flux:text>
                @enderror
            </div>

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeSendModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1" wire:loading.attr="disabled">
                    {{ __('Send') }} {{ count($selectedContactIds) > 0 ? '(' . count($selectedContactIds) . ')' : '' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Template') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete') }} <strong>{{ $deletingName }}</strong>{{ __('? This will also delete all associated RVM drops.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelDelete" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteTemplate" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
