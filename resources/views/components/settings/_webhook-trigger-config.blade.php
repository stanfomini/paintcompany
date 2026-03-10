{{-- InboundWebhook Trigger Config Partial --}}
<flux:separator />

{{-- Authentication --}}
<flux:field>
    <flux:label>Authentication</flux:label>
    <flux:description>How the external service will authenticate when sending webhooks.</flux:description>
    <flux:select wire:model.live="webhookAuthType">
        <flux:select.option value="none">None (public)</flux:select.option>
        <flux:select.option value="query_param">API Key in URL (most common)</flux:select.option>
        <flux:select.option value="api_key">API Key in header</flux:select.option>
        <flux:select.option value="bearer">Bearer Token</flux:select.option>
    </flux:select>
</flux:field>

@if ($webhookAuthType === 'query_param')
    <flux:field>
        <flux:label>Parameter Name</flux:label>
        <flux:description>The URL parameter name (e.g. api_key, key, token).</flux:description>
        <flux:input wire:model.blur="webhookAuthHeader" placeholder="api_key" />
    </flux:field>
    <flux:field>
        <flux:label>API Key Value</flux:label>
        <flux:description>The secret key value. This will be appended to the webhook URL.</flux:description>
        <div class="flex items-center gap-2">
            <flux:input wire:model.blur="webhookAuthValue" placeholder="Your secret API key" class="flex-1" />
            <flux:button
                variant="ghost"
                icon="clipboard-document"
                x-on:click="
                    const input = $el.closest('.flex').querySelector('input');
                    navigator.clipboard.writeText(input.value).then(() => $flux.toast('Copied!'));
                "
                title="Copy API key"
            />
        </div>
    </flux:field>
@elseif ($webhookAuthType === 'api_key')
    <flux:field>
        <flux:label>Header Name</flux:label>
        <flux:description>The HTTP header the external service will send the API key in (e.g. X-Api-Key).</flux:description>
        <flux:input wire:model="webhookAuthHeader" placeholder="X-Api-Key" />
    </flux:field>
    <flux:field>
        <flux:label>API Key</flux:label>
        <flux:description>The secret value the external service must send.</flux:description>
        <div class="flex items-center gap-2">
            <flux:input wire:model="webhookAuthValue" type="password" placeholder="Your secret API key" class="flex-1" />
            <flux:button
                variant="ghost"
                icon="clipboard-document"
                x-on:click="
                    const input = $el.closest('.flex').querySelector('input');
                    navigator.clipboard.writeText(input.value).then(() => $flux.toast('Copied!'));
                "
                title="Copy API key"
            />
        </div>
    </flux:field>
@elseif ($webhookAuthType === 'bearer')
    <flux:field>
        <flux:label>Bearer Token</flux:label>
        <flux:description>The token the external service must send in the Authorization header.</flux:description>
        <div class="flex items-center gap-2">
            <flux:input wire:model="webhookAuthValue" type="password" placeholder="Your bearer token" class="flex-1" />
            <flux:button
                variant="ghost"
                icon="clipboard-document"
                x-on:click="
                    const input = $el.closest('.flex').querySelector('input');
                    navigator.clipboard.writeText(input.value).then(() => $flux.toast('Copied!'));
                "
                title="Copy token"
            />
        </div>
    </flux:field>
@endif

<flux:separator />

{{-- Webhook URL --}}
@if ($webhookUrl)
    <flux:field>
        <flux:label>Webhook URL</flux:label>
        <flux:description>
            @if ($webhookAuthType === 'query_param' && $webhookAuthHeader && $webhookAuthValue)
                Give this full URL (with API key) to the external service.
            @else
                Give this URL to the external service. They will POST lead data here.
            @endif
        </flux:description>
        @php
            $displayUrl = $webhookUrl;
            if ($webhookAuthType === 'query_param' && $webhookAuthHeader && $webhookAuthValue) {
                $displayUrl .= (str_contains($displayUrl, '?') ? '&' : '?')
                    . urlencode($webhookAuthHeader) . '=' . urlencode($webhookAuthValue);
            }
        @endphp
        <div class="flex items-center gap-2">
            <flux:input readonly :value="$displayUrl" class="flex-1 font-mono text-sm" />
            <flux:button
                variant="ghost"
                icon="clipboard-document"
                x-on:click="navigator.clipboard.writeText(@js($displayUrl)).then(() => $flux.toast('Copied!'))"
                title="Copy URL"
            />
        </div>
        @if ($webhookAuthType === 'query_param' && $webhookAuthHeader && $webhookAuthValue)
            <flux:description class="mt-1">
                <strong>URL only</strong> (without API key): <code class="text-xs">{{ $webhookUrl }}</code><br>
                <strong>API Key parameter</strong>: <code class="text-xs">{{ $webhookAuthHeader }}={{ $webhookAuthValue }}</code>
            </flux:description>
        @endif
    </flux:field>
@endif

<flux:separator />

@if (! $webhookSamplePayload)
    <div wire:poll.5s.visible="refreshWebhookPayload" class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="relative flex h-3 w-3">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"></span>
            <span class="relative inline-flex h-3 w-3 rounded-full bg-amber-500"></span>
        </div>
        <span class="text-sm text-zinc-600 dark:text-zinc-400">Listening for test payload... Send a POST request to the webhook URL above.</span>
    </div>
@else
    <div class="space-y-3">
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>
                Sample payload received! {{ count($webhookAvailableFields ?? []) }} fields detected.
            </flux:callout.text>
        </flux:callout>

        <details class="group">
            <summary class="cursor-pointer text-sm font-medium text-zinc-600 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">
                View raw payload
            </summary>
            <pre class="mt-2 max-h-48 overflow-auto rounded-lg border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-900">{{ json_encode($webhookSamplePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>

        <flux:button variant="ghost" size="sm" wire:click="clearWebhookPayload" icon="arrow-path">
            Clear & wait for new payload
        </flux:button>

        @if ($webhookAvailableFields)
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">Available Fields</p>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                    @foreach ($webhookAvailableFields as $field)
                        <div class="truncate font-mono text-zinc-600 dark:text-zinc-300">{{ $field['path'] }}</div>
                        <div class="truncate text-zinc-400">{{ Str::limit((string) ($field['sample'] ?? ''), 30) }}</div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
