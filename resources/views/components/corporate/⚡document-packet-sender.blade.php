<?php

use App\Models\CorporateDocument;
use App\Models\Corporation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public array $selectedDocumentIds = [];
    public array $selectedMinutesIds = [];
    // Maps parent document id => selected version id
    public array $selectedVersions = [];
    public string $email = '';
    public string $subject = '';
    public string $message = '';
    public bool $sending = false;
    public ?string $successMessage = null;

    public function boot(): void
    {
        if (! Auth::user()?->can('business_plan.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('business_plan.manage') ?? false;
    }

    #[Computed]
    public function corporations(): \Illuminate\Database\Eloquent\Collection
    {
        return Corporation::active()
            ->with([
                'documents' => fn ($q) => $q->whereNull('parent_document_id')->where('status', 'ready')->orderBy('title'),
                'documents.children' => fn ($q) => $q->orderBy('version'),
                'meetingMinutes' => fn ($q) => $q->orderByDesc('meeting_date'),
            ])
            ->orderBy('name')
            ->get();
    }

    public function toggleDocument(int $parentId, int $versionId): void
    {
        if (in_array($versionId, $this->selectedDocumentIds)) {
            $this->selectedDocumentIds = array_values(array_diff($this->selectedDocumentIds, [$versionId]));
            unset($this->selectedVersions[$parentId]);
        } else {
            // Remove any previously selected version of this document
            if (isset($this->selectedVersions[$parentId])) {
                $this->selectedDocumentIds = array_values(array_diff($this->selectedDocumentIds, [$this->selectedVersions[$parentId]]));
            }
            $this->selectedDocumentIds[] = $versionId;
            $this->selectedVersions[$parentId] = $versionId;
        }
    }

    public function changeVersion(int $parentId, int $newVersionId): void
    {
        // Remove old version from selection
        if (isset($this->selectedVersions[$parentId])) {
            $this->selectedDocumentIds = array_values(array_diff($this->selectedDocumentIds, [$this->selectedVersions[$parentId]]));
        }

        // Add new version
        $this->selectedDocumentIds[] = $newVersionId;
        $this->selectedVersions[$parentId] = $newVersionId;
    }

    public function toggleMinutes(int $id): void
    {
        if (in_array($id, $this->selectedMinutesIds)) {
            $this->selectedMinutesIds = array_values(array_diff($this->selectedMinutesIds, [$id]));
        } else {
            $this->selectedMinutesIds[] = $id;
        }
    }

    public function downloadPacket(): void
    {
        if (empty($this->selectedDocumentIds) && empty($this->selectedMinutesIds)) {
            return;
        }

        $this->redirect(route('corporate.packet.download') . '?' . http_build_query([
            'document_ids' => $this->selectedDocumentIds,
            'minutes_ids' => $this->selectedMinutesIds,
        ]));
    }

    public function sendPacket(): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        $this->validate([
            'email' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'selectedDocumentIds' => ['required_without:selectedMinutesIds', 'array'],
            'selectedMinutesIds' => ['required_without:selectedDocumentIds', 'array'],
        ]);

        $this->sending = true;

        try {
            $pdfContent = app(\App\Services\CorporateDocumentService::class)->buildMergedPacketPdf(
                $this->selectedDocumentIds,
                $this->selectedMinutesIds,
            );

            \Illuminate\Support\Facades\Mail::raw(
                $this->message ?: 'Please find the attached corporate document packet.',
                function ($mail) use ($pdfContent) {
                    $mail->to($this->email)
                        ->subject($this->subject)
                        ->attachData($pdfContent, 'corporate-document-packet.pdf', [
                            'mime' => 'application/pdf',
                        ]);
                }
            );

            $this->successMessage = __('Document packet sent successfully!');
            $this->email = '';
            $this->subject = '';
            $this->message = '';
        } finally {
            $this->sending = false;
        }
    }

    #[Computed]
    public function selectedCount(): int
    {
        return count($this->selectedDocumentIds) + count($this->selectedMinutesIds);
    }
};
?>

