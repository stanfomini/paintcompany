<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Services\Prospector\WebhookIntakeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Prospector Contacts')]
class extends Component
{
    use WithFileUploads, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $companyFilter = '';

    #[Url]
    public string $dncFilter = '';

    #[Url]
    public string $sourceFilter = '';

    #[Url]
    public string $sortBy = 'name';

    #[Url]
    public string $sortDir = 'asc';

    // Form state
    public bool $showModal = false;
    public ?int $editingId = null;
    public ?int $companyId = null;
    public string $firstName = '';
    public string $lastName = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $tagsInput = '';

    // Bulk actions
    public array $selectedContacts = [];
    public bool $showBulkCampaignModal = false;
    public ?int $bulkCampaignId = null;
    public ?int $bulkCampaignCompanyId = null;
    public bool $showBulkTagModal = false;
    public string $bulkTagInput = '';
    public bool $showBulkDeleteModal = false;

    // Delete
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingName = '';

    // CSV Import state
    public bool $showImportModal = false;
    public int $importStep = 1; // 1=upload, 2=map, 3=preview, 4=importing, 5=done
    public $csvFile = null;
    public array $csvHeaders = [];
    public array $csvPreview = [];
    public array $csvAllRows = [];
    public array $columnMapping = [];
    public ?int $importCompanyId = null;
    public bool $enrollInCampaign = false;
    public ?int $importCampaignId = null;
    public int $importTotal = 0;
    public int $importProcessed = 0;
    public int $importCreated = 0;
    public int $importUpdated = 0;
    public int $importSkipped = 0;
    public int $importErrorCount = 0;
    public array $importErrors = [];
    public array $validationWarnings = [];

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function contacts(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Contact::query()
            ->with('company')
            ->when($this->search, fn ($q) => $q->search($this->search))
            ->when($this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->when($this->dncFilter === '1', fn ($q) => $q->where('do_not_call', true))
            ->when($this->dncFilter === '0', fn ($q) => $q->where('do_not_call', false))
            ->when($this->sourceFilter, fn ($q) => $q->where('source', $this->sourceFilter));

        match ($this->sortBy) {
            'name' => $query->orderBy('first_name', $this->sortDir)->orderBy('last_name', $this->sortDir),
            'priority' => $query->orderBy('priority', $this->sortDir),
            'last_contacted_at' => $query->orderBy('last_contacted_at', $this->sortDir),
            default => $query->orderBy('first_name', $this->sortDir),
        };

        return $query->paginate(25);
    }

    #[Computed]
    public function companies(): \Illuminate\Support\Collection
    {
        return Company::query()->orderBy('name')->get();
    }

    #[Computed]
    public function availableSources(): array
    {
        return Contact::query()
            ->distinct()
            ->whereNotNull('source')
            ->orderBy('source')
            ->pluck('source')
            ->all();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => Contact::count(),
            'active' => Contact::where('do_not_call', false)->count(),
            'dnc' => Contact::where('do_not_call', true)->count(),
            'contacted_today' => Contact::whereDate('last_contacted_at', today())->count(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCompanyFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDncFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->companyFilter = '';
        $this->dncFilter = '';
        $this->sourceFilter = '';
        $this->resetPage();
    }

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $contact = Contact::findOrFail($id);
        $this->editingId = $contact->id;
        $this->companyId = $contact->company_id;
        $this->firstName = $contact->first_name;
        $this->lastName = $contact->last_name ?? '';
        $this->phone = $contact->phone;
        $this->email = $contact->email ?? '';
        $this->address = $contact->address ?? '';
        $this->tagsInput = $contact->tags ? implode(', ', $contact->tags) : '';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function save(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $validated = $this->validate([
            'companyId' => ['required', 'exists:prospector_companies,id'],
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'tagsInput' => ['nullable', 'string', 'max:500'],
        ]);

        $tags = $this->tagsInput
            ? array_map('trim', explode(',', $this->tagsInput))
            : null;

        $data = [
            'company_id' => $this->companyId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName ?: null,
            'phone' => $this->phone,
            'email' => $this->email ?: null,
            'address' => $this->address ?: null,
            'tags' => $tags,
        ];

        if ($this->editingId) {
            $contact = Contact::findOrFail($this->editingId);
            $contact->update($data);
        } else {
            $intake = app(\App\Services\Prospector\ContactIntakeService::class);
            $intake->findOrCreate(
                Company::find($this->companyId),
                $this->phone,
                [
                    'first_name' => $this->firstName,
                    'last_name' => $this->lastName ?: null,
                    'email' => $this->email ?: null,
                    'address' => $this->address ?: null,
                    'tags' => $tags,
                    'source' => 'manual',
                ],
            );
        }

        $this->closeModal();
        $this->clearCaches();
    }

    public function toggleDnc(int $id): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $contact = Contact::findOrFail($id);
        $contact->update(['do_not_call' => ! $contact->do_not_call]);
        $this->clearCaches();
    }

    public function confirmDelete(int $id): void
    {
        $contact = Contact::findOrFail($id);
        $this->deletingId = $id;
        $this->deletingName = $contact->getFullName();
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (! $this->deletingId) {
            return;
        }

        Contact::findOrFail($this->deletingId)->delete();
        $this->cancelDelete();
        $this->clearCaches();
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingName = '';
    }

    // ─── CSV Import Methods ─────────────────────────────────

    public function openImportModal(): void
    {
        $this->resetImport();
        $this->showImportModal = true;
    }

    public function closeImportModal(): void
    {
        $this->showImportModal = false;
        $this->resetImport();
    }

    public function updatedCsvFile(): void
    {
        $this->validate([
            'csvFile' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $this->parseCsvFile();
    }

    public function parseCsvFile(): void
    {
        if (! $this->csvFile) {
            return;
        }

        $path = $this->csvFile->getRealPath();
        $handle = fopen($path, 'r');
        if (! $handle) {
            return;
        }

        // Read headers
        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);
            $this->addError('csvFile', __('Could not parse CSV headers.'));

            return;
        }

        $this->csvHeaders = array_map('trim', $headers);

        // Read all rows
        $allRows = [];
        $preview = [];
        $rowIndex = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($this->csvHeaders)) {
                continue;
            }
            $mapped = array_combine($this->csvHeaders, $row);
            $allRows[] = $mapped;
            if ($rowIndex < 5) {
                $preview[] = $mapped;
            }
            $rowIndex++;
        }
        fclose($handle);

