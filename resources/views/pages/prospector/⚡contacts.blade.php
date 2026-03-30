<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Prospector Contacts')]
class extends Component
{
    use WithPagination;

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

    // Delete
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingName = '';

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
    public function companies(): \Illuminate\Database\Eloquent\Collection
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
            Contact::create($data);
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

        <flux:button variant="primary" icon="plus" wire:click="openAddModal">
            {{ __('Add Contact') }}
        </flux:button>
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
</div>