<div>
    <flux:heading size="lg" class="mb-4">{{ __('Send Document Packet') }}</flux:heading>

    @if ($successMessage)
        <div class="mb-4">
            <flux:callout variant="outline" icon="check-circle" class="text-sm">
                <flux:callout.text>{{ $successMessage }}</flux:callout.text>
            </flux:callout>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Document Selection --}}
        <div class="space-y-4">
            <flux:heading size="sm">{{ __('Select Documents') }}</flux:heading>

            @foreach ($this->corporations as $corp)
                @if ($corp->documents->isNotEmpty() || $corp->meetingMinutes->isNotEmpty())
                    <div wire:key="corp-select-{{ $corp->id }}" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <flux:text class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $corp->name }}</flux:text>

                        @if ($corp->documents->isNotEmpty())
                            <div class="mb-3">
                                <flux:text class="mb-1 text-xs font-medium uppercase tracking-wider text-zinc-400">{{ __('Documents') }}</flux:text>
                                <div class="space-y-2">
                                    @foreach ($corp->documents as $doc)
                                        @php
                                            $versions = collect([$doc])->merge($doc->children);
                                            $latestVersion = $versions->sortByDesc('version')->first();
                                            $isSelected = isset($selectedVersions[$doc->id]);
                                            $currentSelectedId = $selectedVersions[$doc->id] ?? $latestVersion->id;
                                        @endphp
                                        <div wire:key="doc-select-{{ $doc->id }}" class="rounded border border-zinc-100 p-2 dark:border-zinc-800">
                                            <label class="flex cursor-pointer items-center gap-2">
                                                <flux:checkbox
                                                    :checked="$isSelected"
                                                    wire:click="toggleDocument({{ $doc->id }}, {{ $currentSelectedId }})"
                                                />
                                                <span class="flex-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $doc->title }}</span>
                                                <flux:badge size="sm" color="blue">{{ CorporateDocument::TYPES[$doc->type] ?? $doc->type }}</flux:badge>
                                            </label>
                                            @if ($isSelected && $versions->count() > 1)
                                                <div class="mt-2 ml-6">
                                                    <flux:select
                                                        size="sm"
                                                        wire:change="changeVersion({{ $doc->id }}, $event.target.value)"
                                                        class="w-full"
                                                    >
                                                        @foreach ($versions->sortByDesc('version') as $ver)
                                                            <flux:select.option :value="$ver->id" :selected="$ver->id === $currentSelectedId">
                                                                v{{ $ver->version }}{{ $ver->edited_content ? ' (edited)' : ' (original)' }} - {{ $ver->created_at->format('M j, Y') }}
                                                            </flux:select.option>
                                                        @endforeach
                                                    </flux:select>
                                                </div>
                                            @elseif ($isSelected)
                                                <div class="mt-1 ml-6">
                                                    <flux:text class="text-xs text-zinc-400">v{{ $latestVersion->version }}</flux:text>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($corp->meetingMinutes->isNotEmpty())
                            <div>
                                <flux:text class="mb-1 text-xs font-medium uppercase tracking-wider text-zinc-400">{{ __('Minutes') }}</flux:text>
                                <div class="space-y-1">
                                    @foreach ($corp->meetingMinutes as $min)
                                        <label wire:key="min-select-{{ $min->id }}" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                            <flux:checkbox
                                                :checked="in_array($min->id, $selectedMinutesIds)"
                                                wire:click="toggleMinutes({{ $min->id }})"
                                            />
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $min->title }}</span>
                                            <flux:text class="text-xs text-zinc-400">{{ $min->meeting_date->format('M j, Y') }}</flux:text>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach

            @if ($this->corporations->every(fn ($c) => $c->documents->isEmpty() && $c->meetingMinutes->isEmpty()))
                <div class="rounded-lg border border-zinc-200 bg-white p-6 text-center dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:text class="text-sm text-zinc-500">{{ __('No documents or minutes available to send.') }}</flux:text>
                </div>
            @endif
        </div>

        {{-- Send Form --}}
        <div>
            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="sm" class="mb-4">
                    {{ __('Send To') }}
                    @if ($this->selectedCount > 0)
                        <flux:badge size="sm" color="blue" class="ml-2">{{ $this->selectedCount }} {{ __('selected') }}</flux:badge>
                    @endif
                </flux:heading>

                <div class="space-y-4">
                    <flux:field>
                        <flux:label>{{ __('Email Address') }}</flux:label>
                        <flux:input wire:model="email" type="email" placeholder="recipient@example.com" />
                        <flux:error name="email" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Subject') }}</flux:label>
                        <flux:input wire:model="subject" placeholder="Corporate Document Packet" />
                        <flux:error name="subject" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Message (optional)') }}</flux:label>
                        <flux:textarea wire:model="message" rows="3" placeholder="Please find the attached documents..." />
                    </flux:field>

                    <div class="flex gap-2">
                        <flux:button variant="ghost" wire:click="downloadPacket" :disabled="$this->selectedCount === 0" icon="arrow-down-tray">
                            {{ __('Download') }}
                        </flux:button>
                        @if ($this->canManage)
                            <flux:button variant="primary" wire:click="sendPacket" :disabled="$this->selectedCount === 0 || $sending" icon="paper-airplane">
                                @if ($sending)
                                    {{ __('Sending...') }}
                                @else
                                    {{ __('Send Packet') }}
                                @endif
                            </flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
