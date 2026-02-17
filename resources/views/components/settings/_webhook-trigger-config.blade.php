{{-- InboundWebhook Trigger Config Partial --}}
<flux:separator />

@if ($webhookUrl)
    <flux:field>
        <flux:label>Webhook URL</flux:label>
        <flux:description>Send a POST request with JSON data to this URL.</flux:description>
        <div class="flex items-center gap-2">
            <flux:input readonly :value="$webhookUrl" class="flex-1 font-mono text-sm" />
            <flux:button
                variant="ghost"
                icon="clipboard-document"
                x-on:click="navigator.clipboard.writeText('{{ $webhookUrl }}').then(() => $flux.toast('Copied!'))"
                title="Copy URL"
            />
        </div>
    </flux:field>
@endif

<flux:separator />

@if (! $webhookSamplePayload)
    <div wire:poll.5s="refreshWebhookPayload" class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
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
