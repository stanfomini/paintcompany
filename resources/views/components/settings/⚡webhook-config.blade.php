<?php

use App\Models\LeadSource;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookFieldMapping;
use App\Models\WebhookLog;
use App\Services\WebhookLeadProcessingService;
use App\Services\WebhookMappingPresetService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $leadSourceId;
    public ?int $endpointId = null;
    public string $authType = 'none';
    public string $authHeader = '';
    public string $authValue = '';
    public ?int $defaultAssignedTo = null;
    public bool $isActive = false;
    public array $mappings = [];
    public bool $showTestResult = false;
    public array $testResult = [];

    public function mount(int $leadSourceId): void
    {
        $this->leadSourceId = $leadSourceId;

        $endpoint = WebhookEndpoint::where('lead_source_id', $leadSourceId)->first();

        if ($endpoint) {
            $this->endpointId = $endpoint->id;
            $this->authType = $endpoint->auth_type ?? 'none';
            $this->authHeader = $endpoint->auth_header ?? '';
            $this->authValue = ''; // Don't expose encrypted value
            $this->defaultAssignedTo = $endpoint->default_assigned_to;
            $this->isActive = $endpoint->is_active;

            $this->mappings = $endpoint->fieldMappings()
                ->ordered()
                ->get()
                ->map(fn (WebhookFieldMapping $m) => [
                    'source_path' => $m->source_path,
                    'target' => "{$m->target_model}.{$m->target_field}",
                    'transform' => $m->transform ?? 'none',
                    'default_value' => $m->default_value ?? '',
                ])
                ->toArray();
        }
    }

    #[Computed]
    public function endpoint(): ?WebhookEndpoint
    {
        if (! $this->endpointId) {
            return null;
        }

        return WebhookEndpoint::find($this->endpointId);
    }

    #[Computed]
    public function leadSource(): LeadSource
    {
        return LeadSource::findOrFail($this->leadSourceId);
    }

    #[Computed]
    public function webhookUrl(): ?string
    {
        $endpoint = $this->endpoint;

        if (! $endpoint) {
            return null;
        }

        return $endpoint->getWebhookUrl();
    }

    #[Computed]
    public function samplePayload(): ?array
    {
        return $this->endpoint?->sample_payload;
    }

    #[Computed]
    public function availableSourceFields(): array
    {
        $payload = $this->samplePayload;

        if (! $payload) {
            return [];
        }

        return collect(Arr::dot($payload))
            ->keys()
            ->map(function (string $key) use ($payload) {
                return [
                    'path' => $key,
                    'sample' => data_get($payload, $key),
                ];
            })
            ->toArray();
    }

    #[Computed]
    public function targetFields(): array
    {
        return [
            'customer' => [
                'first_name' => 'First Name',
                'last_name' => 'Last Name',
                'email' => 'Email',
                'phone' => 'Phone',
                'address' => 'Address',
                'city' => 'City',
                'state' => 'State',
                'zip' => 'Zip',
            ],
            'deal' => [
                'title' => 'Title',
                'description' => 'Description',
                'external_id' => 'External ID',
            ],
        ];
    }

    #[Computed]
    public function availableTransforms(): array
    {
        return [
            'none' => 'None',
            'split_first_name' => 'Split First Name',
            'split_last_name' => 'Split Last Name',
            'format_phone' => 'Format Phone',
            'join_details' => 'Join Details',
        ];
    }

    #[Computed]
    public function internalUsers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::where('type', 'internal')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function recentLogs(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->endpointId) {
            return collect();
        }

        return WebhookLog::where('webhook_endpoint_id', $this->endpointId)
            ->with(['customer:id,first_name,last_name', 'deal:id,title'])
            ->latest()
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function hasPreset(): bool
    {
        $slug = $this->leadSource->slug;

        return in_array($slug, ['angi', 'craftjack', 'thumbtack']);
    }

    public function saveEndpoint(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'authType' => ['required', 'in:none,api_key,bearer'],
            'authHeader' => ['required_if:authType,api_key', 'nullable', 'string', 'max:100'],
            'defaultAssignedTo' => ['nullable', 'exists:users,id'],
        ]);

        $data = [
            'lead_source_id' => $this->leadSourceId,
            'auth_type' => $this->authType,
            'auth_header' => $this->authType === 'api_key' ? $this->authHeader : null,
            'created_by' => Auth::id(),
            'default_assigned_to' => $this->defaultAssignedTo,
        ];

        // Only update auth_value if a new value was provided
        if ($this->authValue !== '') {
            $data['auth_value'] = $this->authValue;
        }

        if ($this->endpointId) {
            $endpoint = WebhookEndpoint::findOrFail($this->endpointId);
            $endpoint->update($data);
        } else {
            $endpoint = WebhookEndpoint::create($data);
            $this->endpointId = $endpoint->id;
        }

        unset($this->endpoint, $this->webhookUrl);

        $this->dispatch('notify', message: __('Webhook endpoint saved.'));
    }

    public function saveMappings(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (! $this->endpointId) {
            return;
        }

        $endpoint = WebhookEndpoint::findOrFail($this->endpointId);

        // Delete existing and recreate
        $endpoint->fieldMappings()->delete();

        foreach ($this->mappings as $i => $mapping) {
            $target = $mapping['target'] ?? '';

            if (empty($mapping['source_path']) && empty($target)) {
                continue;
            }

            // Parse combined target value (e.g., "customer.first_name")
            $targetParts = explode('.', $target, 2);
            $targetModel = $targetParts[0] ?? 'customer';
            $targetField = $targetParts[1] ?? '';

            $endpoint->fieldMappings()->create([
                'source_path' => $mapping['source_path'] ?? '',
                'target_model' => $targetModel,
                'target_field' => $targetField,
                'transform' => ($mapping['transform'] ?? 'none') === 'none' ? null : $mapping['transform'],
                'default_value' => $mapping['default_value'] ?? null,
                'sort_order' => $i + 1,
                'is_active' => true,
            ]);
        }

        $this->dispatch('notify', message: __('Field mappings saved.'));
    }

    public function addMapping(): void
    {
        $this->mappings[] = [
            'source_path' => '',
            'target' => '',
            'transform' => 'none',
            'default_value' => '',
        ];
    }

    public function removeMapping(int $index): void
    {
        unset($this->mappings[$index]);
        $this->mappings = array_values($this->mappings);
    }

    public function applyPreset(): void
    {
        $service = app(WebhookMappingPresetService::class);
        $preset = $service->getPresetFor($this->leadSource->slug);

        if (! $preset) {
            return;
        }

        $this->mappings = collect($preset)->map(fn (array $m) => [
            'source_path' => $m['source_path'],
            'target' => "{$m['target_model']}.{$m['target_field']}",
            'transform' => $m['transform'] ?? 'none',
            'default_value' => '',
        ])->toArray();

        $this->dispatch('notify', message: __('Preset mappings applied. Save to persist.'));
    }

    public function testWebhook(): void
    {
        $payload = $this->samplePayload;

        if (! $payload || ! $this->endpointId) {
            return;
        }

        // Save mappings first so the test uses current state
        $this->saveMappings();

        $endpoint = WebhookEndpoint::with('fieldMappings')->findOrFail($this->endpointId);
        $service = app(WebhookLeadProcessingService::class);

        DB::beginTransaction();

        try {
            $log = $service->process($endpoint, $payload);

            $this->testResult = [
                'status' => $log->status,
                'customer_name' => $log->customer
                    ? "{$log->customer->first_name} {$log->customer->last_name}"
                    : null,
                'customer_email' => $log->customer?->email,
                'deal_title' => $log->deal?->title,
                'error' => $log->error_message,
            ];

            $this->showTestResult = true;
        } finally {
            DB::rollBack();
        }

        unset($this->recentLogs);
    }

    public function toggleActive(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (! $this->endpointId) {
            return;
        }

        $endpoint = WebhookEndpoint::findOrFail($this->endpointId);
        $endpoint->update(['is_active' => ! $endpoint->is_active]);
        $this->isActive = $endpoint->is_active;

        unset($this->endpoint);
    }

    public function refreshSamplePayload(): void
    {
        unset($this->endpoint, $this->samplePayload, $this->availableSourceFields);
    }

    public function clearSamplePayload(): void
    {
        if (! $this->endpointId) {
            return;
        }

        $endpoint = WebhookEndpoint::findOrFail($this->endpointId);
        $endpoint->update(['sample_payload' => null]);
        unset($this->endpoint, $this->samplePayload, $this->availableSourceFields);
    }

    protected function getStatusColor(string $status): string
    {
        return match ($status) {
            'processed' => 'green',
            'failed' => 'red',
            'duplicate' => 'yellow',
            default => 'zinc',
        };
    }
}; ?>

