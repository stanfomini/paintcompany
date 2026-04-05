<?php

use App\Models\Prospector\ExternalIdMap;
use App\Models\Prospector\ImportLog;
use App\Services\Prospector\ModelBridgeService;
use App\Services\Prospector\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Integrations')]
class extends Component
{
    // Bridge toggle
    public bool $bridgeEnabled = false;

    // Company mapping
    public string $companyModel = '';
    public array $companyFields = [];
    public string $newCompanyProspectorField = '';
    public string $newCompanyHostField = '';

    // Contact mapping
    public string $contactModel = '';
    public string $contactCompanyKey = '';
    public array $contactFields = [];
    public string $newContactProspectorField = '';
    public string $newContactHostField = '';

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! method_exists($user, 'isInternal') || ! $user->isInternal()) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $settings = app(SettingsService::class);

        $this->bridgeEnabled = (bool) $settings->get('bridge_enabled', false);
        $this->companyModel = $settings->get('bridge.company_model', '') ?: '';
        $this->companyFields = $settings->get('bridge.company_fields') ?: [];
        $this->contactModel = $settings->get('bridge.contact_model', '') ?: '';
        $this->contactCompanyKey = $settings->get('bridge.contact_company_key', '') ?: '';
        $this->contactFields = $settings->get('bridge.contact_fields') ?: [];
    }

    #[Computed]
    public function availableModels(): array
    {
        $models = [];
        $modelPath = app_path('Models');

        if (File::isDirectory($modelPath)) {
            foreach (File::allFiles($modelPath) as $file) {
                $relativePath = $file->getRelativePathname();

                // Skip Prospector models
                if (str_starts_with($relativePath, 'Prospector/')) {
                    continue;
                }

                $class = 'App\\Models\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

                if (class_exists($class) && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
                    $models[$class] = str_replace('.php', '', $relativePath);
                }
            }
        }

        ksort($models);

        return $models;
    }

    #[Computed]
    public function prospectorCompanyFields(): array
    {
        return ['name', 'timezone', 'notes', 'business_hours_start', 'business_hours_end', 'color'];
    }

    #[Computed]
    public function prospectorContactFields(): array
    {
        return [
            'first_name', 'last_name', 'phone', 'phone_2', 'email',
            'address', 'city', 'state', 'zip', 'timezone',
            'tags', 'custom_fields', 'source',
        ];
    }

    #[Computed]
    public function companySyncStats(): array
    {
        $model = $this->companyModel;

        return [
            'configured' => ! empty($model) && class_exists($model),
            'synced_count' => $model ? ExternalIdMap::where('prospector_type', 'company')->where('external_model', $model)->count() : 0,
            'host_count' => ($model && class_exists($model)) ? $model::count() : 0,
        ];
    }

    #[Computed]
    public function contactSyncStats(): array
    {
        $model = $this->contactModel;

        return [
            'configured' => ! empty($model) && class_exists($model),
            'synced_count' => $model ? ExternalIdMap::where('prospector_type', 'contact')->where('external_model', $model)->count() : 0,
            'host_count' => ($model && class_exists($model)) ? $model::count() : 0,
        ];
    }

    #[Computed]
    public function recentImports(): \Illuminate\Support\Collection
    {
        return ImportLog::latest()->limit(10)->get();
    }

    public function saveBridgeEnabled(): void
    {
        app(SettingsService::class)->set('bridge_enabled', $this->bridgeEnabled);
        $this->dispatch('notify', message: $this->bridgeEnabled ? 'Bridge enabled.' : 'Bridge disabled.');
    }

    public function saveCompanyMapping(): void
    {
        app(SettingsService::class)->setMany([
            'bridge.company_model' => $this->companyModel,
            'bridge.company_fields' => $this->companyFields,
        ]);

        unset($this->companySyncStats);
        $this->dispatch('notify', message: 'Company mapping saved.');
    }

    public function addCompanyField(): void
    {
        if ($this->newCompanyProspectorField && $this->newCompanyHostField) {
            $this->companyFields[$this->newCompanyProspectorField] = $this->newCompanyHostField;
            $this->newCompanyProspectorField = '';
            $this->newCompanyHostField = '';
        }
    }

    public function removeCompanyField(string $key): void
    {
        unset($this->companyFields[$key]);
    }

    public function saveContactMapping(): void
    {
        app(SettingsService::class)->setMany([
            'bridge.contact_model' => $this->contactModel,
            'bridge.contact_company_key' => $this->contactCompanyKey,
            'bridge.contact_fields' => $this->contactFields,
        ]);

        unset($this->contactSyncStats);
        $this->dispatch('notify', message: 'Contact mapping saved.');
    }

    public function addContactField(): void
    {
        if ($this->newContactProspectorField && $this->newContactHostField) {
            $this->contactFields[$this->newContactProspectorField] = $this->newContactHostField;
            $this->newContactProspectorField = '';
            $this->newContactHostField = '';
        }
    }

    public function removeContactField(string $key): void
    {
        unset($this->contactFields[$key]);
    }

    public function runImport(string $type): void
    {
        if (! in_array($type, ['companies', 'contacts'])) {
            return;
        }

        if (! ModelBridgeService::isConfigured($type)) {
            $this->dispatch('notify', message: 'Bridge is not configured for ' . $type . '.', type: 'error');

            return;
        }

        Artisan::queue('prospector:import', ['type' => $type]);

        unset($this->recentImports);
        $this->dispatch('notify', message: ucfirst($type) . ' import queued.');
    }
}; ?>

