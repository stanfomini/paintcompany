<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\CallScript;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\CompanyBookingLink;
use App\Models\Prospector\Contact;
use App\Models\Prospector\ContactNote;
use App\Models\Prospector\DialerSession;
use App\Models\Prospector\PipelineStage;
use App\Models\Prospector\RvmTemplate;
use App\Models\Prospector\SmsMessage;
use App\Services\Prospector\AgentStateService;
use App\Services\Prospector\CampaignQueueService;
use App\Services\Prospector\ConferenceService;
use App\Services\Prospector\DialerOrchestrationService;
use App\Services\Prospector\PowerDialerService;
use App\Services\Prospector\ProspectorSmsService;
use App\Services\Prospector\RvmService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Power Dialer')]
class extends Component
{
    // Queue filters
    public ?int $filterCompanyId = null;
    public string $queueSearch = '';
    public string $dialerMode = 'single'; // single, parallel

    // Active call state
    public ?string $activeCallUlid = null;
    public ?int $activeCallId = null;
    public ?array $activeContact = null;
    public string $callStatus = 'idle'; // idle, ringing, connected, ended
    public ?string $callingFrom = null;
    public ?string $amdResult = null;

    // Parallel dialer state
    public array $parallelLines = []; // [{call_id, contact_name, phone, status}]
    public ?int $connectedLineIndex = null;

    // Disposition
    public string $dispositionNotes = '';
    public bool $showDisposition = false;
    public ?string $callbackDate = null;

    // Agent
    public ?int $agentId = null;
    public string $agentStatus = 'offline';

    // Session (persistent conference)
    public ?int $activeSessionId = null;
    public ?int $selectedCampaignId = null;

    // UI
    public bool $isMuted = false;

    // SMS
    public string $smsBody = '';
    public bool $smsOpen = false;
    public array $smsConversation = [];
    public ?int $smsContactId = null;

    // Contact notes
    public string $newContactNote = '';

    // Company notes quick-edit
    public string $companyNotes = '';

    // Pipeline & follow-up
    public ?int $contactPipelineStageId = null;
    public ?string $contactFollowUpAt = null;

    // Preview contact (clicked from queue without dialing)
    public ?int $previewContactId = null;

    // Manual dial
    public string $manualDialNumber = '';

    // Transfer
    public bool $showTransferPanel = false;
    public string $transferNumber = '';
    public string $transferStatus = 'idle'; // idle, ringing, connected
    public ?string $transferCallSid = null;
    public ?int $transferAgentId = null;

