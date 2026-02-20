{{-- ExternalApiPoll Trigger Config Partial --}}
<flux:separator />

<flux:field>
    <flux:label>API URL</flux:label>
    <flux:description>The external API endpoint to fetch data from.</flux:description>
    <flux:input wire:model="apiSourceUrl" placeholder="https://api.example.com/leads" />
</flux:field>

<div class="grid grid-cols-2 gap-4">
    <flux:field>
        <flux:label>HTTP Method</flux:label>
        <flux:select wire:model="nodeConfig.http_method">
            <flux:select.option value="GET">GET</flux:select.option>
            <flux:select.option value="POST">POST</flux:select.option>
        </flux:select>
    </flux:field>

    <flux:field>
        <flux:label>Poll Interval</flux:label>
        <flux:select wire:model="nodeConfig.poll_interval">
            <flux:select.option value="every_1_min">Every 1 minute</flux:select.option>
            <flux:select.option value="every_5_min">Every 5 minutes</flux:select.option>
            <flux:select.option value="every_15_min">Every 15 minutes</flux:select.option>
            <flux:select.option value="every_30_min">Every 30 minutes</flux:select.option>
            <flux:select.option value="hourly">Every hour</flux:select.option>
        </flux:select>
    </flux:field>
</div>

<flux:separator />

<flux:field>
    <flux:label>Authentication</flux:label>
    <flux:select wire:model.live="nodeConfig.auth_type">
        <flux:select.option value="none">None</flux:select.option>
        <flux:select.option value="bearer">Bearer Token</flux:select.option>
        <flux:select.option value="api_key">API Key Header</flux:select.option>
    </flux:select>
</flux:field>

@if (($nodeConfig['auth_type'] ?? 'none') === 'bearer')
    <flux:field>
        <flux:label>Bearer Token</flux:label>
        <flux:input wire:model="nodeConfig.auth_value" type="password" placeholder="Enter bearer token" />
    </flux:field>
@elseif (($nodeConfig['auth_type'] ?? 'none') === 'api_key')
    <div class="grid grid-cols-2 gap-4">
        <flux:field>
            <flux:label>Header Name</flux:label>
            <flux:input wire:model="nodeConfig.auth_header" placeholder="X-Api-Key" />
        </flux:field>
        <flux:field>
            <flux:label>Header Value</flux:label>
            <flux:input wire:model="nodeConfig.auth_value" type="password" placeholder="Enter API key" />
        </flux:field>
    </div>
@endif

<flux:separator />

<div class="grid grid-cols-2 gap-4">
    <flux:field>
        <flux:label>Data Path</flux:label>
        <flux:description>Dot-notation path to records array (e.g., "data.results").</flux:description>
        <flux:input wire:model="nodeConfig.data_path" placeholder="data" />
    </flux:field>

    <flux:field>
        <flux:label>Dedup Field</flux:label>
        <flux:description>Field name to prevent duplicate processing. Leave empty to use full record hash.</flux:description>
        <flux:input wire:model="nodeConfig.dedup_field" placeholder="id" />
    </flux:field>
</div>

<flux:separator />

<div class="flex items-center gap-3">
    <flux:button wire:click="testApiSource" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="testApiSource">Test Connection</span>
        <span wire:loading wire:target="testApiSource">Testing...</span>
    </flux:button>
    @if ($apiSourceSamplePayload)
        <flux:button variant="ghost" size="sm" wire:click="clearApiSourcePayload" icon="arrow-path">
            Clear &amp; re-test
        </flux:button>
    @endif
</div>

@if ($apiSourceSamplePayload)
    <div class="space-y-3">
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>
                Connection successful! {{ count($apiSourceAvailableFields ?? []) }} fields detected.
            </flux:callout.text>
        </flux:callout>

        <details class="group">
            <summary class="cursor-pointer text-sm font-medium text-zinc-600 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">
                View raw response
            </summary>
            <pre class="mt-2 max-h-48 overflow-auto rounded-lg border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-900">{{ json_encode($apiSourceSamplePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>

        @if ($apiSourceAvailableFields)
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">Available Fields</p>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                    @foreach ($apiSourceAvailableFields as $field)
                        <div class="truncate font-mono text-zinc-600 dark:text-zinc-300">{{ $field['path'] }}</div>
                        <div class="truncate text-zinc-400">{{ Str::limit((string) ($field['sample'] ?? ''), 30) }}</div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