        $this->csvPreview = $preview;
        $this->csvAllRows = $allRows;
        $this->importTotal = count($allRows);

        // Auto-detect column mapping
        $this->columnMapping = $this->autoDetectColumns();

        $this->importStep = 2;
    }

    protected function autoDetectColumns(): array
    {
        $mapping = [];
        $contactFields = $this->getContactFields();
        $normalizedHeaders = array_map(fn ($h) => strtolower(str_replace([' ', '-'], '_', trim($h))), $this->csvHeaders);

        $aliases = [
            'first_name' => ['first_name', 'firstname', 'first', 'fname', 'given_name'],
            'last_name' => ['last_name', 'lastname', 'last', 'lname', 'surname', 'family_name'],
            'phone' => ['phone', 'phone_number', 'phonenumber', 'mobile', 'cell', 'telephone', 'tel'],
            'email' => ['email', 'email_address', 'emailaddress', 'e_mail'],
            'address' => ['address', 'street', 'street_address', 'addr'],
            'city' => ['city', 'town'],
            'state' => ['state', 'province', 'st'],
            'zip' => ['zip', 'zipcode', 'zip_code', 'postal', 'postal_code', 'postcode'],
            'tags' => ['tags', 'tag', 'labels', 'label', 'categories'],
        ];

        foreach ($this->csvHeaders as $index => $header) {
            $normalized = $normalizedHeaders[$index];
            $mapping[$header] = '';
            foreach ($aliases as $field => $aliasList) {
                if (in_array($normalized, $aliasList)) {
                    $mapping[$header] = $field;
                    break;
                }
            }
        }

        return $mapping;
    }

    public function getContactFields(): array
    {
        return [
            '' => __('-- Skip --'),
            'first_name' => __('First Name'),
            'last_name' => __('Last Name'),
            'phone' => __('Phone'),
            'email' => __('Email'),
            'address' => __('Address'),
            'city' => __('City'),
            'state' => __('State'),
            'zip' => __('Zip'),
            'tags' => __('Tags'),
        ];
    }

    public function submitMapping(): void
    {
        // Validate required mappings
        $mappedFields = array_values($this->columnMapping);
        if (! in_array('first_name', $mappedFields)) {
            $this->addError('columnMapping', __('First Name mapping is required.'));

            return;
        }
        if (! in_array('phone', $mappedFields)) {
            $this->addError('columnMapping', __('Phone mapping is required.'));

            return;
        }

        // Check for duplicate mappings (excluding empty/skip)
        $nonEmpty = array_filter($mappedFields, fn ($v) => $v !== '');
        if (count($nonEmpty) !== count(array_unique($nonEmpty))) {
            $this->addError('columnMapping', __('Each field can only be mapped once.'));

            return;
        }

        // Validate rows and generate warnings
        $this->validationWarnings = [];
        $service = app(WebhookIntakeService::class);
        $reverseMap = array_flip(array_filter($this->columnMapping, fn ($v) => $v !== ''));

        $missingPhone = 0;
        $invalidPhone = 0;
        foreach ($this->csvAllRows as $i => $row) {
            $phoneHeader = $reverseMap['phone'] ?? null;
            $phoneValue = $phoneHeader ? trim($row[$phoneHeader] ?? '') : '';
            if (empty($phoneValue)) {
                $missingPhone++;
            } elseif (! $service->isValidPhonePublic($phoneValue)) {
                $invalidPhone++;
            }
        }

        if ($missingPhone > 0) {
            $this->validationWarnings[] = __(':count rows missing phone number — will be skipped.', ['count' => $missingPhone]);
        }
        if ($invalidPhone > 0) {
            $this->validationWarnings[] = __(':count rows with invalid phone — will be skipped.', ['count' => $invalidPhone]);
        }

        $this->importStep = 3;
    }

    public function startImport(): void
    {
        $this->validate([
            'importCompanyId' => ['required', 'exists:prospector_companies,id'],
        ]);

        if ($this->enrollInCampaign && $this->importCampaignId) {
            $this->validate([
                'importCampaignId' => ['exists:prospector_campaigns,id'],
            ]);
        }

        $this->importStep = 4;
        $this->importProcessed = 0;
        $this->importCreated = 0;
        $this->importUpdated = 0;
        $this->importSkipped = 0;
        $this->importErrorCount = 0;
        $this->importErrors = [];

        $this->processImportBatch();
    }

    public function processImportBatch(): void
    {
        $service = app(WebhookIntakeService::class);
        $company = Company::findOrFail($this->importCompanyId);
        $campaign = null;

        if ($this->enrollInCampaign && $this->importCampaignId) {
            $campaign = Campaign::where('id', $this->importCampaignId)
                ->where('company_id', $company->id)
                ->first();
        }

        $reverseMap = array_flip(array_filter($this->columnMapping, fn ($v) => $v !== ''));
        $batchSize = 50;
        $start = $this->importProcessed;
        $end = min($start + $batchSize, $this->importTotal);

        for ($i = $start; $i < $end; $i++) {
            $row = $this->csvAllRows[$i] ?? null;
            if (! $row) {
                continue;
            }

            $data = $this->mapRowToData($row, $reverseMap);

            // Validate required fields
            $errors = $service->validatePayload($data);
            if (! empty($errors)) {
                $this->importErrorCount++;
                $this->importErrors[] = __('Row :row: :errors', [
                    'row' => $i + 2,
                    'errors' => implode(', ', $errors),
                ]);
                $this->importProcessed++;

                continue;
            }

            $result = $service->processLead($company, array_merge(
                $data,
                $campaign ? ['campaign_ulid' => $campaign->ulid] : [],
            ));

            if ($result['contact']->do_not_call) {
                $this->importSkipped++;
            } elseif ($result['created']) {
                $this->importCreated++;
                // Set source to 'csv_import' for newly created contacts
                $result['contact']->update(['source' => 'csv_import']);
            } else {
                $this->importUpdated++;
            }

            $this->importProcessed++;
        }

        if ($this->importProcessed >= $this->importTotal) {
            $this->importStep = 5;
            $this->clearCaches();
        }
    }

    protected function mapRowToData(array $row, array $reverseMap): array
    {
        $data = [];
        foreach ($reverseMap as $field => $header) {
            $value = trim($row[$header] ?? '');
            if ($field === 'tags' && ! empty($value)) {
                $data['tags'] = array_map('trim', explode(',', $value));
            } else {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    #[Computed]
    public function importCampaigns(): \Illuminate\Support\Collection
    {
        if (! $this->importCompanyId) {
            return collect();
        }

        return Campaign::where('company_id', $this->importCompanyId)
            ->active()
            ->orderBy('name')
            ->get();
    }

    protected function resetImport(): void
    {
        $this->csvFile = null;
        $this->csvHeaders = [];
        $this->csvPreview = [];
        $this->csvAllRows = [];
        $this->columnMapping = [];
        $this->importStep = 1;
        $this->importCompanyId = null;
        $this->enrollInCampaign = false;
        $this->importCampaignId = null;
        $this->importTotal = 0;
        $this->importProcessed = 0;
        $this->importCreated = 0;
        $this->importUpdated = 0;
        $this->importSkipped = 0;
        $this->importErrorCount = 0;
        $this->importErrors = [];
        $this->validationWarnings = [];
        unset($this->importCampaigns);
    }

    // ─── Bulk Actions ─────────────────────────────────────

    public function openBulkCampaignModal(): void
    {
        if (empty($this->selectedContacts)) {
            return;
        }

        $this->bulkCampaignId = null;
        $this->bulkCampaignCompanyId = null;
        $this->showBulkCampaignModal = true;
    }

    public function closeBulkCampaignModal(): void
    {
        $this->showBulkCampaignModal = false;
        $this->bulkCampaignId = null;
        $this->bulkCampaignCompanyId = null;
    }

    #[Computed]
    public function bulkCampaignOptions(): \Illuminate\Support\Collection
    {
        if (! $this->bulkCampaignCompanyId) {
            return collect();
        }

        return Campaign::where('company_id', $this->bulkCampaignCompanyId)
            ->active()
            ->orderBy('name')
            ->get();
    }

    public function bulkAddToCampaign(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'bulkCampaignId' => ['required', 'exists:prospector_campaigns,id'],
        ]);

        $campaign = Campaign::findOrFail($this->bulkCampaignId);
        $maxSort = $campaign->contacts()->max('sort_order') ?? -1;

        $contacts = Contact::whereIn('id', $this->selectedContacts)
            ->where('do_not_call', false)
            ->get();

        $enrolled = 0;
        foreach ($contacts as $contact) {
            $exists = $campaign->contacts()
                ->where('prospector_contacts.id', $contact->id)
                ->exists();

            if (! $exists) {
                $maxSort++;
                $campaign->contacts()->attach($contact->id, [
                    'status' => 'queued',
                    'sort_order' => $maxSort,
                ]);
                $enrolled++;
            }
        }

        $this->selectedContacts = [];
        $this->closeBulkCampaignModal();
        session()->flash('success', __(':count contact(s) added to campaign.', ['count' => $enrolled]));
    }

    public function openBulkTagModal(): void
    {
        if (empty($this->selectedContacts)) {
            return;
        }

        $this->bulkTagInput = '';
        $this->showBulkTagModal = true;
    }

    public function closeBulkTagModal(): void
    {
        $this->showBulkTagModal = false;
        $this->bulkTagInput = '';
    }

    public function bulkAddTag(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'bulkTagInput' => ['required', 'string', 'max:100'],
        ]);

        $newTags = array_map('trim', explode(',', $this->bulkTagInput));
        $newTags = array_filter($newTags);

        $contacts = Contact::whereIn('id', $this->selectedContacts)->get();
        foreach ($contacts as $contact) {
            $existing = $contact->tags ?? [];
            $merged = array_unique(array_merge($existing, $newTags));
            $contact->update(['tags' => array_values($merged)]);
        }

        $count = $contacts->count();
        $this->selectedContacts = [];
        $this->closeBulkTagModal();
        $this->clearCaches();
        session()->flash('success', __('Tags added to :count contact(s).', ['count' => $count]));
    }

    public function openBulkDeleteModal(): void
    {
        if (empty($this->selectedContacts)) {
            return;
        }

        $this->showBulkDeleteModal = true;
    }

    public function closeBulkDeleteModal(): void
    {
        $this->showBulkDeleteModal = false;
    }

    public function bulkDelete(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $count = Contact::whereIn('id', $this->selectedContacts)->count();
        Contact::whereIn('id', $this->selectedContacts)->delete();

        $this->selectedContacts = [];
        $this->closeBulkDeleteModal();
        $this->clearCaches();
        session()->flash('success', __(':count contact(s) deleted.', ['count' => $count]));
    }

    // ─── CSV Export ─────────────────────────────────────────

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = Contact::query()
            ->with('company')
            ->when($this->search, fn ($q) => $q->search($this->search))
            ->when($this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->when($this->dncFilter === '1', fn ($q) => $q->where('do_not_call', true))
            ->when($this->dncFilter === '0', fn ($q) => $q->where('do_not_call', false))
            ->when($this->sourceFilter, fn ($q) => $q->where('source', $this->sourceFilter))
            ->orderBy('first_name')
            ->orderBy('last_name');

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="contacts-'.date('Y-m-d').'.csv"',
        ];

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['First Name', 'Last Name', 'Phone', 'Email', 'Address', 'City', 'State', 'Zip', 'Company', 'Source', 'Tags', 'DNC', 'Priority', 'Last Contacted', 'Total Calls']);

            $query->chunk(500, function ($contacts) use ($handle) {
                foreach ($contacts as $contact) {
                    fputcsv($handle, [
                        $contact->first_name,
                        $contact->last_name ?? '',
                        $contact->phone,
                        $contact->email ?? '',
                        $contact->address ?? '',
                        $contact->city ?? '',
                        $contact->state ?? '',
                        $contact->zip ?? '',
                        $contact->company?->name ?? '',
                        $contact->source ?? '',
                        $contact->tags ? implode(', ', $contact->tags) : '',
                        $contact->do_not_call ? 'Yes' : 'No',
                        $contact->priority,
                        $contact->last_contacted_at?->format('Y-m-d H:i') ?? '',
                        $contact->total_calls,
                    ]);
                }
            });

            fclose($handle);
        }, 'contacts-'.date('Y-m-d').'.csv', $headers);
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->companyId = null;
        $this->firstName = '';
        $this->lastName = '';
        $this->phone = '';
        $this->email = '';
        $this->address = '';
        $this->tagsInput = '';
    }

    protected function clearCaches(): void
    {
        unset($this->contacts, $this->stats);
    }

    protected function getPriorityBadge(int $priority): array
    {
        return match (true) {
            $priority >= 8 => ['color' => 'red', 'label' => __('High')],
            $priority >= 4 => ['color' => 'amber', 'label' => __('Medium')],
            $priority >= 1 => ['color' => 'blue', 'label' => __('Low')],
            default => ['color' => 'zinc', 'label' => __('None')],
        };
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Contacts') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage prospector contacts and their call status.') }}</flux:text>
        </div>

        <div class="flex items-center gap-2">
            @if (! empty($selectedContacts))
                <flux:button size="sm" variant="ghost" icon="tag" wire:click="openBulkTagModal">
                    {{ __('Tag (:count)', ['count' => count($selectedContacts)]) }}
                </flux:button>
                <flux:button size="sm" variant="ghost" icon="queue-list" wire:click="openBulkCampaignModal">
                    {{ __('Add to Campaign') }}
                </flux:button>
                <flux:button size="sm" variant="danger" icon="trash" wire:click="openBulkDeleteModal">
                    {{ __('Delete (:count)', ['count' => count($selectedContacts)]) }}
                </flux:button>
                <flux:separator vertical class="mx-1 h-6" />
            @endif
            <flux:button variant="ghost" icon="arrow-down-tray" wire:click="exportCsv">
                {{ __('Export CSV') }}
            </flux:button>
            <flux:button variant="ghost" icon="arrow-up-tray" wire:click="openImportModal">
                {{ __('Import CSV') }}
            </flux:button>
            <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                {{ __('Add Contact') }}
            </flux:button>
        </div>
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['total'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Active') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['active'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('DNC') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['dnc'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Contacted Today') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['contacted_today'] }}</flux:heading>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-end gap-4">
        <div class="w-64">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search name, phone, email...')"
            />
        </div>

        <flux:select wire:model.live="companyFilter" class="w-48" placeholder="{{ __('All Companies') }}">
            <flux:select.option value="">{{ __('All Companies') }}</flux:select.option>
            @foreach ($this->companies as $company)
                <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="dncFilter" class="w-36" placeholder="{{ __('DNC Status') }}">
            <flux:select.option value="">{{ __('All Statuses') }}</flux:select.option>
            <flux:select.option value="0">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="1">{{ __('DNC') }}</flux:select.option>
        </flux:select>

        @if (count($this->availableSources) > 0)
            <flux:select wire:model.live="sourceFilter" class="w-40" placeholder="{{ __('All Sources') }}">
                <flux:select.option value="">{{ __('All Sources') }}</flux:select.option>
                @foreach ($this->availableSources as $source)
                    <flux:select.option value="{{ $source }}">{{ ucfirst($source) }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if ($search || $companyFilter || $dncFilter || $sourceFilter)
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">
                {{ __('Clear') }}
            </flux:button>
        @endif
    </div>

    {{-- Contacts table --}}
    @if ($this->contacts->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="users" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No contacts found') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Add your first contact to get started.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                    {{ __('Add Contact') }}
                </flux:button>
            </div>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="w-8"></flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortBy === 'name' ? $sortDir : null" wire:click="sortByColumn('name')">{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Phone') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Company') }}</flux:table.column>
                <flux:table.column>{{ __('Tags') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'priority'" :direction="$sortBy === 'priority' ? $sortDir : null" wire:click="sortByColumn('priority')">{{ __('Priority') }}</flux:table.column>
                <flux:table.column>{{ __('DNC') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'last_contacted_at'" :direction="$sortBy === 'last_contacted_at' ? $sortDir : null" wire:click="sortByColumn('last_contacted_at')">{{ __('Last Contacted') }}</flux:table.column>
                <flux:table.column>{{ __('Source') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->contacts as $contact)
                    <flux:table.row wire:key="contact-{{ $contact->id }}">
                        <flux:table.cell>
                            <input
                                type="checkbox"
                                wire:model.live="selectedContacts"
                                value="{{ $contact->id }}"
                                class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="font-medium">{{ $contact->getFullName() }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="font-mono text-sm">{{ $contact->getDisplayPhone() }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($contact->email)
                                <span class="text-sm">{{ $contact->email }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($contact->company)
                                <span class="text-sm">{{ $contact->company->name }}</span>
                            @else
                                <span class="text-zinc-400">{{ __('None') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($contact->tags && count($contact->tags) > 0)
                                <div class="flex flex-wrap gap-1">
                                    @foreach (array_slice($contact->tags, 0, 3) as $tag)
                                        <flux:badge size="sm" color="zinc">{{ $tag }}</flux:badge>
                                    @endforeach
                                    @if (count($contact->tags) > 3)
                                        <flux:badge size="sm" color="zinc">+{{ count($contact->tags) - 3 }}</flux:badge>
                                    @endif
                                </div>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @php $badge = $this->getPriorityBadge($contact->priority); @endphp
                            <flux:badge size="sm" :color="$badge['color']">{{ $badge['label'] }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:switch
                                wire:click="toggleDnc({{ $contact->id }})"
                                :checked="$contact->do_not_call"
                                size="sm"
                            />
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($contact->last_contacted_at)
                                <span class="text-sm text-zinc-500" title="{{ $contact->last_contacted_at->format('M j, Y g:i A') }}">
                                    {{ $contact->last_contacted_at->diffForHumans() }}
                                </span>
                            @else
                                <span class="text-zinc-400">{{ __('Never') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($contact->source)
                                <flux:badge size="sm" color="zinc">{{ ucfirst($contact->source) }}</flux:badge>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditModal({{ $contact->id }})" title="{{ __('Edit') }}" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $contact->id }})" title="{{ __('Delete') }}" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-6">
            {{ $this->contacts->links() }}
        </div>
    @endif

    {{-- Add/Edit Modal --}}
    <flux:modal wire:model="showModal" variant="flyout" class="w-[420px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Contact') : __('Add Contact') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $editingId
                        ? __('Update the contact details below.')
                        : __('Add a new contact to your prospector list.')
                    }}
                </flux:text>
            </div>

            <flux:select wire:model="companyId" :label="__('Company')" required>
                <flux:select.option value="">{{ __('Select a company...') }}</flux:select.option>
                @foreach ($this->companies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-4">
                <flux:input
                    wire:model="firstName"
                    :label="__('First Name')"
                    :placeholder="__('John')"
                    required
                />

                <flux:input
                    wire:model="lastName"
                    :label="__('Last Name')"
                    :placeholder="__('Doe')"
                />
            </div>

            <flux:input
                wire:model="phone"
                :label="__('Phone')"
                :placeholder="__('(720) 555-1234')"
                required
            />

            <flux:input
                wire:model="email"
                :label="__('Email')"
                type="email"
                :placeholder="__('john@example.com')"
            />

            <flux:input
                wire:model="address"
                :label="__('Address')"
                :placeholder="__('123 Main St, Denver, CO')"
            />

            <flux:input
                wire:model="tagsInput"
                :label="__('Tags')"
                :placeholder="__('homeowner, denver, interested')"
            />
            <flux:text class="-mt-4 text-xs text-zinc-500">{{ __('Separate tags with commas') }}</flux:text>

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingId ? __('Update Contact') : __('Add Contact') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Contact') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete') }} <strong>{{ $deletingName }}</strong>{{ __('? This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelDelete" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="delete" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- CSV Import Modal --}}
    <flux:modal wire:model="showImportModal" class="max-w-2xl" :dismissible="$importStep !== 4">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Import Contacts from CSV') }}</flux:heading>
                <flux:text class="mt-1">
                    @if ($importStep === 1)
                        {{ __('Upload a CSV file with your contacts.') }}
                    @elseif ($importStep === 2)
                        {{ __('Map CSV columns to contact fields.') }}
                    @elseif ($importStep === 3)
                        {{ __('Review and start import.') }}
                    @elseif ($importStep === 4)
                        {{ __('Importing contacts...') }}
                    @else
                        {{ __('Import complete.') }}
                    @endif
                </flux:text>
            </div>

            {{-- Step indicator --}}
            <div class="flex items-center gap-2">
                @foreach ([1 => 'Upload', 2 => 'Map', 3 => 'Review', 4 => 'Import', 5 => 'Done'] as $step => $label)
                    <div class="flex items-center gap-1">
                        <div @class([
                            'flex size-6 items-center justify-center rounded-full text-xs font-medium',
                            'bg-blue-600 text-white' => $importStep === $step,
                            'bg-green-600 text-white' => $importStep > $step,
                            'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400' => $importStep < $step,
                        ])>
                            @if ($importStep > $step)
                                <flux:icon name="check" class="size-3" />
                            @else
                                {{ $step }}
                            @endif
                        </div>
                        <span @class([
                            'text-xs',
                            'font-medium text-zinc-900 dark:text-white' => $importStep >= $step,
                            'text-zinc-400' => $importStep < $step,
                        ])>{{ __($label) }}</span>
                    </div>
                    @if ($step < 5)
                        <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
                    @endif
                @endforeach
            </div>

            {{-- Step 1: Upload --}}
            @if ($importStep === 1)
                <div class="rounded-lg border-2 border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
                    <flux:icon name="arrow-up-tray" class="mx-auto mb-3 size-10 text-zinc-400" />
                    <flux:text class="mb-4">{{ __('Choose a CSV file to upload') }}</flux:text>
                    <input
                        type="file"
                        wire:model="csvFile"
                        accept=".csv,.txt"
                        class="mx-auto block text-sm text-zinc-500 file:mr-4 file:rounded-md file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100 dark:text-zinc-400 dark:file:bg-blue-900/30 dark:file:text-blue-400"
                    />
                    <div wire:loading wire:target="csvFile" class="mt-3">
                        <flux:text class="text-sm text-blue-600">{{ __('Uploading...') }}</flux:text>
                    </div>
                    @error('csvFile')
                        <flux:text class="mt-2 text-sm text-red-600">{{ $message }}</flux:text>
                    @enderror
                    <flux:text class="mt-4 text-xs text-zinc-400">{{ __('Max 5MB. Required columns: first_name, phone') }}</flux:text>
                </div>
            @endif

            {{-- Step 2: Column Mapping --}}
            @if ($importStep === 2)
                <div class="space-y-4">
                    <flux:callout variant="info" icon="information-circle">
                        <flux:text>{{ __('We auto-detected some columns. Adjust as needed. First Name and Phone are required.') }}</flux:text>
                    </flux:callout>

                    <div class="max-h-64 space-y-3 overflow-y-auto">
                        @foreach ($csvHeaders as $header)
                            <div class="flex items-center gap-4">
                                <div class="w-40 shrink-0">
                                    <flux:badge size="sm">{{ $header }}</flux:badge>
                                </div>
                                <flux:icon name="arrow-right" class="size-4 shrink-0 text-zinc-400" />
                                <flux:select wire:model="columnMapping.{{ $header }}" class="flex-1" size="sm">
                                    @foreach ($this->getContactFields() as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endforeach
                    </div>

                    @error('columnMapping')
                        <flux:text class="text-sm text-red-600">{{ $message }}</flux:text>
                    @enderror

                    {{-- Preview --}}
                    @if (count($csvPreview) > 0)
                        <div>
                            <flux:text class="mb-2 text-sm font-medium">{{ __('Preview (first :count rows)', ['count' => count($csvPreview)]) }}</flux:text>
                            <div class="overflow-x-auto rounded border border-zinc-200 dark:border-zinc-700">
                                <table class="min-w-full text-xs">
                                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                                        <tr>
                                            @foreach ($csvHeaders as $header)
                                                <th class="px-3 py-1.5 text-left font-medium text-zinc-600 dark:text-zinc-400">{{ $header }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($csvPreview as $row)
                                            <tr class="border-t border-zinc-100 dark:border-zinc-700">
                                                @foreach ($csvHeaders as $header)
                                                    <td class="max-w-[150px] truncate px-3 py-1.5 text-zinc-700 dark:text-zinc-300">{{ $row[$header] ?? '' }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <div class="flex gap-2">
                        <flux:button variant="ghost" wire:click="$set('importStep', 1)" class="flex-1">{{ __('Back') }}</flux:button>
                        <flux:button variant="primary" wire:click="submitMapping" class="flex-1">{{ __('Continue') }}</flux:button>
                    </div>
                </div>
            @endif

            {{-- Step 3: Preview & Import Settings --}}
            @if ($importStep === 3)
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <flux:text class="text-sm text-zinc-500">{{ __('Total Rows') }}</flux:text>
                            <flux:heading size="lg">{{ $importTotal }}</flux:heading>
                        </div>
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <flux:text class="text-sm text-zinc-500">{{ __('Mapped Fields') }}</flux:text>
                            <flux:heading size="lg">{{ count(array_filter($columnMapping, fn ($v) => $v !== '')) }}</flux:heading>
                        </div>
                    </div>

                    @if (count($validationWarnings) > 0)
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            @foreach ($validationWarnings as $warning)
                                <flux:text>{{ $warning }}</flux:text>
                            @endforeach
                        </flux:callout>
                    @endif

                    <flux:select wire:model.live="importCompanyId" :label="__('Import into Company')" required>
                        <flux:select.option value="">{{ __('Select a company...') }}</flux:select.option>
                        @foreach ($this->companies as $company)
                            <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('importCompanyId')
                        <flux:text class="text-sm text-red-600">{{ $message }}</flux:text>
                    @enderror

                    <div class="space-y-3">
                        <flux:switch wire:model.live="enrollInCampaign" :label="__('Auto-enroll in campaign')" />

                        @if ($enrollInCampaign && $importCompanyId)
                            <flux:select wire:model="importCampaignId" :label="__('Campaign')">
                                <flux:select.option value="">{{ __('Select a campaign...') }}</flux:select.option>
                                @foreach ($this->importCampaigns as $campaign)
                                    <flux:select.option value="{{ $campaign->id }}">{{ $campaign->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                    </div>

                    <div class="flex gap-2">
                        <flux:button variant="ghost" wire:click="$set('importStep', 2)" class="flex-1">{{ __('Back') }}</flux:button>
                        <flux:button variant="primary" wire:click="startImport" class="flex-1" wire:loading.attr="disabled" wire:target="startImport">
                            <span wire:loading.remove wire:target="startImport">{{ __('Start Import') }}</span>
                            <span wire:loading wire:target="startImport">{{ __('Starting...') }}</span>
                        </flux:button>
                    </div>
                </div>
            @endif

            {{-- Step 4: Importing --}}
            @if ($importStep === 4)
                <div wire:poll.500ms="processImportBatch" class="space-y-4">
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <flux:text>{{ __('Progress') }}</flux:text>
                            <flux:text class="font-medium">{{ $importProcessed }} / {{ $importTotal }}</flux:text>
                        </div>
                        <div class="h-3 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                            <div
                                class="h-full rounded-full bg-blue-600 transition-all duration-300"
                                style="width: {{ $importTotal > 0 ? round(($importProcessed / $importTotal) * 100) : 0 }}%"
                            ></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-4 gap-3 text-center">
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Created') }}</flux:text>
                            <flux:heading size="lg" class="text-green-600">{{ $importCreated }}</flux:heading>
                        </div>
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Updated') }}</flux:text>
                            <flux:heading size="lg" class="text-blue-600">{{ $importUpdated }}</flux:heading>
                        </div>
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Skipped') }}</flux:text>
                            <flux:heading size="lg" class="text-amber-600">{{ $importSkipped }}</flux:heading>
                        </div>
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Errors') }}</flux:text>
                            <flux:heading size="lg" class="text-red-600">{{ $importErrorCount }}</flux:heading>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Step 5: Done --}}
            @if ($importStep === 5)
                <div class="space-y-4">
                    <div class="rounded-lg bg-green-50 p-4 text-center dark:bg-green-900/20">
                        <flux:icon name="check-circle" class="mx-auto mb-2 size-10 text-green-600" />
                        <flux:heading size="lg">{{ __('Import Complete') }}</flux:heading>
                    </div>

                    <div class="grid grid-cols-4 gap-3 text-center">
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Created') }}</flux:text>
                            <flux:heading size="lg" class="text-green-600">{{ $importCreated }}</flux:heading>
                        </div>
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Updated') }}</flux:text>
                            <flux:heading size="lg" class="text-blue-600">{{ $importUpdated }}</flux:heading>
                        </div>
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Skipped (DNC)') }}</flux:text>
                            <flux:heading size="lg" class="text-amber-600">{{ $importSkipped }}</flux:heading>
                        </div>
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Errors') }}</flux:text>
                            <flux:heading size="lg" class="text-red-600">{{ $importErrorCount }}</flux:heading>
                        </div>
                    </div>

                    @if (count($importErrors) > 0)
                        <div class="max-h-32 overflow-y-auto rounded border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-900/20">
                            <flux:text class="mb-1 text-sm font-medium text-red-700 dark:text-red-400">{{ __('Errors:') }}</flux:text>
                            @foreach ($importErrors as $error)
                                <flux:text class="text-xs text-red-600 dark:text-red-400">{{ $error }}</flux:text>
                            @endforeach
                        </div>
                    @endif

                    <flux:button variant="primary" wire:click="closeImportModal" class="w-full">{{ __('Done') }}</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- Bulk Add to Campaign Modal --}}
    <flux:modal wire:model="showBulkCampaignModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Add to Campaign') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Add :count selected contact(s) to a campaign.', ['count' => count($selectedContacts)]) }}
                </flux:text>
            </div>

            <flux:select wire:model.live="bulkCampaignCompanyId" :label="__('Company')">
                <flux:select.option value="">{{ __('Select company...') }}</flux:select.option>
                @foreach ($this->companies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($bulkCampaignCompanyId)
                <flux:select wire:model="bulkCampaignId" :label="__('Campaign')" required>
                    <flux:select.option value="">{{ __('Select campaign...') }}</flux:select.option>
                    @foreach ($this->bulkCampaignOptions as $campaign)
                        <flux:select.option value="{{ $campaign->id }}">{{ $campaign->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="closeBulkCampaignModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="bulkAddToCampaign" class="flex-1">{{ __('Add') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Bulk Tag Modal --}}
    <flux:modal wire:model="showBulkTagModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Add Tags') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Add tags to :count selected contact(s).', ['count' => count($selectedContacts)]) }}
                </flux:text>
            </div>

            <flux:input wire:model="bulkTagInput" :label="__('Tags')" :placeholder="__('tag1, tag2, tag3')" required />
            <flux:text class="-mt-4 text-xs text-zinc-500">{{ __('Separate multiple tags with commas') }}</flux:text>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="closeBulkTagModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="bulkAddTag" class="flex-1">{{ __('Add Tags') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Bulk Delete Modal --}}
    <flux:modal wire:model="showBulkDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Contacts') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete :count contact(s)? This action cannot be undone.', ['count' => count($selectedContacts)]) }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="closeBulkDeleteModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="bulkDelete" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