<div class="mx-auto max-w-5xl space-y-6 p-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Integrations</flux:heading>
            <flux:subheading>Connect your existing models to Prospector</flux:subheading>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm text-zinc-500">Model Bridge</span>
            <flux:switch wire:model.live="bridgeEnabled" wire:change="saveBridgeEnabled" />
            @if ($bridgeEnabled)
                <flux:badge color="green" size="sm">Enabled</flux:badge>
            @else
                <flux:badge color="zinc" size="sm">Disabled</flux:badge>
            @endif
        </div>
    </div>

    @if (! $bridgeEnabled)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center dark:border-amber-800 dark:bg-amber-900/20">
            <flux:icon name="exclamation-triangle" class="mx-auto mb-2 size-8 text-amber-500" />
            <p class="text-sm text-amber-800 dark:text-amber-300">
                Model Bridge is disabled. Toggle the switch above to enable bidirectional sync between your app and Prospector.
            </p>
        </div>
    @else
        {{-- Company Mapping --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                <div>
                    <flux:heading size="lg">Company Mapping</flux:heading>
                    @if ($companyModel && class_exists($companyModel))
                        <flux:subheading class="font-mono text-xs">{{ $companyModel }}</flux:subheading>
                    @else
                        <flux:subheading class="text-amber-600">Select a model below</flux:subheading>
                    @endif
                </div>
                @if ($this->companySyncStats['configured'])
                    <div class="flex items-center gap-3">
                        <div class="text-right text-xs text-zinc-500">
                            <div>Host: <span class="font-bold text-zinc-900 dark:text-zinc-100">{{ $this->companySyncStats['host_count'] }}</span></div>
                            <div>Synced: <span class="font-bold text-green-600">{{ $this->companySyncStats['synced_count'] }}</span></div>
                        </div>
                        <flux:button size="sm" variant="primary" wire:click="runImport('companies')" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="runImport('companies')">Import All</span>
                            <span wire:loading wire:target="runImport('companies')">Queuing...</span>
                        </flux:button>
                    </div>
                @endif
            </div>

            <div class="space-y-4 p-6">
                {{-- Model selector --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Host Model Class</label>
                    <select wire:model="companyModel" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">
                        <option value="">-- Select a model --</option>
                        @foreach ($this->availableModels as $class => $label)
                            <option value="{{ $class }}">{{ $label }} ({{ $class }})</option>
                        @endforeach
                    </select>
                </div>

                {{-- Field mappings --}}
                @if (! empty($companyFields))
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Field Mappings</label>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-zinc-500">
                                    <th class="pb-2 font-medium">Prospector Field</th>
                                    <th class="pb-2 font-medium">Your Model Field</th>
                                    <th class="pb-2 w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                                @foreach ($companyFields as $pField => $hField)
                                    <tr>
                                        <td class="py-1.5 font-mono text-xs text-zinc-900 dark:text-zinc-100">{{ $pField }}</td>
                                        <td class="py-1.5 font-mono text-xs text-blue-600 dark:text-blue-400">{{ $hField }}</td>
                                        <td class="py-1.5">
                                            <button wire:click="removeCompanyField('{{ $pField }}')" class="text-red-400 hover:text-red-600" title="Remove">
                                                <flux:icon name="x-mark" class="size-4" />
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Add field mapping --}}
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <label class="mb-1 block text-xs text-zinc-500">Prospector Field</label>
                        <select wire:model="newCompanyProspectorField" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">
                            <option value="">Select...</option>
                            @foreach ($this->prospectorCompanyFields as $field)
                                @if (! array_key_exists($field, $companyFields))
                                    <option value="{{ $field }}">{{ $field }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="mb-1 block text-xs text-zinc-500">Your Model Field</label>
                        <input wire:model="newCompanyHostField" type="text" placeholder="e.g. business_name" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100" />
                    </div>
                    <flux:button size="sm" variant="ghost" wire:click="addCompanyField">Add</flux:button>
                </div>

                <div class="flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:button variant="primary" wire:click="saveCompanyMapping" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveCompanyMapping">Save Company Mapping</span>
                        <span wire:loading wire:target="saveCompanyMapping">Saving...</span>
                    </flux:button>
                </div>
            </div>
        </div>

        {{-- Contact Mapping --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                <div>
                    <flux:heading size="lg">Contact Mapping</flux:heading>
                    @if ($contactModel && class_exists($contactModel))
                        <flux:subheading class="font-mono text-xs">{{ $contactModel }}</flux:subheading>
                    @else
                        <flux:subheading class="text-amber-600">Select a model below</flux:subheading>
                    @endif
                </div>
                @if ($this->contactSyncStats['configured'])
                    <div class="flex items-center gap-3">
                        <div class="text-right text-xs text-zinc-500">
                            <div>Host: <span class="font-bold text-zinc-900 dark:text-zinc-100">{{ $this->contactSyncStats['host_count'] }}</span></div>
                            <div>Synced: <span class="font-bold text-green-600">{{ $this->contactSyncStats['synced_count'] }}</span></div>
                        </div>
                        <flux:button size="sm" variant="primary" wire:click="runImport('contacts')" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="runImport('contacts')">Import All</span>
                            <span wire:loading wire:target="runImport('contacts')">Queuing...</span>
                        </flux:button>
                    </div>
                @endif
            </div>

            <div class="space-y-4 p-6">
                {{-- Model selector --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Host Model Class</label>
                        <select wire:model="contactModel" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">
                            <option value="">-- Select a model --</option>
                            @foreach ($this->availableModels as $class => $label)
                                <option value="{{ $class }}">{{ $label }} ({{ $class }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Company Foreign Key</label>
                        <input wire:model="contactCompanyKey" type="text" placeholder="e.g. customer_id" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100" />
                        <p class="mt-1 text-xs text-zinc-400">The column on your model that links to the company model</p>
                    </div>
                </div>

                {{-- Field mappings --}}
                @if (! empty($contactFields))
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Field Mappings</label>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-zinc-500">
                                    <th class="pb-2 font-medium">Prospector Field</th>
                                    <th class="pb-2 font-medium">Your Model Field</th>
                                    <th class="pb-2 w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                                @foreach ($contactFields as $pField => $hField)
                                    <tr>
                                        <td class="py-1.5 font-mono text-xs text-zinc-900 dark:text-zinc-100">{{ $pField }}</td>
                                        <td class="py-1.5 font-mono text-xs text-blue-600 dark:text-blue-400">{{ $hField }}</td>
                                        <td class="py-1.5">
                                            <button wire:click="removeContactField('{{ $pField }}')" class="text-red-400 hover:text-red-600" title="Remove">
                                                <flux:icon name="x-mark" class="size-4" />
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Add field mapping --}}
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <label class="mb-1 block text-xs text-zinc-500">Prospector Field</label>
                        <select wire:model="newContactProspectorField" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100">
                            <option value="">Select...</option>
                            @foreach ($this->prospectorContactFields as $field)
                                @if (! array_key_exists($field, $contactFields))
                                    <option value="{{ $field }}">{{ $field }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="mb-1 block text-xs text-zinc-500">Your Model Field</label>
                        <input wire:model="newContactHostField" type="text" placeholder="e.g. phone_number" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100" />
                    </div>
                    <flux:button size="sm" variant="ghost" wire:click="addContactField">Add</flux:button>
                </div>

                <div class="flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:button variant="primary" wire:click="saveContactMapping" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveContactMapping">Save Contact Mapping</span>
                        <span wire:loading wire:target="saveContactMapping">Saving...</span>
                    </flux:button>
                </div>
            </div>
        </div>

        {{-- Import History --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                <flux:heading size="lg">Import History</flux:heading>
            </div>
            <div class="p-4">
                @if ($this->recentImports->isEmpty())
                    <p class="py-4 text-center text-sm text-zinc-400">No imports yet</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-zinc-500">
                                <th class="pb-2 font-medium">Type</th>
                                <th class="pb-2 font-medium">Status</th>
                                <th class="pb-2 font-medium">Progress</th>
                                <th class="pb-2 font-medium">Created</th>
                                <th class="pb-2 font-medium">Updated</th>
                                <th class="pb-2 font-medium">Errors</th>
                                <th class="pb-2 font-medium">Started</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                            @foreach ($this->recentImports as $log)
                                <tr>
                                    <td class="py-1.5 font-medium capitalize text-zinc-900 dark:text-zinc-100">{{ $log->type }}</td>
                                    <td class="py-1.5">
                                        <flux:badge size="sm" :color="match($log->status) { 'completed' => 'green', 'running' => 'blue', 'failed' => 'red', default => 'zinc' }">
                                            {{ ucfirst($log->status) }}
                                        </flux:badge>
                                    </td>
                                    <td class="py-1.5 font-mono text-xs">{{ $log->processed }}/{{ $log->total_records }}</td>
                                    <td class="py-1.5 font-mono text-xs text-green-600">{{ $log->created }}</td>
                                    <td class="py-1.5 font-mono text-xs text-blue-600">{{ $log->updated }}</td>
                                    <td class="py-1.5 font-mono text-xs text-red-600">{{ $log->errors }}</td>
                                    <td class="py-1.5 text-xs text-zinc-500">{{ $log->started_at?->diffForHumans() ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- Webhook Endpoints --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                <flux:heading size="lg">Webhook Endpoints</flux:heading>
                <flux:subheading>For non-Laravel systems, use these endpoints to push data</flux:subheading>
            </div>
            <div class="space-y-3 p-4">
                @foreach (\App\Models\Prospector\Company::where('is_active', true)->get() as $company)
                    @php $webhookUrl = url('/prospector/webhook/' . $company->ulid . '/leads'); @endphp
                    <div class="rounded-md border border-zinc-100 p-3 dark:border-zinc-700">
                        <div class="mb-1 text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $company->name }}</div>
                        <div class="flex items-center gap-2">
                            <code class="flex-1 rounded bg-zinc-100 px-2 py-1 font-mono text-xs text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                POST {{ $webhookUrl }}
                            </code>
                            <flux:button size="xs" variant="ghost" icon="clipboard-document"
                                x-on:click="navigator.clipboard.writeText('{{ $webhookUrl }}')"
                                title="Copy URL" />
                        </div>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400">Secret:</span>
                            <code class="font-mono text-[10px] text-zinc-500">{{ substr($company->webhook_secret, 0, 8) }}...{{ substr($company->webhook_secret, -4) }}</code>
                            <flux:button size="xs" variant="ghost" icon="clipboard-document"
                                x-on:click="navigator.clipboard.writeText('{{ $company->webhook_secret }}')"
                                title="Copy Secret" />
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
