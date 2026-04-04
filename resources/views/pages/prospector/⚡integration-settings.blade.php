<?php

use App\Models\Prospector\ExternalIdMap;
use App\Models\Prospector\ImportLog;
use App\Services\Prospector\ModelBridgeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Integrations')]
class extends Component
{
    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function bridgeEnabled(): bool
    {
        return config('prospector-bridge.enabled', false);
    }

    #[Computed]
    public function companyConfig(): array
    {
        $config = config('prospector-bridge.companies', []);

        return [
            'model' => $config['model'] ?? null,
            'fields' => $config['fields'] ?? [],
            'configured' => ! empty($config['model']),
            'synced_count' => $config['model'] ? ExternalIdMap::where('prospector_type', 'company')->where('external_model', $config['model'])->count() : 0,
            'host_count' => ($config['model'] && class_exists($config['model'])) ? ($config['model'])::count() : 0,
        ];
    }

    #[Computed]
    public function contactConfig(): array
    {
        $config = config('prospector-bridge.contacts', []);

        return [
            'model' => $config['model'] ?? null,
            'company_key' => $config['company_key'] ?? null,
            'fields' => $config['fields'] ?? [],
            'configured' => ! empty($config['model']),
            'synced_count' => $config['model'] ? ExternalIdMap::where('prospector_type', 'contact')->where('external_model', $config['model'])->count() : 0,
            'host_count' => ($config['model'] && class_exists($config['model'])) ? ($config['model'])::count() : 0,
        ];
    }

    #[Computed]
    public function recentImports(): \Illuminate\Support\Collection
    {
        return ImportLog::latest()->limit(10)->get();
    }

    public function runImport(string $type): void
    {
        if (! in_array($type, ['companies', 'contacts'])) {
            return;
        }

        if (! ModelBridgeService::isConfigured($type)) {
            return;
        }

        Artisan::queue('prospector:import', ['type' => $type]);

        unset($this->recentImports);
    }
}; ?>

<div class="mx-auto max-w-5xl space-y-6 p-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Integrations</flux:heading>
            <flux:subheading>Connect your existing models to Prospector</flux:subheading>
        </div>
        @if ($this->bridgeEnabled)
            <flux:badge color="green" size="sm">Bridge Enabled</flux:badge>
        @else
            <flux:badge color="zinc" size="sm">Bridge Disabled</flux:badge>
        @endif
    </div>

    @if (! $this->bridgeEnabled)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center dark:border-amber-800 dark:bg-amber-900/20">
            <flux:icon name="exclamation-triangle" class="mx-auto mb-2 size-8 text-amber-500" />
            <p class="text-sm text-amber-800 dark:text-amber-300">
                Model Bridge is disabled. Set <code class="rounded bg-amber-100 px-1 py-0.5 font-mono text-xs dark:bg-amber-800">PROSPECTOR_BRIDGE_ENABLED=true</code> in your <code>.env</code> file and configure your models in <code>config/prospector-bridge.php</code>.
            </p>
        </div>
    @else
        {{-- Company Mapping --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                <div>
                    <flux:heading size="lg">Company Mapping</flux:heading>
                    @if ($this->companyConfig['configured'])
                        <flux:subheading class="font-mono text-xs">{{ $this->companyConfig['model'] }}</flux:subheading>
                    @else
                        <flux:subheading class="text-amber-600">Not configured</flux:subheading>
                    @endif
                </div>
                @if ($this->companyConfig['configured'])
                    <div class="flex items-center gap-3">
                        <div class="text-right text-xs text-zinc-500">
                            <div>Host: <span class="font-bold text-zinc-900 dark:text-zinc-100">{{ $this->companyConfig['host_count'] }}</span></div>
                            <div>Synced: <span class="font-bold text-green-600">{{ $this->companyConfig['synced_count'] }}</span></div>
                        </div>
                        <flux:button size="sm" variant="primary" wire:click="runImport('companies')" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="runImport('companies')">Import All</span>
                            <span wire:loading wire:target="runImport('companies')">Queuing...</span>
                        </flux:button>
                    </div>
                @endif
            </div>

            @if ($this->companyConfig['configured'] && ! empty($this->companyConfig['fields']))
                <div class="p-4">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-zinc-500">
                                <th class="pb-2 font-medium">Prospector Field</th>
                                <th class="pb-2 font-medium">Your Model Field</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                            @foreach ($this->companyConfig['fields'] as $prospectorField => $hostField)
                                <tr>
                                    <td class="py-1.5 font-mono text-xs text-zinc-900 dark:text-zinc-100">{{ $prospectorField }}</td>
                                    <td class="py-1.5 font-mono text-xs text-blue-600 dark:text-blue-400">{{ is_string($hostField) ? $hostField : 'callable' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Contact Mapping --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                <div>
                    <flux:heading size="lg">Contact Mapping</flux:heading>
                    @if ($this->contactConfig['configured'])
                        <flux:subheading class="font-mono text-xs">{{ $this->contactConfig['model'] }}</flux:subheading>
                        @if ($this->contactConfig['company_key'])
                            <div class="mt-0.5 text-[10px] text-zinc-400">Company FK: <span class="font-mono">{{ $this->contactConfig['company_key'] }}</span></div>
                        @endif
                    @else
                        <flux:subheading class="text-amber-600">Not configured</flux:subheading>
                    @endif
                </div>
                @if ($this->contactConfig['configured'])
                    <div class="flex items-center gap-3">
                        <div class="text-right text-xs text-zinc-500">
                            <div>Host: <span class="font-bold text-zinc-900 dark:text-zinc-100">{{ $this->contactConfig['host_count'] }}</span></div>
                            <div>Synced: <span class="font-bold text-green-600">{{ $this->contactConfig['synced_count'] }}</span></div>
                        </div>
                        <flux:button size="sm" variant="primary" wire:click="runImport('contacts')" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="runImport('contacts')">Import All</span>
                            <span wire:loading wire:target="runImport('contacts')">Queuing...</span>
                        </flux:button>
                    </div>
                @endif
            </div>

            @if ($this->contactConfig['configured'] && ! empty($this->contactConfig['fields']))
                <div class="p-4">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-zinc-500">
                                <th class="pb-2 font-medium">Prospector Field</th>
                                <th class="pb-2 font-medium">Your Model Field</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                            @foreach ($this->contactConfig['fields'] as $prospectorField => $hostField)
                                <tr>
                                    <td class="py-1.5 font-mono text-xs text-zinc-900 dark:text-zinc-100">{{ $prospectorField }}</td>
                                    <td class="py-1.5 font-mono text-xs text-blue-600 dark:text-blue-400">{{ is_string($hostField) ? $hostField : 'callable' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
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
                    <div class="rounded-md border border-zinc-100 p-3 dark:border-zinc-700">
                        <div class="mb-1 text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $company->name }}</div>
                        <div class="flex items-center gap-2">
                            <code class="flex-1 rounded bg-zinc-100 px-2 py-1 font-mono text-xs text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                POST {{ url("/prospector/webhook/{$company->ulid}/leads") }}
                            </code>
                            <flux:button size="xs" variant="ghost" icon="clipboard-document"
                                x-on:click="navigator.clipboard.writeText('{{ url("/prospector/webhook/{$company->ulid}/leads") }}')"
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