<div class="space-y-8">
    {{-- Section 1: Webhook URL --}}
    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Webhook URL') }}</flux:heading>
        <flux:text class="text-sm">
            {{ __('Send a POST request to this URL with your lead data as JSON.') }}
        </flux:text>

        @if ($this->webhookUrl)
            <div class="flex items-center gap-2">
                <flux:input
                    readonly
                    :value="$this->webhookUrl"
                    class="flex-1 font-mono text-sm"
                />
                <flux:button
                    variant="ghost"
                    icon="clipboard-document"
                    x-on:click="navigator.clipboard.writeText('{{ $this->webhookUrl }}').then(() => $flux.toast('Copied!'))"
                    title="{{ __('Copy URL') }}"
                />
            </div>
        @else
            <flux:callout variant="warning" icon="information-circle">
                <flux:callout.text>
                    {{ __('Save authentication settings below to generate your webhook URL.') }}
                </flux:callout.text>
            </flux:callout>
        @endif
    </div>

    <flux:separator />

    {{-- Section 2: Authentication --}}
    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Authentication') }}</flux:heading>

        <flux:radio.group wire:model.live="authType" :label="__('Auth Type')" variant="segmented">
            <flux:radio value="none" :label="__('None')" />
            <flux:radio value="api_key" :label="__('API Key')" />
            <flux:radio value="bearer" :label="__('Bearer Token')" />
        </flux:radio.group>

        @if ($authType === 'api_key')
            <flux:input
                wire:model="authHeader"
                :label="__('Header Name')"
                placeholder="X-API-Key"
            />
            <flux:input
                wire:model="authValue"
                :label="__('API Key Value')"
                type="password"
                placeholder="{{ $this->endpointId ? '(unchanged - enter new value to update)' : 'Enter API key' }}"
            />
        @elseif ($authType === 'bearer')
            <flux:input
                wire:model="authValue"
                :label="__('Bearer Token')"
                type="password"
                placeholder="{{ $this->endpointId ? '(unchanged - enter new value to update)' : 'Enter bearer token' }}"
            />
        @endif

        <flux:select wire:model="defaultAssignedTo" :label="__('Default Assigned To')" :placeholder="__('Select user...')">
            @foreach ($this->internalUsers as $user)
                <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:button variant="primary" wire:click="saveEndpoint" icon="check">
            {{ __('Save Endpoint Settings') }}
        </flux:button>
    </div>

    @if ($this->endpointId)
        <flux:separator />

        {{-- Section 3: Sample Payload / Listening Mode --}}
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Sample Payload') }}</flux:heading>

            @if (! $this->samplePayload)
                <div wire:poll.5s="refreshSamplePayload" class="flex items-center gap-3 p-4 bg-zinc-50 dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="relative flex h-3 w-3">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-500"></span>
                    </div>
                    <flux:text>{{ __('Waiting for test payload... Send a POST request to the webhook URL above.') }}</flux:text>
                </div>
            @else
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.text>{{ __('Sample payload received! Configure your field mappings below.') }}</flux:callout.text>
                </flux:callout>

                <details class="group">
                    <summary class="cursor-pointer text-sm font-medium text-zinc-600 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200">
                        {{ __('View raw payload') }}
                    </summary>
                    <pre class="mt-2 p-3 bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-lg text-xs font-mono overflow-x-auto max-h-64 overflow-y-auto">{{ json_encode($this->samplePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>

                <flux:button variant="ghost" size="sm" wire:click="clearSamplePayload" icon="arrow-path">
                    {{ __('Clear & wait for new payload') }}
                </flux:button>
            @endif
        </div>

        @if ($this->samplePayload)
            <flux:separator />

            {{-- Section 4: Field Mapping --}}
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Field Mapping') }}</flux:heading>
                    @if ($this->hasPreset)
                        <flux:button variant="ghost" size="sm" wire:click="applyPreset" icon="sparkles">
                            {{ __('Apply :source Preset', ['source' => ucfirst($this->leadSource->slug)]) }}
                        </flux:button>
                    @endif
                </div>

                <flux:text class="text-sm">
                    {{ __('Map incoming payload fields to customer and deal fields.') }}
                </flux:text>

                @if (count($this->mappings) > 0)
                    <div class="space-y-3">
                        @foreach ($this->mappings as $index => $mapping)
                            <div wire:key="mapping-{{ $index }}" class="flex flex-wrap items-start gap-2 p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                {{-- Source field --}}
                                <div class="flex-1 min-w-[140px]">
                                    <flux:select
                                        wire:model="mappings.{{ $index }}.source_path"
                                        :label="__('Source')"
                                        :placeholder="__('Select field...')"
                                        size="sm"
                                    >
                                        @foreach ($this->availableSourceFields as $field)
                                            <flux:select.option value="{{ $field['path'] }}">
                                                {{ $field['path'] }}
                                                @if ($field['sample'] !== null && $field['sample'] !== '')
                                                    ({{ Str::limit((string) $field['sample'], 20) }})
                                                @endif
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>

                                {{-- Target model + field --}}
                                <div class="flex-1 min-w-[160px]">
                                    <flux:select
                                        wire:model="mappings.{{ $index }}.target"
                                        :label="__('Target')"
                                        :placeholder="__('Select target...')"
                                        size="sm"
                                    >
                                        @foreach ($this->targetFields as $model => $fields)
                                            <optgroup label="{{ ucfirst($model) }}">
                                                @foreach ($fields as $fieldKey => $fieldLabel)
                                                    <flux:select.option value="{{ $model }}.{{ $fieldKey }}">
                                                        {{ $fieldLabel }}
                                                    </flux:select.option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </flux:select>
                                </div>

                                {{-- Transform --}}
                                <div class="w-[150px]">
                                    <flux:select
                                        wire:model="mappings.{{ $index }}.transform"
                                        :label="__('Transform')"
                                        size="sm"
                                    >
                                        @foreach ($this->availableTransforms as $key => $label)
                                            <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>

                                {{-- Remove --}}
                                <div class="pt-6">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="x-mark"
                                        wire:click="removeMapping({{ $index }})"
                                        title="{{ __('Remove mapping') }}"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-6 text-zinc-500 dark:text-zinc-400 text-sm">
                        {{ __('No field mappings configured. Add one or apply a preset.') }}
                    </div>
                @endif

                <div class="flex gap-2">
                    <flux:button variant="ghost" wire:click="addMapping" icon="plus" size="sm">
                        {{ __('Add Mapping') }}
                    </flux:button>
                    @if (count($this->mappings) > 0)
                        <flux:button variant="primary" wire:click="saveMappings" icon="check" size="sm">
                            {{ __('Save Mappings') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            <flux:separator />

            {{-- Section 5: Test --}}
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Test Webhook') }}</flux:heading>

                <flux:text class="text-sm">
                    {{ __('Process the sample payload with your current mappings to preview what would be created.') }}
                </flux:text>

                <flux:button variant="filled" wire:click="testWebhook" icon="play">
                    {{ __('Test with Sample Payload') }}
                </flux:button>

                @if ($showTestResult && ! empty($testResult))
                    <div class="p-4 rounded-lg border {{ $testResult['status'] === 'processed' ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800' }}">
                        @if ($testResult['status'] === 'processed')
                            <div class="flex items-start gap-2">
                                <flux:icon name="check-circle" variant="mini" class="text-green-600 mt-0.5 shrink-0" />
                                <div class="text-sm space-y-1">
                                    <div class="font-medium text-green-800 dark:text-green-200">{{ __('Test successful (changes rolled back)') }}</div>
                                    @if ($testResult['customer_name'])
                                        <div class="text-green-700 dark:text-green-300">
                                            Customer: {{ $testResult['customer_name'] }}
                                            @if ($testResult['customer_email'])
                                                ({{ $testResult['customer_email'] }})
                                            @endif
                                        </div>
                                    @endif
                                    @if ($testResult['deal_title'])
                                        <div class="text-green-700 dark:text-green-300">Deal: {{ $testResult['deal_title'] }}</div>
                                    @endif
                                </div>
                            </div>
                        @elseif ($testResult['status'] === 'duplicate')
                            <div class="flex items-start gap-2">
                                <flux:icon name="exclamation-triangle" variant="mini" class="text-yellow-600 mt-0.5 shrink-0" />
                                <div class="text-sm">
                                    <div class="font-medium text-yellow-800 dark:text-yellow-200">{{ __('Duplicate detected') }}</div>
                                    <div class="text-yellow-700 dark:text-yellow-300">{{ __('A deal with this external ID already exists.') }}</div>
                                </div>
                            </div>
                        @else
                            <div class="flex items-start gap-2">
                                <flux:icon name="x-circle" variant="mini" class="text-red-600 mt-0.5 shrink-0" />
                                <div class="text-sm">
                                    <div class="font-medium text-red-800 dark:text-red-200">{{ __('Test failed') }}</div>
                                    @if ($testResult['error'])
                                        <div class="text-red-700 dark:text-red-300">{{ $testResult['error'] }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- Section 6: Recent Logs --}}
        @if ($this->endpointId && $this->recentLogs->isNotEmpty())
            <flux:separator />

            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Recent Activity') }}</flux:heading>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wider border-b border-zinc-200 dark:border-zinc-700">
                                <th class="pb-2 pr-4">{{ __('Time') }}</th>
                                <th class="pb-2 pr-4">{{ __('Status') }}</th>
                                <th class="pb-2 pr-4">{{ __('Customer') }}</th>
                                <th class="pb-2 pr-4">{{ __('Deal') }}</th>
                                <th class="pb-2">{{ __('Error') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($this->recentLogs as $log)
                                <tr wire:key="log-{{ $log->id }}">
                                    <td class="py-2 pr-4 text-zinc-500 whitespace-nowrap">
                                        {{ $log->created_at->diffForHumans() }}
                                    </td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm" :color="$this->getStatusColor($log->status)">
                                            {{ ucfirst($log->status) }}
                                        </flux:badge>
                                    </td>
                                    <td class="py-2 pr-4 truncate max-w-[140px]">
                                        {{ $log->customer ? "{$log->customer->first_name} {$log->customer->last_name}" : '-' }}
                                    </td>
                                    <td class="py-2 pr-4 truncate max-w-[140px]">
                                        {{ $log->deal?->title ?? '-' }}
                                    </td>
                                    <td class="py-2 text-red-600 dark:text-red-400 truncate max-w-[200px]">
                                        {{ $log->error_message ?? '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Section 7: Activate Toggle --}}
        @if ($this->endpointId)
            <flux:separator />

            <div class="space-y-3">
                <flux:heading size="lg">{{ __('Activate Webhook') }}</flux:heading>

                <div class="flex items-center justify-between p-4 bg-zinc-50 dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div>
                        <div class="font-medium">{{ $isActive ? __('Webhook is active') : __('Webhook is inactive') }}</div>
                        <flux:text class="text-sm mt-1">
                            @if ($isActive)
                                {{ __('Incoming leads will be processed automatically.') }}
                            @else
                                {{ __('Enable this webhook to start processing incoming leads.') }}
                            @endif
                        </flux:text>
                    </div>
                    <flux:switch
                        wire:click="toggleActive"
                        :checked="$isActive"
                    />
                </div>

                @if (! $isActive && count($this->mappings) === 0)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.text>
                            {{ __('Configure field mappings before activating this webhook.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif
            </div>
        @endif
    @endif
</div>