    // Supervisor indicator
    public ?string $supervisorName = null;
    public ?string $supervisorMode = null; // listen, whisper, barge

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    public function mount(): void
    {
        $user = Auth::user();
        $agent = Agent::where('user_id', $user->id)->first();

        if (! $agent) {
            $company = Company::where('is_active', true)->first();
            if (! $company) {
                return;
            }
            $agent = Agent::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'status' => 'available',
                'max_concurrent_lines' => 1,
                'wrap_up_seconds' => 30,
            ]);
        }

        // Ensure agent is assigned to ALL active companies
        $activeCompanyIds = Company::where('is_active', true)->pluck('id');
        $agent->companies()->syncWithoutDetaching($activeCompanyIds);

        $this->agentId = $agent->id;
        $this->agentStatus = $agent->status;

        if ($agent->status === 'offline') {
            $agent->goAvailable();
            $this->agentStatus = 'available';
        }

        // No auto-preview on mount — queue is campaign-driven
    }

    #[Computed]
    public function agent(): ?Agent
    {
        if (! $this->agentId) {
            return null;
        }

        return Agent::with('user', 'company')->find($this->agentId);
    }

    #[Computed]
    public function assignedCompanies(): \Illuminate\Support\Collection
    {
        if (! $this->agent) {
            return collect();
        }

        return Company::whereIn('id', $this->agent->getCompanyIds())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function agentCampaigns(): \Illuminate\Support\Collection
    {
        if (! $this->agent) {
            return collect();
        }

        $companyIds = $this->agent->getCompanyIds();

        return Campaign::where(function ($q) use ($companyIds) {
                $q->whereIn('company_id', $companyIds)
                    ->orWhereNull('company_id');
            })
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    /**
     * Return all company IDs the agent is assigned to (for Alpine Echo subscriptions).
     */
    public function getAssignedCompanyIds(): array
    {
        if (! $this->agent) {
            return [];
        }

        return $this->agent->getCompanyIds();
    }

    #[Computed]
    public function queueContacts(): \Illuminate\Support\Collection
    {
        if (! $this->selectedCampaignId) {
            return collect();
        }

        $campaign = Campaign::find($this->selectedCampaignId);

        if (! $campaign) {
            return collect();
        }

        return \App\Models\Prospector\CampaignContact::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->with('contact')
            ->orderBy('sort_order')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function queuedCount(): int
    {
        return $this->queueContacts->count();
    }

    /**
     * Return the campaign-driven queue as a JSON-serializable array.
     * Called by Alpine to populate the queue cards.
     */
    public function syncCampaignQueue(): array
    {
        if (! $this->selectedCampaignId || ! $this->agent) {
            return [];
        }

        $campaign = Campaign::find($this->selectedCampaignId);
        if (! $campaign) {
            return [];
        }

        return app(CampaignQueueService::class)->getQueueAsArray($campaign, $this->agent);
    }

    #[Computed]
    public function dispositions(): array
    {
        return collect(config('prospector.dispositions', []))
            ->map(fn ($value) => is_array($value) ? $value['label'] : $value)
            ->all();
    }

    #[Computed]
    public function activeCall(): ?Call
    {
        if (! $this->activeCallId) {
            return null;
        }

        return Call::with('contact', 'fromPhoneNumber')->find($this->activeCallId);
    }

    #[Computed]
    public function contactDetail(): ?Contact
    {
        $contactId = $this->activeContact['id'] ?? $this->previewContactId;

        if (! $contactId) {
            return null;
        }

        return Contact::with(['company', 'pipelineStage.pipeline', 'notes.user'])->find($contactId);
    }

    #[Computed]
    public function contactCallHistory(): \Illuminate\Support\Collection
    {
        $contactId = $this->activeContact['id'] ?? $this->previewContactId;

        if (! $contactId) {
            return collect();
        }

        return Call::where('contact_id', $contactId)
            ->with('agent.user')
            ->latest()
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function bookingLinks(): \Illuminate\Support\Collection
    {
        if (! $this->agent) {
            return collect();
        }

        return CompanyBookingLink::where('company_id', $this->agent->company_id)
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function pipelineStages(): \Illuminate\Support\Collection
    {
        return PipelineStage::with('pipeline')
            ->orderBy('pipeline_id')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function rvmTemplates(): \Illuminate\Support\Collection
    {
        if (! $this->agent) {
            return collect();
        }

        return RvmTemplate::where('company_id', $this->agent->company_id)
            ->active()
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function activeScript(): ?CallScript
    {
        if (! $this->agent) {
            return null;
        }

        return CallScript::where('company_id', $this->agent->company_id)
            ->active()
            ->orderBy('sort_order')
            ->first();
    }

    // ──────────────────────────────────────
    // Queue & Preview
    // ──────────────────────────────────────

    public function previewContact(int $contactId): void
    {
        $this->previewContactId = $contactId;
        $this->loadContactState($contactId);
        $this->loadConversation($contactId);

        // Clear unread SMS flag when agent views this contact
        Contact::where('id', $contactId)
            ->where('has_unread_sms', true)
            ->update(['has_unread_sms' => false]);

        $this->clearCaches();
    }

    protected function loadContactState(?int $contactId): void
    {
        if (! $contactId) {
            $this->companyNotes = '';
            $this->contactPipelineStageId = null;
            $this->contactFollowUpAt = null;

            return;
        }

        $contact = Contact::with('company')->find($contactId);

        if (! $contact) {
            return;
        }

        $this->companyNotes = $contact->company?->notes ?? '';
        $this->contactPipelineStageId = $contact->pipeline_stage_id;
        $this->contactFollowUpAt = $contact->follow_up_at?->format('Y-m-d');
    }

    // ──────────────────────────────────────
    // Contact Notes
    // ──────────────────────────────────────

    public function addContactNote(): void
    {
        $body = trim($this->newContactNote);

        if (! $body) {
            return;
        }

        $contactId = $this->activeContact['id'] ?? $this->previewContactId;

        if (! $contactId) {
            return;
        }

        ContactNote::create([
            'contact_id' => $contactId,
            'user_id' => Auth::id(),
            'body' => $body,
        ]);

        $this->newContactNote = '';
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Company Notes Quick-Edit
    // ──────────────────────────────────────

    public function saveCompanyNotes(): void
    {
        $contactId = $this->activeContact['id'] ?? $this->previewContactId;

        if (! $contactId) {
            return;
        }

        $contact = Contact::find($contactId);

        if ($contact?->company) {
            $contact->company->update(['notes' => $this->companyNotes]);
        }
    }

    // ──────────────────────────────────────
    // Pipeline & Follow-up
    // ──────────────────────────────────────

    public function updateContactStage(?int $stageId): void
    {
        $contactId = $this->activeContact['id'] ?? $this->previewContactId;

        if (! $contactId) {
            return;
        }

        Contact::where('id', $contactId)->update([
            'pipeline_stage_id' => $stageId ?: null,
        ]);

        $this->contactPipelineStageId = $stageId ?: null;
        $this->clearCaches();

        // Tell Alpine to reload the queue so stage change is reflected
        $this->dispatch('queue-reload');
    }

    /**
     * Quick-disposition a contact without requiring an active call.
     * Used when agent reviews a contact and wants to set outcome directly.
     */
    public function quickDisposition(string $disposition, ?string $callbackAt = null): void
    {
        $contactId = $this->activeContact['id'] ?? $this->previewContactId;

        if (! $contactId) {
            return;
        }

        $contact = Contact::find($contactId);

        if (! $contact) {
            return;
        }

        // Update contact based on disposition
        $updates = ['last_disposition' => $disposition];

        if ($disposition === 'callback' && $callbackAt) {
            $updates['follow_up_at'] = $callbackAt;
        } elseif ($disposition === 'dnc') {
            $updates['do_not_call'] = true;
        } elseif ($disposition === 'not_interested' || $disposition === 'wrong_number' || $disposition === 'disconnected') {
            // Move to Lost stage if it exists
            $lostStage = PipelineStage::whereHas('pipeline', fn ($q) => $q->where('is_default', true))
                ->where('name', 'Lost')
                ->first();
            if ($lostStage) {
                $updates['pipeline_stage_id'] = $lostStage->id;
            }
        } elseif ($disposition === 'interested') {
            // Move to Interested stage if it exists
            $interestedStage = PipelineStage::whereHas('pipeline', fn ($q) => $q->where('is_default', true))
                ->where('name', 'Interested')
                ->first();
            if ($interestedStage) {
                $updates['pipeline_stage_id'] = $interestedStage->id;
            }
        }

        $contact->update($updates);

        if ($disposition === 'callback' && $callbackAt) {
            $this->contactFollowUpAt = $callbackAt;
        }

        $this->contactPipelineStageId = $contact->fresh()->pipeline_stage_id;

        // Add note if disposition notes exist
        if ($this->dispositionNotes) {
            ContactNote::create([
                'contact_id' => $contactId,
                'user_id' => Auth::id(),
                'body' => "[{$disposition}] {$this->dispositionNotes}",
            ]);
            $this->dispositionNotes = '';
        }

        $this->clearCaches();
        $this->dispatch('queue-reload');
    }

    public function setFollowUp(?string $date): void
    {
        $contactId = $this->activeContact['id'] ?? $this->previewContactId;

        if (! $contactId) {
            return;
        }

        Contact::where('id', $contactId)->update([
            'follow_up_at' => $date ?: null,
        ]);

        $this->contactFollowUpAt = $date ?: null;
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Booking Links & SMS Insert
    // ──────────────────────────────────────

    public function insertBookingLink(int $linkId): void
    {
        $link = CompanyBookingLink::find($linkId);

        if ($link) {
            $this->smsBody .= ($this->smsBody ? ' ' : '') . $link->url;
        }
    }

    // ──────────────────────────────────────
    // Voicemail Drop
    // ──────────────────────────────────────

    public function dropVoicemail(int $templateId): void
    {
        $contactId = $this->activeContact['id'] ?? null;

        if (! $contactId || ! $this->agent) {
            return;
        }

        $contact = Contact::with('company')->find($contactId);
        $template = RvmTemplate::find($templateId);

        if (! $contact || ! $template || ! $contact->company) {
            return;
        }

        try {
            $service = app(RvmService::class);
            $service->drop($contact->company, $contact, $template);
        } catch (\Exception $e) {
            // Error logged by service
        }
    }

    // ──────────────────────────────────────
    // Manual Dial & Quick-Add
    // ──────────────────────────────────────

    public function manualDial(): array
    {
        $number = preg_replace('/[^0-9+]/', '', $this->manualDialNumber);

        if (strlen($number) < 10 || ! $this->agent) {
            return ['error' => 'Invalid number or no agent'];
        }

        // Use the filtered company if set, otherwise the agent's default
        $companyId = $this->filterCompanyId ?? $this->agent->company_id;
        $company = Company::find($companyId) ?? $this->agent->company;

        // Find or create contact under the active company
        $contact = Contact::where('company_id', $company->id)
            ->where('phone', 'like', '%' . substr($number, -10))
            ->first();

        if (! $contact) {
            $intake = app(\App\Services\Prospector\ContactIntakeService::class);
            $result = $intake->findOrCreate(
                $company,
                $number,
                ['first_name' => 'Manual Dial', 'source' => 'manual'],
            );
            $contact = $result->contact;
        }

        $this->manualDialNumber = '';

        return $this->dialContact($contact->id);
    }

    public function dialContact(int $contactId): array
    {
        if (! $this->agent) {
            return ['error' => 'No agent'];
        }

        $contact = Contact::with('company.twilioAccount')->find($contactId);

        if (! $contact || ! $contact->company) {
            return ['error' => 'Contact not found'];
        }

        $agent = Agent::findOrFail($this->agentId);
        $company = $contact->company;

        // Select phone number
        $numberService = app(\App\Services\Prospector\NumberSelectionService::class);

        try {
            $fromNumber = $numberService->selectNumber($company, $contact, null);
        } catch (\RuntimeException $e) {
            $this->dispatch('dial-error', message: $e->getMessage());

            return ['error' => $e->getMessage()];
        }

        $conferenceName = 'call-'.\Illuminate\Support\Str::ulid();

        // Create Call record
        $call = Call::create([
            'ulid' => \Illuminate\Support\Str::ulid()->toBase32(),
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'contact_id' => $contact->id,
            'from_phone_number_id' => $fromNumber->id,
            'from_number' => $fromNumber->getEffectiveCallerId(),
            'to_number' => $contact->phone,
            'direction' => 'outbound',
            'status' => 'ringing',
            'started_at' => now(),
            'twilio_conference_sid' => $conferenceName,
        ]);

        // Set active call state
        $this->setActiveCallFromCall($call->load('contact'));
        $this->agentStatus = 'ringing';
        $this->showDisposition = false;
        $this->clearCaches();

        return [
            'phone' => $contact->phone,
            'contactId' => $contact->id,
            'companyId' => $company->id,
            'conferenceName' => $conferenceName,
        ];
    }

    public function quickAddContact(string $firstName, string $phone): void
    {
        if (! $this->agent || ! $firstName || ! $phone) {
            return;
        }

        $intake = app(\App\Services\Prospector\ContactIntakeService::class);
        $result = $intake->findOrCreate(
            $this->agent->company,
            $phone,
            ['first_name' => $firstName, 'source' => 'manual'],
        );

        $this->previewContact($result->contact->id);
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Agent Presence
    // ──────────────────────────────────────

    public function setAgentStatus(string $status): void
    {
        if (! $this->agentId) {
            return;
        }
        $agent = Agent::findOrFail($this->agentId);
        $validStatuses = ['available', 'break', 'offline'];
        if (! in_array($status, $validStatuses)) {
            return;
        }
        app(AgentStateService::class)->setStatus($agent, $status);
        $this->agentStatus = $status;
    }

    // ──────────────────────────────────────
    // Call Controls
    // ──────────────────────────────────────

    // ──────────────────────────────────────
    // Session Management (Persistent Conference)
    // ──────────────────────────────────────

    public function startDialerSession(int $campaignId): array
    {
        if (! $this->agent) {
            return ['error' => 'No agent'];
        }

        $campaign = Campaign::where('id', $campaignId)->where('status', 'active')->first();
        if (! $campaign) {
            return ['error' => 'Campaign not found or not active'];
        }

        $service = app(DialerOrchestrationService::class);
        $session = $service->startSession($this->agent, $campaign);

        $this->activeSessionId = $session->id;
        $this->selectedCampaignId = $campaign->id;
        $this->agentStatus = 'available';

        return [
            'sessionId' => $session->id,
            'conferenceName' => $session->conference_name,
        ];
    }

    public function endDialerSession(): void
    {
        if (! $this->activeSessionId) {
            return;
        }

        $session = DialerSession::find($this->activeSessionId);
        if ($session) {
            app(DialerOrchestrationService::class)->endSession($session);
        }

        $this->activeSessionId = null;
        $this->agentStatus = 'offline';
        $this->resetCallState();
    }

    public function pauseDialerSession(): void
    {
        if (! $this->activeSessionId) {
            return;
        }

        $session = DialerSession::find($this->activeSessionId);
        if ($session) {
            app(DialerOrchestrationService::class)->pauseSession($session);
        }
    }

    public function resumeDialerSession(): void
    {
        if (! $this->activeSessionId) {
            return;
        }

        $session = DialerSession::find($this->activeSessionId);
        if ($session) {
            app(DialerOrchestrationService::class)->resumeSession($session);
        }
    }

    public function dialNextFromSession(): array
    {
        if (! $this->activeSessionId) {
            return ['error' => 'No active session'];
        }

        $session = DialerSession::with('campaign')->find($this->activeSessionId);
        if (! $session || ! $session->isActive()) {
            return ['error' => 'Session not active'];
        }

        $service = app(DialerOrchestrationService::class);
        $call = $service->dialNext($session);

        if (! $call) {
            return ['error' => 'queue_empty'];
        }

        // Set UI state
        $this->setActiveCallFromCall($call->load('contact'));
        $this->agentStatus = 'ringing';
        $this->showDisposition = false;
        $this->clearCaches();

        return [
            'contactId' => $call->contact_id,
            'callId' => $call->id,
        ];
    }

    private function setActiveCallFromCall(Call $call): void
    {
        $this->activeCallId = $call->id;
        $this->activeCallUlid = $call->ulid;
        $this->callStatus = 'ringing';
        $this->callingFrom = $call->from_number;
        $this->previewContactId = null;
        $this->activeContact = [
            'id' => $call->contact->id,
            'name' => $call->contact->getFullName(),
            'phone' => $call->contact->getDisplayPhone(),
            'email' => $call->contact->email,
            'city' => $call->contact->city,
            'state' => $call->contact->state,
            'tags' => $call->contact->tags,
            'last_contacted_at' => $call->contact->last_contacted_at?->diffForHumans(),
            'total_calls' => $call->contact->total_calls,
            'total_sms' => $call->contact->total_sms,
        ];

        $this->loadContactState($call->contact->id);
        $this->loadConversation($call->contact->id);
    }

    public function skipContact(): void
    {
        $this->resetCallState();
        $this->clearCaches();
    }

    public function submitDisposition(string $disposition, ?string $callbackAt = null): void
    {
        if (! $this->activeCallId) {
            return;
        }

        $call = Call::findOrFail($this->activeCallId);

        if ($callbackAt) {
            $call->update(['callback_at' => $callbackAt]);

            if ($disposition === 'callback') {
                $call->contact()->update(['follow_up_at' => $callbackAt]);
            }
        }

        $service = app(DialerOrchestrationService::class);
        $service->dispositionCall($call, $disposition, $this->dispositionNotes ?: null);

        $this->dispositionNotes = '';
        $this->resetCallState();
        $this->clearCaches();
        $this->dispatch('queue-reload');
    }

    // ──────────────────────────────────────
    // SMS Methods
    // ──────────────────────────────────────

    public function loadConversation(?int $contactId = null): void
    {
        $contactId ??= $this->activeContact['id'] ?? null;

        if (! $contactId) {
            $this->smsConversation = [];
            $this->smsContactId = null;

            return;
        }

        $this->smsContactId = $contactId;

        $contact = Contact::find($contactId);

        if (! $contact) {
            $this->smsConversation = [];

            return;
        }

        $service = app(ProspectorSmsService::class);
        $messages = $service->getConversation($contact, 50);

        $this->smsConversation = $messages->reverse()->values()->map(fn (SmsMessage $msg) => [
            'id' => $msg->id,
            'direction' => $msg->direction,
            'body' => $msg->body,
            'status' => $msg->status,
            'created_at' => $msg->created_at->format('g:i A'),
            'created_at_full' => $msg->created_at->format('M j, Y g:i A'),
        ])->toArray();
    }

    public function sendSms(): void
    {
        $body = trim($this->smsBody);

        if (! $body || ! $this->smsContactId) {
            return;
        }

        $contact = Contact::with('company')->find($this->smsContactId);

        if (! $contact || ! $contact->company) {
            return;
        }

        $agent = $this->agentId ? Agent::find($this->agentId) : null;
        $company = $contact->company;

        try {
            $service = app(ProspectorSmsService::class);
            $service->sendSms($company, $contact, $body, $agent);

            $this->smsBody = '';
            $this->loadConversation($this->smsContactId);
        } catch (\Exception $e) {
            // Error is logged by the service
        }
    }

    public function onSmsReceived(array $data): void
    {
        $contactId = $data['contact_id'] ?? null;

        if ($contactId && $contactId === $this->smsContactId) {
            $this->loadConversation($this->smsContactId);
        }
    }

    // ──────────────────────────────────────
    // Transfer Controls
    // ──────────────────────────────────────

    #[Computed]
    public function onlineAgents(): \Illuminate\Support\Collection
    {
        if (! $this->agentId) {
            return collect();
        }

        $agent = Agent::find($this->agentId);

        if (! $agent) {
            return collect();
        }

        return Agent::with('user')
            ->forCompany($agent->company_id)
            ->online()
            ->where('id', '!=', $this->agentId)
            ->get();
    }

    public function openTransferPanel(): void
    {
        $this->showTransferPanel = true;
        $this->transferNumber = '';
        $this->transferStatus = 'idle';
        $this->transferCallSid = null;
        $this->transferAgentId = null;
        unset($this->onlineAgents);
    }

    public function closeTransferPanel(): void
    {
        $this->showTransferPanel = false;
        $this->transferNumber = '';
        $this->transferStatus = 'idle';
        $this->transferCallSid = null;
        $this->transferAgentId = null;
    }

    public function warmTransfer(): void
    {
        if (! $this->activeCallId || ! $this->transferNumber) {
            return;
        }

        $call = Call::with('fromPhoneNumber')->findOrFail($this->activeCallId);

        if (! $call->fromPhoneNumber) {
            return;
        }

        $conferenceName = $call->twilio_conference_sid;

        if (! $conferenceName) {
            return;
        }

        $service = app(ConferenceService::class);
        $this->transferCallSid = $service->warmTransfer($conferenceName, $this->transferNumber, $call->fromPhoneNumber);
        $this->transferStatus = 'ringing';
    }

    public function coldTransfer(): void
    {
        if (! $this->activeCallId || ! $this->transferNumber) {
            return;
        }

        $call = Call::with('fromPhoneNumber')->findOrFail($this->activeCallId);

        if (! $call->fromPhoneNumber) {
            return;
        }

        $conferenceName = $call->twilio_conference_sid;

        if (! $conferenceName) {
            return;
        }

        $service = app(ConferenceService::class);
        $service->coldTransfer($conferenceName, $call->twilio_call_sid, $this->transferNumber, $call->fromPhoneNumber);

        $this->transferStatus = 'connected';
        $this->closeTransferPanel();
        $this->callStatus = 'ended';
        $this->showDisposition = true;
    }

    public function transferToAgent(int $agentId): void
    {
        if (! $this->activeCallId) {
            return;
        }

        $call = Call::with('fromPhoneNumber')->findOrFail($this->activeCallId);

        if (! $call->fromPhoneNumber) {
            return;
        }

        $conferenceName = $call->twilio_conference_sid;

        if (! $conferenceName) {
            return;
        }

        $targetAgent = Agent::findOrFail($agentId);
        $service = app(ConferenceService::class);
        $this->transferCallSid = $service->transferToAgent($conferenceName, $targetAgent->id);
        $this->transferStatus = 'ringing';
        $this->transferAgentId = $agentId;
    }

    public function completeTransfer(): void
    {
        if (! $this->activeCallId) {
            return;
        }

        $call = Call::findOrFail($this->activeCallId);
        $conferenceName = $call->twilio_conference_sid;

        if (! $conferenceName || ! $call->twilio_call_sid) {
            return;
        }

        // Remove the original agent from the conference
        $service = app(ConferenceService::class);
        $service->removeParticipant($conferenceName, $call->twilio_call_sid);

        $this->closeTransferPanel();
        $this->callStatus = 'ended';
        $this->showDisposition = true;
    }

    public function cancelTransfer(): void
    {
        if (! $this->transferCallSid || ! $this->activeCallId) {
            $this->closeTransferPanel();

            return;
        }

        $call = Call::findOrFail($this->activeCallId);
        $conferenceName = $call->twilio_conference_sid;

        if ($conferenceName) {
            $service = app(ConferenceService::class);
            $service->removeParticipant($conferenceName, $this->transferCallSid);
        }

        $this->transferStatus = 'idle';
        $this->transferCallSid = null;
        $this->transferAgentId = null;
    }

    // ──────────────────────────────────────
    // Echo Listeners (called from JS)
    // ──────────────────────────────────────

    public function onCallRinging(array $data): void
    {
        $this->callStatus = 'ringing';
        $this->activeCallUlid = $data['call_ulid'] ?? null;
        $this->activeContact = $data['contact'] ?? null;
        $this->agentStatus = 'ringing';
    }

    public function onCallConnected(array $data): void
    {
        $this->callStatus = 'connected';
        $this->amdResult = $data['amd_result'] ?? null;
        $this->agentStatus = 'on_call';

        // In parallel mode, find and highlight the winning line
        if ($this->dialerMode === 'parallel') {
            $connectedCallId = $data['call_id'] ?? null;

            foreach ($this->parallelLines as $i => &$line) {
                if ($line['call_id'] == $connectedCallId) {
                    $line['status'] = 'connected';
                    $this->connectedLineIndex = $i;
                    $this->activeCallId = $connectedCallId;

                    // Update active contact to the winning line's contact
                    $call = Call::with('contact')->find($connectedCallId);

                    if ($call) {
                        $this->setActiveCallFromCall($call);
                        $this->callStatus = 'connected';
                    }
                } else {
                    $line['status'] = 'canceled';
                }
            }

            unset($line);
        }
    }

    public function onCallEnded(array $data): void
    {
        $this->callStatus = 'ended';
        $this->showDisposition = true;
        $this->supervisorName = null;
        $this->supervisorMode = null;
        $this->clearCaches();
    }

    public function onSupervisorJoined(array $data): void
    {
        $this->supervisorName = $data['supervisor_name'] ?? 'Manager';
        $this->supervisorMode = $data['mode'] ?? 'listen';
    }

    public function onInboundCallRinging(array $data): void
    {
        $this->activeCallId = $data['call_id'] ?? null;
        $this->activeContact = $data['contact'] ?? null;
        $this->callStatus = 'ringing';
        $this->callingFrom = $data['from_number'] ?? null;

        if ($contactId = $data['contact']['id'] ?? null) {
            $this->previewContactId = $contactId;
            $this->loadContactState($contactId);
        }

        $this->clearCaches();
    }

    public function updatedFilterCompanyId($value): void
    {
        if ($value === '' || $value === '0') {
            $this->filterCompanyId = null;
        }
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    public function resetCallState(): void
    {
        $this->activeCallId = null;
        $this->activeCallUlid = null;
        $this->activeContact = null;
        $this->callStatus = 'idle';
        $this->callingFrom = null;
        $this->amdResult = null;
        $this->showDisposition = false;
        $this->isMuted = false;
        $this->smsConversation = [];
        $this->smsContactId = null;
        $this->smsBody = '';
        $this->previewContactId = null;
        $this->companyNotes = '';
        $this->contactPipelineStageId = null;
        $this->contactFollowUpAt = null;
        $this->newContactNote = '';
        $this->callbackDate = null;
        $this->showTransferPanel = false;
        $this->transferNumber = '';
        $this->transferStatus = 'idle';
        $this->transferCallSid = null;
        $this->transferAgentId = null;
        $this->supervisorName = null;
        $this->supervisorMode = null;
    }

    protected function clearCaches(): void
    {
        unset(
            $this->agent,
            $this->campaigns,
            $this->agentCampaigns,
            $this->assignedCompanies,
            $this->queueContacts,
            $this->queuedCount,
            $this->activeCall,
            $this->contactDetail,
            $this->contactCallHistory,
            $this->bookingLinks,
            $this->pipelineStages,
            $this->rvmTemplates,
            $this->activeScript,
        );
    }

    protected function getStatusColor(string $status): string
    {
        return match ($status) {
            'available' => 'green',
            'on_call' => 'blue',
            'ringing' => 'amber',
            'wrap_up' => 'purple',
            'break' => 'orange',
            'offline' => 'zinc',
            default => 'zinc',
        };
    }

    protected function getStatusDotClass(string $status): string
    {
        return match ($status) {
            'available' => 'bg-green-500',
            'on_call' => 'bg-blue-500',
            'ringing' => 'bg-amber-500 animate-pulse',
            'wrap_up' => 'bg-purple-500',
            'break' => 'bg-orange-500',
            'offline' => 'bg-zinc-400',
            default => 'bg-zinc-400',
        };
    }
}; ?>

{{-- SoftphoneManager instance stored outside Alpine to avoid Proxy conflicts --}}
<script>
    window.__softphone = null;
</script>

<div
    x-data="{
        callTimer: 0,
        timerInterval: null,
        muted: $wire.entangle('isMuted'),
        smsOpen: $wire.entangle('smsOpen'),

        phoneReady: false,
        phoneError: null,
        phoneState: 'idle',
        phoneCompanyId: null,

        // Session state
        sessionState: 'idle', // idle, starting, ready, dialing, on_call, disposition
        sessionError: null,
        autoDial: false,
        dialerSession: null,

        // UI state
        showDtmf: false,
        showManualDial: false,
        showCallbackPicker: false,
        showScript: true,

        // Queue state
        queueItems: [],
        syncInterval: null,

        async loadQueue() {
            if ($wire.selectedCampaignId) {
                this.queueItems = await $wire.syncCampaignQueue();
            } else {
                this.queueItems = [];
            }
        },

        removeFromQueue(contactId) {
            this.queueItems = this.queueItems.filter(c => c.id !== contactId);
        },

        startTimer() {
            this.callTimer = 0;
            this.timerInterval = setInterval(() => { this.callTimer++ }, 1000);
        },

        stopTimer() {
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
        },

        formatTime(seconds) {
            const m = Math.floor(seconds / 60).toString().padStart(2, '0');
            const s = (seconds % 60).toString().padStart(2, '0');
            return m + ':' + s;
        },

        get sessionStatusText() {
            switch (this.sessionState) {
                case 'idle': return 'Select a campaign and press Start';
                case 'starting': return 'Connecting...';
                case 'ready': return this.autoDial ? `Auto-dialing · ${this.queueItems.length} remaining` : 'Paused · Conference active';
                case 'dialing': return 'Dialing...';
                case 'on_call': return 'Connected · ' + this.formatTime(this.callTimer);
                case 'disposition': return 'Disposition';
                default: return '';
            }
        },

        async initDialerSession() {
            // Don't re-create if already initialized (survives Livewire re-renders)
            if (window.__softphone && window.__softphone.isReady) {
                this.phoneReady = true;
                this.phoneCompanyId = window.__softphone.companyId;
                return;
            }

            const SoftphoneManager = await window.loadSoftphone();
            window.__softphone = new SoftphoneManager();
            const phone = window.__softphone;

            phone.on('ready', (companyId) => {
                this.phoneReady = true;
                this.phoneError = null;
                this.phoneCompanyId = companyId;
            });

            phone.on('error', (msg) => {
                const errorStr = typeof msg === 'string' ? msg : (msg?.message || String(msg));
                this.phoneError = errorStr;
                this.sessionError = errorStr;
            });

            phone.on('state-change', (state) => {
                this.phoneState = state;
            });

            phone.on('incoming', (info) => {
                $wire.onInboundCallRinging({
                    from_number: info.from,
                    call_sid: info.callSid,
                });
            });

            phone.on('incoming-canceled', () => {
                this.phoneState = 'idle';
                $wire.onCallEnded({});
            });

            phone.on('disconnected', () => {
                this.stopTimer();
            });

            await phone.init();

            // Load DialerSession module and wire events
            const DialerSessionClass = await window.loadDialerSession();
            this.dialerSession = new DialerSessionClass(phone, $wire);

            this.dialerSession.on('state-change', (state) => {
                this.sessionState = state;
            });

            this.dialerSession.on('error', (msg) => {
                this.sessionError = msg;
                setTimeout(() => { this.sessionError = null; }, 10000);
            });

            this.dialerSession.on('queue-empty', () => {
                this.sessionError = 'Queue is empty — no more contacts to call';
                this.autoDial = false;
            });

            this.dialerSession.on('session-started', () => {
                this.loadQueue();
            });

            this.dialerSession.on('session-ended', () => {
                this.stopTimer();
            });
        },

        async startSession() {
            if (!$wire.selectedCampaignId) {
                this.sessionError = 'Select a campaign first';
                return;
            }
            await this.dialerSession.start($wire.selectedCampaignId);
            this.autoDial = true;
            this.dialerSession.startAutoDial();
        },

        pauseSession() {
            this.autoDial = false;
            this.dialerSession.pause();
        },

        resumeSession() {
            this.autoDial = true;
            this.dialerSession.resume();
        },

        async stopSession() {
            this.autoDial = false;
            await this.dialerSession.stop();
        },

        answerInbound() {
            if (window.__softphone) window.__softphone.accept();
        },

        declineInbound() {
            if (window.__softphone) window.__softphone.reject();
            $wire.resetCallState();
        },

        toggleMute() {
            if (window.__softphone) {
                const newMuted = !this.muted;
                window.__softphone.mute(newMuted);
                this.muted = newMuted;
            }
        },

        hangUp() {
            if (window.__softphone) window.__softphone.hangup();
        },

        sendDtmf(digit) {
            if (window.__softphone) window.__softphone.sendDtmf(digit);
        },

        // Track subscribed company channel IDs for cleanup
        subscribedCompanyIds: [],

        init() {
            // Initialize SoftphoneManager + DialerSession
            this.initDialerSession();

            // Listen for Livewire dispatches
            this.$wire.on('queue-reload', () => {
                this.loadQueue();
            });

            this.$wire.on('dial-error', ({ message }) => {
                this.sessionError = 'Call failed: ' + message;
                setTimeout(() => { this.sessionError = null; }, 10000);
            });

            // Load initial queue
            this.loadQueue();

            // Background sync every 30 seconds
            this.syncInterval = setInterval(() => { this.loadQueue(); }, 30000);

            // Re-fetch queue when server-side filters change
            $watch('$wire.selectedCampaignId', () => { this.loadQueue(); });
            $watch('$wire.queueSearch', () => { this.loadQueue(); });

            if (typeof Echo !== 'undefined' && $wire.agentId) {
                Echo.private('prospector.agent.' + $wire.agentId)
                    .listen('.dialer.call.ringing', (e) => {
                        $wire.onCallRinging(e);
                    })
                    .listen('.inbound.call.ringing', (e) => {
                        try {
                            const audio = new Audio('/sounds/new-lead.wav');
                            audio.volume = 0.8;
                            audio.play().catch(() => {});
                        } catch (err) {}

                        if ('Notification' in window && Notification.permission === 'granted') {
                            new Notification('Inbound Call!', {
                                body: e.contact.name + ' - ' + e.company_name + '\n' + e.from_number,
                                icon: '/favicon.ico',
                            });
                        }

                        this.loadQueue();
                        $wire.onInboundCallRinging(e);
                    })
                    .listen('.dialer.call.connected', (e) => {
                        $wire.onCallConnected(e);
                        this.startTimer();
                        if (this.dialerSession) {
                            this.dialerSession.onCallConnected(e.contact?.id);
                        }
                    })
                    .listen('.dialer.call.ended', (e) => {
                        this.stopTimer();
                        this.hangUp();
                        $wire.onCallEnded(e);
                        if (this.dialerSession) {
                            if (e.disposition === 'no_answer' || e.disposition === 'busy') {
                                this.dialerSession.onNoAnswer();
                            } else {
                                this.dialerSession.onCallEnded();
                            }
                        }
                    })
                    .listen('.supervisor.joined', (e) => {
                        $wire.onSupervisorJoined(e);
                    });

                // Subscribe to ALL assigned company channels (not just the agent's FK)
                $wire.getAssignedCompanyIds().then((companyIds) => {
                    this.subscribedCompanyIds = companyIds || [];
                    this.subscribedCompanyIds.forEach((cid) => {
                        Echo.private('prospector.company.' + cid)
                            .listen('.sms.received', (e) => {
                                $wire.onSmsReceived(e);
                            })
                            .listen('.lead.arrived', (e) => {
                                try {
                                    const audio = new Audio('/sounds/new-lead.wav');
                                    audio.volume = 0.5;
                                    audio.play().catch(() => {});
                                } catch (err) {}

                                if ('Notification' in window && Notification.permission === 'granted') {
                                    new Notification('New Lead!', {
                                        body: e.contact_name + ' - ' + e.company_name,
                                        icon: '/favicon.ico',
                                    });
                                }

                                this.loadQueue();
                            })
                            .listen('.queue.updated', (e) => {
                                this.loadQueue();
                            })
                            .listen('.queue.item.claimed', (e) => {
                                if (e.claimed_by_agent_id !== $wire.agentId) {
                                    this.removeFromQueue(e.contact_id);
                                }
                            });
                    });

                    if ('Notification' in window && Notification.permission === 'default') {
                        Notification.requestPermission();
                    }
                });
            }
        },

        destroy() {
            this.stopTimer();
            if (this.syncInterval) {
                clearInterval(this.syncInterval);
                this.syncInterval = null;
            }
            // Leave all subscribed company channels
            if (typeof Echo !== 'undefined') {
                this.subscribedCompanyIds.forEach((cid) => {
                    Echo.leave('prospector.company.' + cid);
                });
            }
            // NOTE: Do NOT destroy softphone or dialer session here.
            // Alpine destroy() fires on Livewire re-renders (morphs), not just page unload.
            // The softphone and session must persist across re-renders.
            // They are cleaned up explicitly via stopSession() or page unload.
            if (typeof Echo !== 'undefined' && $wire.agentId) {
                Echo.leave('prospector.agent.' + $wire.agentId);
            }
        }
    }"
>
    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- SESSION CONTROLS + CAMPAIGN SELECTOR                              --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
        <div class="mb-4 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            {{-- Top bar: campaign picker + start/pause/stop controls --}}
            <div class="flex items-center gap-3 border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
                <flux:select wire:model.live="selectedCampaignId" size="sm" class="w-48">
                    <option value="">Select Campaign</option>
                    @foreach ($this->agentCampaigns as $campaign)
                        <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                    @endforeach
                </flux:select>

                <template x-if="sessionState === 'idle'">
                    <flux:button size="sm" variant="primary" icon="play" x-on:click="startSession()">
                        Start
                    </flux:button>
                </template>
                <template x-if="sessionState !== 'idle' && autoDial">
                    <flux:button size="sm" variant="filled" icon="pause" x-on:click="pauseSession()">
                        Pause
                    </flux:button>
                </template>
                <template x-if="sessionState !== 'idle' && !autoDial">
                    <div class="flex gap-2">
                        <flux:button size="sm" variant="primary" icon="play" x-on:click="resumeSession()">
                            Resume
                        </flux:button>
                        <flux:button size="sm" variant="danger" icon="stop" x-on:click="stopSession()">
                            Stop
                        </flux:button>
                    </div>
                </template>

                <div class="ml-auto text-xs text-zinc-500" x-text="sessionStatusText"></div>
            </div>

            {{-- Session error banner --}}
            <template x-if="sessionError">
                <div class="flex items-center gap-2 border-b border-red-200 bg-red-50 px-4 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                    <flux:icon name="exclamation-triangle" variant="micro" class="shrink-0" />
                    <span x-text="sessionError"></span>
                    <button type="button" x-on:click="sessionError = null" class="ml-auto text-red-500 hover:text-red-700">
                        <flux:icon name="x-mark" variant="micro" />
                    </button>
                </div>
            </template>

    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- HORIZONTAL QUEUE CARDS (campaign-driven)                          --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
            <div class="flex gap-2 overflow-x-auto border-b border-zinc-200 px-4 py-2 dark:border-zinc-700"
                 x-ref="queueScroll">
                <template x-for="item in queueItems" :key="item.id">
                    <div class="flex w-36 flex-shrink-0 cursor-pointer flex-col rounded-lg border border-zinc-200 p-2 text-xs hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                         x-on:click="$wire.previewContact(item.id)"
                         :class="{ 'ring-2 ring-blue-500': $wire.previewContactId === item.id }">
                        <div class="mb-1 flex items-center gap-1.5">
                            <span class="inline-block size-2 flex-shrink-0 rounded-full" :style="'background:' + (item.company_color || '#6b7280')"></span>
                            <span class="truncate font-medium text-zinc-900 dark:text-zinc-100" x-text="item.name"></span>
                        </div>
                        <div class="truncate text-zinc-500" x-text="item.phone"></div>
                        <div class="mt-0.5 text-zinc-400" x-text="item.calls_today !== undefined ? ('Call ' + item.calls_today + '/' + (item.max_calls_today || '?') + ' · ' + (item.total_attempts || 0) + '/' + (item.max_total || '?')) : ''"></div>
                    </div>
                </template>
                <div x-show="queueItems.length === 0" class="py-3 text-xs text-zinc-400">
                    <template x-if="!$wire.selectedCampaignId">
                        <span>Select a campaign to see the queue</span>
                    </template>
                    <template x-if="$wire.selectedCampaignId">
                        <span>No contacts in queue</span>
                    </template>
                </div>
            </div>

            {{-- Queue footer --}}
            <div class="flex items-center justify-between px-4 py-2">
                <span class="text-xs text-zinc-500"><span x-text="queueItems.length"></span> in queue</span>
                <flux:input wire:model.live.debounce.300ms="queueSearch" size="sm" placeholder="Search..." class="w-40" icon="magnifying-glass" />
            </div>
        </div>

    {{-- Supervisor Indicator --}}
    @if ($supervisorMode)
        <div class="mb-4 flex items-center gap-2 rounded-lg border px-4 py-2
            {{ $supervisorMode === 'barge' ? 'border-red-300 bg-red-50 dark:border-red-700 dark:bg-red-900/20' :
               ($supervisorMode === 'whisper' ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20' :
               'border-blue-300 bg-blue-50 dark:border-blue-700 dark:bg-blue-900/20') }}"
        >
            <flux:icon
                name="{{ $supervisorMode === 'barge' ? 'megaphone' : ($supervisorMode === 'whisper' ? 'chat-bubble-bottom-center-text' : 'eye') }}"
                variant="mini"
                class="{{ $supervisorMode === 'barge' ? 'text-red-600 dark:text-red-400' :
                    ($supervisorMode === 'whisper' ? 'text-amber-600 dark:text-amber-400' :
                    'text-blue-600 dark:text-blue-400') }}"
            />
            <span class="text-sm font-medium
                {{ $supervisorMode === 'barge' ? 'text-red-800 dark:text-red-200' :
                    ($supervisorMode === 'whisper' ? 'text-amber-800 dark:text-amber-200' :
                    'text-blue-800 dark:text-blue-200') }}"
            >
                @if ($supervisorMode === 'listen')
                    {{ $supervisorName }} {{ __('is listening') }}
                @elseif ($supervisorMode === 'whisper')
                    {{ $supervisorName }} {{ __('is coaching') }}
                @elseif ($supervisorMode === 'barge')
                    {{ $supervisorName }} {{ __('joined the call') }}
                @endif
            </span>
        </div>
    @endif


    {{-- ════════════════════════════════════════════════════════════════════ --}}
    {{-- 3-COLUMN LAYOUT (always visible)                                    --}}
    {{-- ════════════════════════════════════════════════════════════════════ --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">

            {{-- ──────────────────────────────────────────────────────── --}}
            {{-- LEFT COLUMN: Contact Info, Company, Notes, History      --}}
            {{-- ──────────────────────────────────────────────────────── --}}
            <div class="space-y-4 lg:col-span-3">
                @if ($this->contactDetail)
                    @php $cd = $this->contactDetail; @endphp

                    {{-- Contact Info --}}
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                            <div class="flex items-center justify-between">
                                <flux:heading size="sm">Contact</flux:heading>
                                @if ($callStatus === 'idle')
                                    <flux:button size="xs" variant="primary" icon="phone" wire:click="dialContact({{ $cd->id }})">
                                        Call
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                        <div class="space-y-3 p-4">
                            <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $cd->getFullName() }}</div>

                            <div class="space-y-1.5 text-sm">
                                <div class="flex items-center gap-2">
                                    <flux:icon name="phone" variant="micro" class="shrink-0 text-zinc-400" />
                                    <span class="font-mono">{{ $cd->getDisplayPhone() }}</span>
                                </div>
                                @if ($cd->email)
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="envelope" variant="micro" class="shrink-0 text-zinc-400" />
                                        <span class="truncate">{{ $cd->email }}</span>
                                    </div>
                                @endif
                                @if ($cd->city && $cd->state)
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="map-pin" variant="micro" class="shrink-0 text-zinc-400" />
                                        <span>{{ $cd->city }}, {{ $cd->state }}</span>
                                    </div>
                                @endif
                            </div>

                            @if ($cd->tags)
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($cd->tags as $tag)
                                        <flux:badge size="sm" color="zinc">{{ $tag }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif

                            <div class="space-y-1.5 border-t border-zinc-200 pt-3 text-sm dark:border-zinc-700">
                                @if ($cd->last_contacted_at)
                                    <div class="flex justify-between">
                                        <span class="text-zinc-500">Last Contact</span>
                                        <span class="font-medium {{ $cd->wasContactedToday() ? 'text-green-600 dark:text-green-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                                            {{ $cd->last_contacted_at->diffForHumans() }}
                                        </span>
                                    </div>
                                @endif
                                @if ($cd->follow_up_at)
                                    @php
                                        $fuColor = 'text-zinc-900 dark:text-zinc-100';
                                        if ($cd->follow_up_at->isPast()) $fuColor = 'text-red-600 dark:text-red-400';
                                        elseif ($cd->follow_up_at->isToday()) $fuColor = 'text-amber-600 dark:text-amber-400';
                                        else $fuColor = 'text-green-600 dark:text-green-400';
                                    @endphp
                                    <div class="flex justify-between">
                                        <span class="text-zinc-500">Follow-up</span>
                                        <span class="font-medium {{ $fuColor }}">{{ $cd->follow_up_at->format('M j') }}</span>
                                    </div>
                                @endif
                                <div class="flex justify-between">
                                    <span class="text-zinc-500">Calls</span>
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $cd->total_calls }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-zinc-500">SMS</span>
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $cd->total_sms }}</span>
                                </div>
                                @if ($cd->source)
                                    <div class="flex justify-between">
                                        <span class="text-zinc-500">Source</span>
                                        <flux:badge size="sm" color="{{ $cd->source === 'webhook' ? 'blue' : ($cd->source === 'csv' ? 'amber' : 'zinc') }}">{{ $cd->source }}</flux:badge>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Company Info --}}
                    @if ($cd->company)
                        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                                <div class="flex items-center justify-between">
                                    <flux:heading size="sm">Company</flux:heading>
                                    <a href="{{ route('prospector.companies.show', $cd->company->id) }}" class="text-xs text-blue-600 hover:underline dark:text-blue-400">View</a>
                                </div>
                            </div>
                            <div class="space-y-3 p-4">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $cd->company->name }}</div>
                                <div>
                                    <label class="mb-1 block text-xs text-zinc-500">Notes</label>
                                    <textarea
                                        wire:model.blur="companyNotes"
                                        wire:change="saveCompanyNotes"
                                        rows="2"
                                        class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-xs text-zinc-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                                        placeholder="Add company notes..."
                                    ></textarea>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Contact Notes --}}
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                            <flux:heading size="sm">Notes</flux:heading>
                        </div>
                        <div class="max-h-[200px] overflow-y-auto p-4">
                            @forelse ($cd->notes->sortByDesc('created_at')->take(10) as $note)
                                <div class="mb-2 last:mb-0">
                                    <div class="text-xs text-zinc-500">
                                        {{ $note->user?->name ?? 'System' }} - {{ $note->created_at->format('M j, g:i A') }}
                                    </div>
                                    <div class="text-sm text-zinc-900 dark:text-zinc-100">{{ $note->body }}</div>
                                </div>
                            @empty
                                <div class="text-center text-xs text-zinc-400">No notes yet</div>
                            @endforelse
                        </div>
                        <div class="border-t border-zinc-200 p-3 dark:border-zinc-700">
                            <form wire:submit="addContactNote" class="flex gap-2">
                                <input
                                    type="text"
                                    wire:model="newContactNote"
                                    placeholder="Add a note..."
                                    class="flex-1 rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-xs focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                                />
                                <flux:button type="submit" size="xs" variant="primary">Add</flux:button>
                            </form>
                        </div>
                    </div>

                    {{-- Call History --}}
                    @if ($this->contactCallHistory->isNotEmpty())
                        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                                <flux:heading size="sm">Call History</flux:heading>
                            </div>
                            <div class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                                @foreach ($this->contactCallHistory as $histCall)
                                    <div class="flex items-center justify-between px-4 py-2.5">
                                        <div>
                                            <div class="text-xs text-zinc-500">{{ $histCall->created_at->format('M j, g:i A') }}</div>
                                            <div class="text-xs text-zinc-400">{{ $histCall->agent?->user?->name ?? 'Unknown' }}</div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            @if ($histCall->duration_seconds)
                                                <span class="font-mono text-xs text-zinc-500">{{ gmdate('i:s', $histCall->duration_seconds) }}</span>
                                            @endif
                                            @if ($histCall->disposition)
                                                <flux:badge size="sm" color="{{ match($histCall->disposition) {
                                                    'interested' => 'green',
                                                    'callback' => 'blue',
                                                    'not_interested', 'wrong_number', 'dnc', 'disconnected' => 'red',
                                                    default => 'amber',
                                                } }}">{{ str_replace('_', ' ', $histCall->disposition) }}</flux:badge>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @else
                    <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                        <flux:icon name="user-circle" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                        <flux:text class="text-zinc-400">Select a contact from the queue above.</flux:text>
                    </div>
                @endif
            </div>

            {{-- ──────────────────────────────────────────────────────── --}}
            {{-- CENTER COLUMN: Softphone, Call, Disposition, SMS       --}}
            {{-- ──────────────────────────────────────────────────────── --}}
            <div class="space-y-4 lg:col-span-5">

                {{-- Softphone Status Bar — always visible --}}
                <div class="rounded-lg border bg-white dark:bg-zinc-800"
                    :class="phoneReady ? 'border-green-300 dark:border-green-700' : (phoneError ? 'border-red-300 dark:border-red-700' : 'border-amber-300 dark:border-amber-700')"
                >
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex size-2.5 rounded-full" :class="phoneReady ? 'bg-green-500' : (phoneError ? 'bg-red-500' : 'bg-amber-500 animate-pulse')"></span>
                            <span class="text-sm font-medium"
                                :class="phoneReady ? 'text-green-700 dark:text-green-400' : (phoneError ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400')"
                                x-text="phoneReady ? 'Phone Ready' : (phoneError ? 'Phone Error' : 'Phone Connecting...')"
                            ></span>
                            <template x-if="phoneError">
                                <span class="text-xs text-red-500 dark:text-red-400" x-text="phoneError"></span>
                            </template>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($callingFrom)
                                <span class="text-xs text-zinc-400">{{ $callingFrom }}</span>
                            @endif
                            @if ($callStatus === 'connected')
                                <span class="font-mono text-sm font-bold text-green-600 dark:text-green-400" x-text="formatTime(callTimer)">00:00</span>
                            @endif
                            <template x-if="!phoneReady">
                                <button type="button" x-on:click="initDialerSession()" class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Retry</button>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Call Controls — always visible --}}
                <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="p-4">
                        {{-- Parallel Lines Indicator --}}
                        @if ($dialerMode === 'parallel' && ! empty($parallelLines))
                            <div class="mb-4 space-y-2">
                                @foreach ($parallelLines as $i => $line)
                                    <div class="flex items-center gap-3 rounded-lg border px-3 py-2
                                        @if ($line['status'] === 'connected') border-green-300 bg-green-50 dark:border-green-700 dark:bg-green-900/20
                                        @elseif ($line['status'] === 'canceled') border-zinc-200 bg-zinc-50 opacity-50 dark:border-zinc-700 dark:bg-zinc-800/50
                                        @elseif ($line['status'] === 'ringing') border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20
                                        @else border-zinc-200 dark:border-zinc-700
                                        @endif
                                    ">
                                        <div class="flex size-6 items-center justify-center rounded-full text-xs font-bold
                                            @if ($line['status'] === 'connected') bg-green-500 text-white
                                            @elseif ($line['status'] === 'canceled') bg-zinc-300 text-zinc-500 dark:bg-zinc-600 dark:text-zinc-400
                                            @elseif ($line['status'] === 'ringing') bg-amber-500 text-white animate-pulse
                                            @else bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300
                                            @endif
                                        ">{{ $i + 1 }}</div>
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-sm font-medium {{ $line['status'] === 'canceled' ? 'text-zinc-400 line-through' : 'text-zinc-900 dark:text-zinc-100' }}">{{ $line['contact_name'] }}</div>
                                            <div class="font-mono text-xs text-zinc-500">{{ $line['phone'] }}</div>
                                        </div>
                                        <flux:badge size="sm" color="{{ match($line['status']) { 'connected' => 'green', 'canceled' => 'zinc', 'ringing' => 'amber', default => 'zinc' } }}">{{ ucfirst($line['status']) }}</flux:badge>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($callStatus === 'ringing' && $dialerMode !== 'parallel')
                            {{-- Ringing state --}}
                            <div class="text-center">
                                <div class="mx-auto mb-3 flex size-14 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                                    <flux:icon name="phone" class="size-7 animate-pulse text-amber-600 dark:text-amber-400" />
                                </div>
                                <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $activeContact['name'] ?? 'Unknown' }}</div>
                                <div class="font-mono text-sm text-zinc-500">{{ $activeContact['phone'] ?? '' }}</div>
                                <div class="mt-2">
                                    <flux:badge color="amber">Ringing...</flux:badge>
                                </div>
                                <div class="mt-3 flex justify-center gap-2">
                                    <flux:button size="sm" variant="danger" icon="phone-x-mark" x-on:click="hangUp()">
                                        Hang Up
                                    </flux:button>
                                    <flux:button size="sm" variant="ghost" icon="forward" wire:click="skipContact">Skip</flux:button>
                                </div>
                            </div>
                        @elseif ($callStatus === 'connected')
                            {{-- Connected state --}}
                            <div class="text-center">
                                <div class="mx-auto mb-3 flex size-14 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                                    <flux:icon name="phone" class="size-7 text-green-600 dark:text-green-400" />
                                </div>
                                <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $activeContact['name'] ?? 'Unknown' }}</div>
                                <div class="font-mono text-sm text-zinc-500">{{ $activeContact['phone'] ?? '' }}</div>

                                @if ($amdResult)
                                    <div class="mt-1">
                                        <flux:badge size="sm" color="{{ $amdResult === 'human' ? 'green' : 'amber' }}">{{ ucfirst($amdResult) }}</flux:badge>
                                    </div>
                                @endif

                                <div class="mt-3 flex justify-center gap-2">
                                    <flux:button
                                        size="sm"
                                        :variant="$isMuted ? 'danger' : 'filled'"
                                        :icon="$isMuted ? 'speaker-x-mark' : 'speaker-wave'"
                                        x-on:click="toggleMute()"
                                    >
                                        <span x-text="muted ? 'Unmute' : 'Mute'">Mute</span>
                                    </flux:button>
                                    <flux:button size="sm" variant="filled" icon="hashtag" x-on:click="showDtmf = !showDtmf">
                                        DTMF
                                    </flux:button>
                                    <flux:button size="sm" variant="danger" icon="phone-x-mark" x-on:click="hangUp()">
                                        Hang Up
                                    </flux:button>
                                </div>

                                {{-- DTMF Keypad --}}
                                <div x-show="showDtmf" x-collapse class="mx-auto mt-3 max-w-[200px]">
                                    <div class="grid grid-cols-3 gap-1">
                                        @foreach (['1','2','3','4','5','6','7','8','9','*','0','#'] as $digit)
                                            <button
                                                type="button"
                                                x-on:click="sendDtmf('{{ $digit }}')"
                                                class="rounded-lg border border-zinc-200 bg-zinc-50 py-2 text-sm font-bold text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-600"
                                            >{{ $digit }}</button>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- Transfer button --}}
                                @if (! $showTransferPanel)
                                    <div class="mt-3">
                                        <flux:button size="sm" variant="ghost" icon="arrows-right-left" wire:click="openTransferPanel">
                                            Transfer
                                        </flux:button>
                                    </div>
                                @endif

                                {{-- Transfer Panel --}}
                                @if ($showTransferPanel)
                                    <div class="mt-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                                        <div class="mb-3 flex items-center justify-between">
                                            <flux:heading size="sm">Transfer Call</flux:heading>
                                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="closeTransferPanel" />
                                        </div>

                                        @if ($transferStatus === 'idle')
                                            <div class="space-y-3">
                                                <div>
                                                    <label class="mb-1 block text-xs text-zinc-500">Transfer to number</label>
                                                    <div class="flex gap-2">
                                                        <input
                                                            type="tel"
                                                            wire:model="transferNumber"
                                                            placeholder="Phone number..."
                                                            class="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                                                        />
                                                        <flux:button size="sm" variant="primary" wire:click="warmTransfer">Warm</flux:button>
                                                        <flux:button size="sm" variant="filled" wire:click="coldTransfer">Cold</flux:button>
                                                    </div>
                                                </div>

                                                @if ($this->onlineAgents->isNotEmpty())
                                                    <div>
                                                        <label class="mb-1 block text-xs text-zinc-500">Or transfer to agent</label>
                                                        <div class="space-y-1">
                                                            @foreach ($this->onlineAgents as $oa)
                                                                <button
                                                                    type="button"
                                                                    wire:click="transferToAgent({{ $oa->id }})"
                                                                    class="flex w-full items-center gap-2 rounded-md border border-zinc-200 px-3 py-2 text-left text-sm transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-700"
                                                                >
                                                                    <span class="inline-flex size-2 rounded-full bg-green-500"></span>
                                                                    <span>{{ $oa->user?->name ?? 'Agent #'.$oa->id }}</span>
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        @elseif ($transferStatus === 'ringing')
                                            <div class="py-4 text-center">
                                                <flux:badge color="amber">Ringing transfer...</flux:badge>
                                                <div class="mt-2 font-mono text-sm text-zinc-500">{{ $transferNumber }}</div>
                                                <flux:button size="sm" variant="danger" wire:click="cancelTransfer" class="mt-3">Cancel</flux:button>
                                            </div>
                                        @elseif ($transferStatus === 'connected')
                                            <div class="py-4 text-center">
                                                <flux:badge color="green">Transfer connected</flux:badge>
                                                <div class="mt-3 flex justify-center gap-2">
                                                    <flux:button size="sm" variant="primary" wire:click="completeTransfer">Complete</flux:button>
                                                    <flux:button size="sm" variant="danger" wire:click="cancelTransfer">Cancel</flux:button>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @else
                            {{-- Idle / ready state — session-based controls --}}
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <template x-if="sessionState === 'idle'">
                                        <span class="text-sm text-zinc-400">Select a campaign above and press Start to begin auto-dialing.</span>
                                    </template>
                                    <template x-if="sessionState === 'ready' && !autoDial">
                                        <flux:button variant="primary" size="sm" icon="play" x-on:click="resumeSession()">
                                            Resume Auto-Dial
                                        </flux:button>
                                    </template>
                                    <template x-if="sessionState === 'ready' && autoDial">
                                        <flux:badge color="blue">Auto-dialing...</flux:badge>
                                    </template>
                                </div>
                                <flux:button variant="ghost" size="sm" icon="hashtag" x-on:click="showManualDial = !showManualDial">
                                    Manual
                                </flux:button>
                            </div>
                            {{-- Manual Dial Input --}}
                            <div x-show="showManualDial" x-collapse class="mt-3">
                                <div class="flex gap-2">
                                    <input
                                        type="tel"
                                        wire:model="manualDialNumber"
                                        placeholder="Enter phone number..."
                                        class="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-2 font-mono text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                                    />
                                    <flux:button size="sm" variant="primary" icon="phone" wire:click="manualDial">Dial</flux:button>
                                </div>
                            </div>
                        @endif

                        {{-- Post-call disposition (after a call ends) --}}
                        @if ($showDisposition)
                            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <flux:heading size="sm" class="mb-3">Disposition</flux:heading>

                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    @foreach ($this->dispositions as $key => $label)
                                        @php
                                            $dispColor = match($key) {
                                                'interested' => 'bg-green-50 border-green-300 text-green-700 hover:bg-green-100 dark:bg-green-900/20 dark:border-green-700 dark:text-green-300',
                                                'callback' => 'bg-blue-50 border-blue-300 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/20 dark:border-blue-700 dark:text-blue-300',
                                                'not_interested', 'wrong_number', 'dnc', 'disconnected' => 'bg-red-50 border-red-300 text-red-700 hover:bg-red-100 dark:bg-red-900/20 dark:border-red-700 dark:text-red-300',
                                                default => 'bg-amber-50 border-amber-300 text-amber-700 hover:bg-amber-100 dark:bg-amber-900/20 dark:border-amber-700 dark:text-amber-300',
                                            };
                                        @endphp
                                        @if ($key === 'callback')
                                            <button
                                                type="button"
                                                x-on:click="showCallbackPicker = !showCallbackPicker"
                                                class="rounded-lg border px-3 py-2 text-left text-sm font-medium transition {{ $dispColor }}"
                                            >{{ $label }}</button>
                                        @else
                                            <button
                                                type="button"
                                                x-on:click="$wire.submitDisposition('{{ $key }}').then(() => { if (dialerSession) dialerSession.onDispositioned(); })"
                                                class="rounded-lg border px-3 py-2 text-left text-sm font-medium transition {{ $dispColor }}"
                                            >{{ $label }}</button>
                                        @endif
                                    @endforeach
                                </div>

                                {{-- Callback Date Picker --}}
                                <div x-show="showCallbackPicker" x-collapse class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-900/20">
                                    <label class="mb-1 block text-xs font-medium text-blue-700 dark:text-blue-300">Callback Date/Time</label>
                                    <div class="flex gap-2">
                                        <input
                                            type="datetime-local"
                                            wire:model="callbackDate"
                                            class="flex-1 rounded-md border border-blue-300 bg-white px-2 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-blue-600 dark:bg-zinc-700 dark:text-zinc-100"
                                        />
                                        <flux:button size="sm" variant="primary"
                                            x-on:click="$wire.submitDisposition('callback', callbackDate).then(() => { showCallbackPicker = false; if (dialerSession) dialerSession.onDispositioned(); })">
                                            Schedule
                                        </flux:button>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <flux:textarea wire:model="dispositionNotes" placeholder="Notes (optional)..." rows="2" />
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Voicemail Drop --}}
                @if ($callStatus === 'connected')
                    @if ($this->rvmTemplates->isNotEmpty())
                        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:heading size="sm" class="mb-2">Voicemail Drop</flux:heading>
                            <div class="flex gap-2">
                                <select
                                    id="rvm-template-select"
                                    class="flex-1 rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                                >
                                    @foreach ($this->rvmTemplates as $tpl)
                                        <option value="{{ $tpl->id }}">{{ $tpl->name }} ({{ $tpl->duration_seconds }}s)</option>
                                    @endforeach
                                </select>
                                <flux:button
                                    size="sm"
                                    variant="filled"
                                    icon="microphone"
                                    x-on:click="$wire.dropVoicemail(document.getElementById('rvm-template-select').value)"
                                >
                                    Drop VM
                                </flux:button>
                            </div>
                        </div>
                    @endif
                @endif

                {{-- Quick Disposition — always visible when contact selected --}}
                @if (($this->contactDetail || $previewContactId) && $callStatus === 'idle' && ! $showDisposition)
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                            <flux:heading size="sm">Quick Actions</flux:heading>
                        </div>
                        <div class="p-4">
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                @foreach ($this->dispositions as $key => $label)
                                    @php
                                        $dispColor = match($key) {
                                            'interested' => 'bg-green-50 border-green-300 text-green-700 hover:bg-green-100 dark:bg-green-900/20 dark:border-green-700 dark:text-green-300',
                                            'callback' => 'bg-blue-50 border-blue-300 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/20 dark:border-blue-700 dark:text-blue-300',
                                            'not_interested', 'wrong_number', 'dnc', 'disconnected' => 'bg-red-50 border-red-300 text-red-700 hover:bg-red-100 dark:bg-red-900/20 dark:border-red-700 dark:text-red-300',
                                            default => 'bg-amber-50 border-amber-300 text-amber-700 hover:bg-amber-100 dark:bg-amber-900/20 dark:border-amber-700 dark:text-amber-300',
                                        };
                                    @endphp
                                    @if ($key === 'callback')
                                        <button
                                            type="button"
                                            x-on:click="showCallbackPicker = !showCallbackPicker"
                                            class="rounded-lg border px-3 py-2 text-left text-sm font-medium transition {{ $dispColor }}"
                                        >{{ $label }}</button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="quickDisposition('{{ $key }}')"
                                            class="rounded-lg border px-3 py-2 text-left text-sm font-medium transition {{ $dispColor }}"
                                        >{{ $label }}</button>
                                    @endif
                                @endforeach
                            </div>

                            {{-- Callback Date Picker --}}
                            <div x-show="showCallbackPicker" x-collapse class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-900/20">
                                <label class="mb-1 block text-xs font-medium text-blue-700 dark:text-blue-300">Callback Date/Time</label>
                                <div class="flex gap-2">
                                    <input
                                        type="datetime-local"
                                        wire:model="callbackDate"
                                        class="flex-1 rounded-md border border-blue-300 bg-white px-2 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-blue-600 dark:bg-zinc-700 dark:text-zinc-100"
                                    />
                                    <flux:button size="sm" variant="primary" wire:click="quickDisposition('callback', callbackDate)" x-on:click="showCallbackPicker = false">
                                        Schedule
                                    </flux:button>
                                </div>
                            </div>

                            <div class="mt-3">
                                <flux:textarea wire:model="dispositionNotes" placeholder="Notes (optional)..." rows="2" />
                            </div>
                        </div>
                    </div>
                @endif

                {{-- SMS Conversation --}}
                @if ($this->contactDetail || $activeContact)
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700"
                            x-on:click="smsOpen = !smsOpen"
                        >
                            <div class="flex items-center gap-2">
                                <flux:icon name="chat-bubble-left-right" variant="micro" class="text-zinc-400" />
                                <flux:heading size="sm">SMS</flux:heading>
                                @if (count($smsConversation) > 0)
                                    <flux:badge size="sm" color="zinc">{{ count($smsConversation) }}</flux:badge>
                                @endif
                            </div>
                            <flux:icon name="chevron-down" variant="micro" class="text-zinc-400 transition-transform" x-bind:class="smsOpen ? 'rotate-180' : ''" />
                        </button>

                        <div x-show="smsOpen" x-collapse>
                            <div class="max-h-[250px] overflow-y-auto p-4">
                                @if (empty($smsConversation))
                                    <div class="py-4 text-center text-sm text-zinc-400">No messages yet</div>
                                @else
                                    <div class="space-y-2">
                                        @foreach ($smsConversation as $msg)
                                            <div class="flex {{ $msg['direction'] === 'outbound' ? 'justify-end' : 'justify-start' }}">
                                                <div
                                                    class="max-w-[80%] rounded-lg px-3 py-2 text-sm
                                                        @if ($msg['direction'] === 'outbound') bg-blue-600 text-white
                                                        @else bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-zinc-100
                                                        @endif
                                                    "
                                                    title="{{ $msg['created_at_full'] }}"
                                                >
                                                    <div>{{ $msg['body'] }}</div>
                                                    <div class="mt-1 text-[10px] {{ $msg['direction'] === 'outbound' ? 'text-blue-200' : 'text-zinc-400 dark:text-zinc-500' }}">
                                                        {{ $msg['created_at'] }}
                                                        @if ($msg['direction'] === 'outbound' && $msg['status'] === 'failed')
                                                            <span class="text-red-300">- Failed</span>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div class="border-t border-zinc-200 p-3 dark:border-zinc-700">
                                {{-- Quick-insert booking links --}}
                                @if ($this->bookingLinks->isNotEmpty())
                                    <div class="mb-2 flex flex-wrap gap-1">
                                        @foreach ($this->bookingLinks as $bl)
                                            <button
                                                type="button"
                                                wire:click="insertBookingLink({{ $bl->id }})"
                                                class="rounded bg-zinc-100 px-2 py-0.5 text-[10px] font-medium text-zinc-600 transition hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600"
                                                title="Insert {{ $bl->name }} link"
                                            >+ {{ $bl->name }}</button>
                                        @endforeach
                                    </div>
                                @endif
                                <form wire:submit="sendSms" class="flex gap-2">
                                    <input
                                        type="text"
                                        wire:model="smsBody"
                                        placeholder="Type a message..."
                                        class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100 dark:focus:border-blue-500"
                                    />
                                    <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane">Send</flux:button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Call Script --}}
                @if ($this->activeScript)
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700"
                            x-on:click="showScript = !showScript"
                        >
                            <div class="flex items-center gap-2">
                                <flux:icon name="document-text" variant="micro" class="text-zinc-400" />
                                <flux:heading size="sm">Script: {{ $this->activeScript->name }}</flux:heading>
                            </div>
                            <flux:icon name="chevron-down" variant="micro" class="text-zinc-400 transition-transform" x-bind:class="showScript ? 'rotate-180' : ''" />
                        </button>
                        <div x-show="showScript" x-collapse>
                            <div class="max-h-[300px] overflow-y-auto p-4 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
                                {!! nl2br(e($this->activeScript->body)) !!}
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- ──────────────────────────────────────────────────────── --}}
            {{-- RIGHT COLUMN: Booking Links, Pipeline, Follow-up        --}}
            {{-- ──────────────────────────────────────────────────────── --}}
            <div class="space-y-4 lg:col-span-4">
                {{-- Booking Links --}}
                @if ($this->bookingLinks->isNotEmpty())
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                            <flux:heading size="sm">Booking Links</flux:heading>
                        </div>
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                            @foreach ($this->bookingLinks as $bl)
                                <div class="flex items-center justify-between px-4 py-2.5">
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $bl->name }}</span>
                                    <div class="flex gap-1">
                                        <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" x-on:click="window.open('{{ $bl->url }}', '_blank')" title="Open" />
                                        <flux:button size="xs" variant="ghost" icon="clipboard-document" x-on:click="navigator.clipboard.writeText('{{ $bl->url }}')" title="Copy Link" />
                                        <flux:button size="xs" variant="ghost" icon="chat-bubble-left" wire:click="insertBookingLink({{ $bl->id }})" title="Insert into SMS" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Pipeline Stage --}}
                @if ($this->contactDetail || $activeContact)
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                            <flux:heading size="sm">Pipeline Stage</flux:heading>
                        </div>
                        <div class="space-y-3 p-4">
                            <select
                                wire:model.live="contactPipelineStageId"
                                wire:change="updateContactStage($event.target.value)"
                                class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                            >
                                <option value="">No Stage</option>
                                @php $lastPipeline = null; @endphp
                                @foreach ($this->pipelineStages as $stage)
                                    @if ($stage->pipeline && $stage->pipeline->name !== $lastPipeline)
                                        @if ($lastPipeline !== null)
                                            </optgroup>
                                        @endif
                                        <optgroup label="{{ $stage->pipeline->name }}">
                                        @php $lastPipeline = $stage->pipeline->name; @endphp
                                    @endif
                                    <option value="{{ $stage->id }}">{{ $stage->name }}</option>
                                @endforeach
                                @if ($lastPipeline !== null)
                                    </optgroup>
                                @endif
                            </select>
                        </div>
                    </div>

                    {{-- Follow-up Date --}}
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                            <flux:heading size="sm">Follow-up</flux:heading>
                        </div>
                        <div class="space-y-3 p-4">
                            <input
                                type="date"
                                wire:model="contactFollowUpAt"
                                wire:change="setFollowUp($event.target.value)"
                                class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                            />
                            @if ($contactFollowUpAt)
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="setFollowUp(null)" class="w-full">
                                    Clear Follow-up
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @endif

            </div>
        </div>

        {{-- ════════════════════════════════════════════════════════════════════ --}}
        {{-- AGENT PRESENCE BAR (Bottom, sticky)                                --}}
        {{-- ════════════════════════════════════════════════════════════════════ --}}
        <div class="flex items-center gap-3 border-t border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-800 sticky bottom-0 z-10 mt-4 rounded-lg border shadow-lg">
            <span class="text-xs font-medium text-zinc-500">Status:</span>
            <div class="flex gap-1">
                <button wire:click="setAgentStatus('available')"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $agentStatus === 'available' ? 'bg-green-500 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400' }}">
                    Available
                </button>
                <button wire:click="setAgentStatus('break')"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $agentStatus === 'break' ? 'bg-amber-500 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400' }}">
                    Break
                </button>
                <button wire:click="setAgentStatus('offline')"
                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $agentStatus === 'offline' ? 'bg-zinc-500 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400' }}">
                    Offline
                </button>
            </div>
            @if ($agentStatus === 'on_call')
                <flux:badge color="red" size="sm">On Call</flux:badge>
            @endif
        </div>
</div>
