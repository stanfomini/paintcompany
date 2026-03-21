<?php

use App\Models\SaasComparisonScenario;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    // User count slider
    public int $userCount = 10;

    // Self-hosting costs (all adjustable)
    public float $selfHostServer = 96;
    public float $selfHostForge = 19;
    public float $selfHostDomain = 1;
    public float $selfHostStorage = 25;
    public float $selfHostTwilio = 20;
    public float $selfHostMessaging = 100;
    public float $selfHostPython = 0;
    public float $selfHostMisc = 539;

    // Time savings sliders
    public float $hoursConsolidation = 5;
    public float $hoursPerEstimate = 2;
    public int $estimatesPerWeek = 5;
    public float $hoursSwitching = 1;
    public float $trainingHoursSaved = 40;
    public float $employeeHourValue = 35;
    public int $workWeeksPerYear = 50;

    // Tool toggles
    public array $enabledTools = [];

    // Scenario management
    public string $scenarioName = '';
    public bool $showShareModal = false;
    public ?string $shareUrl = null;

    public function boot(): void
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (! $user || ! $user->can('business_plan.view')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $defaults = self::getToolDefaults();
        $this->enabledTools = array_fill_keys(array_keys($defaults), true);
    }

    public function toggleTool(string $slug): void
    {
        $this->enabledTools[$slug] = ! ($this->enabledTools[$slug] ?? true);
    }

    public function saveScenario(): void
    {
        $this->validate(['scenarioName' => 'required|string|max:255']);

        SaasComparisonScenario::create([
            'name' => $this->scenarioName,
            'created_by' => auth()->id(),
            'settings' => $this->getAllSettings(),
        ]);

        $this->scenarioName = '';
        unset($this->scenarios);
    }

    public function loadScenario(int $id): void
    {
        $scenario = SaasComparisonScenario::findOrFail($id);
        $settings = $scenario->settings;

        foreach ($settings as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function deleteScenario(int $id): void
    {
        SaasComparisonScenario::where('id', $id)
            ->where('created_by', auth()->id())
            ->delete();

        unset($this->scenarios);
    }

    public function generateShareLink(): void
    {
        $scenario = SaasComparisonScenario::create([
            'name' => 'Shared Report - ' . now()->format('M j, Y'),
            'created_by' => auth()->id(),
            'settings' => $this->getAllSettings(),
        ]);

        $scenario->generatePublicToken(30);
        $this->shareUrl = route('saas-report.public', $scenario->public_token);
        $this->showShareModal = true;
    }

    #[Computed]
    public function scenarios(): \Illuminate\Support\Collection
    {
        return SaasComparisonScenario::where('created_by', auth()->id())
            ->whereNull('public_token')
            ->orderByDesc('updated_at')
            ->get();
    }

    private function getAllSettings(): array
    {
        return [
            'userCount' => $this->userCount,
            'selfHostServer' => $this->selfHostServer,
            'selfHostForge' => $this->selfHostForge,
            'selfHostDomain' => $this->selfHostDomain,
            'selfHostStorage' => $this->selfHostStorage,
            'selfHostTwilio' => $this->selfHostTwilio,
            'selfHostMessaging' => $this->selfHostMessaging,
            'selfHostPython' => $this->selfHostPython,
            'selfHostMisc' => $this->selfHostMisc,
            'hoursConsolidation' => $this->hoursConsolidation,
            'hoursPerEstimate' => $this->hoursPerEstimate,
            'estimatesPerWeek' => $this->estimatesPerWeek,
            'hoursSwitching' => $this->hoursSwitching,
            'trainingHoursSaved' => $this->trainingHoursSaved,
            'employeeHourValue' => $this->employeeHourValue,
            'workWeeksPerYear' => $this->workWeeksPerYear,
            'enabledTools' => $this->enabledTools,
        ];
    }

    public static function getToolDefaults(): array
    {
        return [
            'estimating' => [
                'name' => 'PaintScout',
                'feature' => 'Estimating & Proposals',
                'icon' => 'calculator',
                'type' => 'per_user',
                'base' => 0,
                'per_user' => 168,
                'included' => 0,
            ],
            'crm' => [
                'name' => 'GoHighLevel',
                'feature' => 'CRM & Communications',
                'icon' => 'users',
                'type' => 'flat',
                'base' => 297,
                'per_user' => 0,
                'included' => 0,
            ],
            'video' => [
                'name' => 'Loom',
                'feature' => 'Video Recording & Sharing',
                'icon' => 'video-camera',
                'type' => 'per_user',
                'base' => 0,
                'per_user' => 24,
                'included' => 0,
            ],
            'photos' => [
                'name' => 'CompanyCam',
                'feature' => 'Photo Documentation',
                'icon' => 'camera',
                'type' => 'hybrid',
                'base' => 149,
                'per_user' => 34,
                'included' => 3,
            ],
            'field-service' => [
                'name' => 'Jobber',
                'feature' => 'Job Progress & Management',
                'icon' => 'clipboard-document-check',
                'type' => 'hybrid',
                'base' => 349,
                'per_user' => 29,
                'included' => 10,
            ],
            'd2d-sales' => [
                'name' => 'SalesRabbit',
                'feature' => 'Door-to-Door Sales',
                'icon' => 'map-pin',
                'type' => 'per_user',
                'base' => 0,
                'per_user' => 75,
                'included' => 0,
            ],
            'accounting' => [
                'name' => 'QuickBooks',
                'feature' => 'Accounting & Finance',
                'icon' => 'banknotes',
                'type' => 'flat',
                'base' => 275,
                'per_user' => 0,
                'included' => 0,
            ],
            'payroll' => [
                'name' => 'Gusto',
                'feature' => 'Payroll Processing',
                'icon' => 'currency-dollar',
                'type' => 'hybrid',
                'base' => 80,
                'per_user' => 12,
                'included' => 0,
            ],
            'hr' => [
                'name' => 'BambooHR',
                'feature' => 'HR & Onboarding',
                'icon' => 'identification',
                'type' => 'per_user',
                'base' => 0,
                'per_user' => 10,
                'included' => 0,
            ],
            'training' => [
                'name' => 'Trainual',
                'feature' => 'Employee Training',
                'icon' => 'academic-cap',
                'type' => 'hybrid',
                'base' => 249,
                'per_user' => 3,
                'included' => 10,
            ],
            'scheduling' => [
                'name' => 'Homebase',
                'feature' => 'Scheduling & Time Tracking',
                'icon' => 'clock',
                'type' => 'flat',
                'base' => 70,
                'per_user' => 0,
                'included' => 0,
            ],
            'booking' => [
                'name' => 'Calendly',
                'feature' => 'Appointment Booking',
                'icon' => 'calendar-days',
                'type' => 'per_user',
                'base' => 0,
                'per_user' => 20,
                'included' => 0,
            ],
            'sms-bots' => [
                'name' => 'ManyChat',
                'feature' => 'SMS & Messenger Bots',
                'icon' => 'chat-bubble-left-right',
                'type' => 'hybrid',
                'base' => 45,
                'per_user' => 39,
                'included' => 3,
            ],
            'contracts' => [
                'name' => 'DocuSign',
                'feature' => 'Contract & Document Signing',
                'icon' => 'pencil-square',
                'type' => 'per_user',
                'base' => 0,
                'per_user' => 45,
                'included' => 0,
            ],
            'business-plan' => [
                'name' => 'LivePlan',
                'feature' => 'Business Planning',
                'icon' => 'presentation-chart-line',
                'type' => 'flat',
                'base' => 40,
                'per_user' => 0,
                'included' => 0,
            ],
            'website' => [
                'name' => 'WordPress + WP Engine',
                'feature' => 'Landing Page & Website',
                'icon' => 'globe-alt',
                'type' => 'flat',
                'base' => 96,
                'per_user' => 0,
                'included' => 0,
            ],
            'visualizer' => [
                'name' => 'Custom Visualization API',
                'feature' => 'AI Color Visualizer',
                'icon' => 'paint-brush',
                'type' => 'flat',
                'base' => 100,
                'per_user' => 0,
                'included' => 0,
            ],
            'blueprints' => [
                'name' => 'HOVER',
                'feature' => 'AI Blueprint Reader',
                'icon' => 'document-magnifying-glass',
                'type' => 'flat',
                'base' => 500,
                'per_user' => 0,
                'included' => 0,
            ],
            'project-mgmt' => [
                'name' => 'Monday.com',
                'feature' => 'Project Mgmt & KPIs',
                'icon' => 'squares-2x2',
                'type' => 'per_user',
                'base' => 0,
                'per_user' => 12,
                'included' => 0,
            ],
            'email-marketing' => [
                'name' => 'Mailchimp',
                'feature' => 'Email Marketing',
                'icon' => 'envelope',
                'type' => 'flat',
                'base' => 45,
                'per_user' => 0,
                'included' => 0,
            ],
            'support-chat' => [
                'name' => 'Freshdesk',
                'feature' => 'Customer Support Chat',
                'icon' => 'chat-bubble-oval-left-ellipsis',
                'type' => 'per_user',
                'base' => 0,
                'per_user' => 15,
                'included' => 0,
            ],
            'recruiting' => [
                'name' => 'JazzHR',
                'feature' => 'Recruiting & ATS',
                'icon' => 'user-plus',
                'type' => 'flat',
                'base' => 75,
                'per_user' => 0,
                'included' => 0,
            ],
            'video-calling' => [
                'name' => 'Zoom',
                'feature' => 'Video Calling',
                'icon' => 'phone',
                'type' => 'per_user',
                'base' => 0,
                'per_user' => 22,
                'included' => 0,
            ],
            'flyers' => [
                'name' => 'Canva',
                'feature' => 'Marketing Flyers',
                'icon' => 'photo',
                'type' => 'flat',
                'base' => 15,
                'per_user' => 0,
                'included' => 0,
            ],
            'vendor-mgmt' => [
                'name' => 'Bill.com',
                'feature' => 'Vendor Management',
                'icon' => 'building-storefront',
                'type' => 'flat',
                'base' => 45,
                'per_user' => 0,
                'included' => 0,
            ],
            'automation' => [
                'name' => 'Zapier',
                'feature' => 'Workflow Automation',
                'icon' => 'bolt',
                'type' => 'flat',
                'base' => 100,
                'per_user' => 0,
                'included' => 0,
            ],
        ];
    }
};
?>

<div x-data="saasSavings()" data-tools='@json(self::getToolDefaults())' class="space-y-6">
    <flux:heading size="xl">SaaS Cost Comparison Calculator</flux:heading>
    <p class="text-sm text-zinc-500 dark:text-zinc-400">Compare the cost of individual SaaS tools against your self-hosted platform. Adjust user count, toggle tools, and tune self-hosting costs to see real savings.</p>

    {{-- Section 1: Hero Dashboard --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Total Monthly SaaS Cost --}}
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <p class="text-xs font-medium uppercase tracking-wider text-red-600 dark:text-red-400">Total Monthly SaaS Cost</p>
            <p class="mt-1 text-2xl font-bold text-red-700 dark:text-red-300" x-text="formatMoney(totalSaasCost)"></p>
            <p class="mt-1 text-xs text-red-500 dark:text-red-400"><span x-text="enabledToolCount"></span> tools enabled</p>
        </div>

        {{-- Your Monthly Cost --}}
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
            <p class="text-xs font-medium uppercase tracking-wider text-green-600 dark:text-green-400">Your Monthly Cost</p>
            <p class="mt-1 text-2xl font-bold text-green-700 dark:text-green-300" x-text="formatMoney(totalSelfHostCost)"></p>
            <p class="mt-1 text-xs text-green-500 dark:text-green-400">Self-hosted platform</p>
        </div>

        {{-- Monthly Savings --}}
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
            <p class="text-xs font-medium uppercase tracking-wider text-green-600 dark:text-green-400">Monthly Savings</p>
            <p class="mt-1 text-2xl font-bold text-green-700 dark:text-green-300" x-text="formatMoney(monthlySavings)"></p>
            <p class="mt-1 text-xs text-green-500 dark:text-green-400" x-text="totalSaasCost > 0 ? Math.round((monthlySavings / totalSaasCost) * 100) + '% reduction' : '0% reduction'"></p>
        </div>

        {{-- Annual Savings --}}
        <div class="rounded-lg border-2 border-green-400 bg-green-50 p-4 dark:border-green-600 dark:bg-green-900/30">
            <p class="text-xs font-medium uppercase tracking-wider text-green-600 dark:text-green-400">Annual Savings</p>
            <p class="mt-1 text-3xl font-extrabold text-green-700 dark:text-green-300" x-text="formatMoney(annualSavings)"></p>
            <p class="mt-1 text-xs font-semibold text-green-600 dark:text-green-400" x-text="formatMoney(monthlySavings) + '/mo saved'"></p>
        </div>
    </div>

    {{-- Section 2: User Count Slider --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between">
            <flux:heading size="sm">Team Size</flux:heading>
            <span class="rounded-full bg-blue-100 px-3 py-1 text-lg font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300" x-text="$wire.userCount + ' users'"></span>
        </div>
        <input type="range" wire:model.live.debounce.300ms="userCount" min="1" max="50" step="1" class="mt-3 w-full">
        <div class="mt-2 flex justify-between text-xs text-zinc-400 dark:text-zinc-500">
            <span>1 user</span>
            <span>25 users</span>
            <span>50 users</span>
        </div>
        <div class="mt-3 flex items-center justify-center gap-6 text-sm">
            <div class="flex items-center gap-2">
                <span class="inline-block size-3 rounded-full bg-red-500"></span>
                <span class="text-zinc-600 dark:text-zinc-400">SaaS: <span class="font-semibold text-red-600 dark:text-red-400" x-text="formatMoney(costPerUserSaaS)"></span>/user</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-block size-3 rounded-full bg-green-500"></span>
                <span class="text-zinc-600 dark:text-zinc-400">Yours: <span class="font-semibold text-green-600 dark:text-green-400" x-text="formatMoney(costPerUserSelfHost)"></span>/user</span>
            </div>
            <div class="flex items-center gap-2">
                <flux:icon name="arrow-trending-down" variant="mini" class="size-4 text-green-500" />
                <span class="font-semibold text-green-600 dark:text-green-400" x-text="formatMoney(savingsPerUser) + '/user saved'"></span>
            </div>
        </div>
    </div>

    {{-- Section 3: Tool Breakdown Grid --}}
    <div>
        <flux:heading size="sm" class="mb-3">SaaS Tool Breakdown</flux:heading>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
            <template x-for="[slug, tool] of sortedTools" :key="slug">
                <div class="rounded-lg border border-zinc-200 bg-white p-4 transition-opacity dark:border-zinc-700 dark:bg-zinc-900"
                     :class="{ 'opacity-40': !$wire.enabledTools[slug] }">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-2">
                            <div class="flex size-8 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100" x-text="tool.name"></p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400" x-text="tool.feature"></p>
                            </div>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" class="peer sr-only"
                                   :checked="$wire.enabledTools[slug]"
                                   x-on:change="$wire.toggleTool(slug)">
                            <div class="peer h-5 w-9 rounded-full bg-zinc-300 after:absolute after:left-[2px] after:top-[2px] after:size-4 after:rounded-full after:bg-white after:transition-all peer-checked:bg-green-500 peer-checked:after:translate-x-full dark:bg-zinc-600 dark:peer-checked:bg-green-600"></div>
                        </label>
                    </div>
                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-lg font-bold" :class="$wire.enabledTools[slug] ? 'text-red-600 dark:text-red-400' : 'text-zinc-400 dark:text-zinc-500'" x-text="formatMoney(toolCost(slug)) + '/mo'"></span>
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium"
                              :class="tool.type === 'flat' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' : (tool.type === 'per_user' ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300')"
                              x-text="tool.type === 'flat' ? 'Flat' : (tool.type === 'per_user' ? 'Per User' : 'Hybrid')"></span>
                    </div>
                    <template x-if="tool.type === 'per_user'">
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500" x-text="'$' + tool.per_user + '/user/mo'"></p>
                    </template>
                    <template x-if="tool.type === 'hybrid'">
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500" x-text="'$' + tool.base + ' base + $' + tool.per_user + '/user (first ' + tool.included + ' included)'"></p>
                    </template>
                    <template x-if="tool.type === 'flat'">
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500" x-text="'$' + tool.base + ' flat rate'"></p>
                    </template>
                </div>
            </template>
        </div>
    </div>

    {{-- Section 4: Self-Hosting Cost Breakdown --}}
    <div x-data="{ showSelfHost: false }" class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <button x-on:click="showSelfHost = !showSelfHost" class="flex w-full items-center justify-between p-4">
            <div class="flex items-center gap-2">
                <flux:icon name="server-stack" variant="mini" class="size-5 text-green-500" />
                <flux:heading size="sm">Your Platform Costs</flux:heading>
                <span class="ml-2 text-sm font-semibold text-green-600 dark:text-green-400" x-text="formatMoney(totalSelfHostCost) + '/mo'"></span>
            </div>
            <flux:icon name="chevron-down" variant="mini" class="size-5 text-zinc-400 transition-transform" x-bind:class="showSelfHost && 'rotate-180'" />
        </button>
        <div x-show="showSelfHost" x-transition class="border-t border-zinc-200 p-4 dark:border-zinc-700">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Server (Hetzner): $<span x-text="$wire.selfHostServer"></span>/mo</label>
                    <input type="range" wire:model.live.debounce.300ms="selfHostServer" min="0" max="500" step="1" class="w-full">
                </div>
                <div>
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Laravel Forge: $<span x-text="$wire.selfHostForge"></span>/mo</label>
                    <input type="range" wire:model.live.debounce.300ms="selfHostForge" min="0" max="100" step="1" class="w-full">
                </div>
                <div>
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Domain: $<span x-text="$wire.selfHostDomain"></span>/mo</label>
                    <input type="range" wire:model.live.debounce.300ms="selfHostDomain" min="0" max="20" step="1" class="w-full">
                </div>
                <div>
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Storage (S3): $<span x-text="$wire.selfHostStorage"></span>/mo</label>
                    <input type="range" wire:model.live.debounce.300ms="selfHostStorage" min="0" max="200" step="5" class="w-full">
                </div>
                <div>
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Twilio (SMS): $<span x-text="$wire.selfHostTwilio"></span>/mo</label>
                    <input type="range" wire:model.live.debounce.300ms="selfHostTwilio" min="0" max="200" step="5" class="w-full">
                </div>
                <div>
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Messaging APIs: $<span x-text="$wire.selfHostMessaging"></span>/mo</label>
                    <input type="range" wire:model.live.debounce.300ms="selfHostMessaging" min="0" max="500" step="10" class="w-full">
                </div>
                <div>
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Python/ML Service: $<span x-text="$wire.selfHostPython"></span>/mo</label>
                    <input type="range" wire:model.live.debounce.300ms="selfHostPython" min="0" max="200" step="5" class="w-full">
                </div>
                <div>
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Misc (Dev/Maintenance): $<span x-text="$wire.selfHostMisc"></span>/mo</label>
                    <input type="range" wire:model.live.debounce.300ms="selfHostMisc" min="0" max="2000" step="10" class="w-full">
                </div>
            </div>
            <div class="mt-4 flex items-center justify-between border-t border-zinc-200 pt-3 dark:border-zinc-700">
                <span class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Total Platform Cost</span>
                <span class="text-lg font-bold text-green-600 dark:text-green-400" x-text="formatMoney(totalSelfHostCost) + '/mo'"></span>
            </div>
        </div>
    </div>

    {{-- Section 5: Time Savings Calculator --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mb-4 flex items-center gap-2">
            <flux:icon name="clock" variant="mini" class="size-5 text-blue-500" />
            <flux:heading size="sm">Time Savings Value</flux:heading>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Hours saved/week (single platform): <span x-text="formatHours($wire.hoursConsolidation)"></span>h</label>
                <input type="range" wire:model.live.debounce.300ms="hoursConsolidation" min="0" max="20" step="0.5" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Hours saved/estimate (AI builder): <span x-text="formatHours($wire.hoursPerEstimate)"></span>h</label>
                <input type="range" wire:model.live.debounce.300ms="hoursPerEstimate" min="0" max="5" step="0.5" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Estimates/week: <span x-text="$wire.estimatesPerWeek"></span></label>
                <input type="range" wire:model.live.debounce.300ms="estimatesPerWeek" min="1" max="20" step="1" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Hours saved/day (no tool switching): <span x-text="formatHours($wire.hoursSwitching)"></span>h</label>
                <input type="range" wire:model.live.debounce.300ms="hoursSwitching" min="0" max="4" step="0.5" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Annual training hours saved: <span x-text="$wire.trainingHoursSaved"></span>h</label>
                <input type="range" wire:model.live.debounce.300ms="trainingHoursSaved" min="0" max="200" step="10" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Employee hour value: $<span x-text="$wire.employeeHourValue"></span></label>
                <input type="range" wire:model.live.debounce.300ms="employeeHourValue" min="15" max="100" step="5" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Work weeks/year: <span x-text="$wire.workWeeksPerYear"></span></label>
                <input type="range" wire:model.live.debounce.300ms="workWeeksPerYear" min="40" max="52" step="1" class="w-full">
            </div>
        </div>
        {{-- Time Savings Summary Cards --}}
        <div class="mt-4 grid grid-cols-1 gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700 sm:grid-cols-3">
            <div class="rounded-lg bg-blue-50 p-3 dark:bg-blue-900/20">
                <p class="text-xs font-medium uppercase tracking-wider text-blue-600 dark:text-blue-400">Weekly Hours Saved</p>
                <p class="mt-1 text-xl font-bold text-blue-700 dark:text-blue-300" x-text="formatHours(weeklyTimeSavings) + 'h'"></p>
            </div>
            <div class="rounded-lg bg-blue-50 p-3 dark:bg-blue-900/20">
                <p class="text-xs font-medium uppercase tracking-wider text-blue-600 dark:text-blue-400">Annual Hours Saved</p>
                <p class="mt-1 text-xl font-bold text-blue-700 dark:text-blue-300" x-text="formatNumber(annualTimeSavings) + 'h'"></p>
            </div>
            <div class="rounded-lg bg-green-50 p-3 dark:bg-green-900/20">
                <p class="text-xs font-medium uppercase tracking-wider text-green-600 dark:text-green-400">Annual Time Value</p>
                <p class="mt-1 text-xl font-bold text-green-700 dark:text-green-300" x-text="formatMoney(annualTimeValue)"></p>
            </div>
        </div>
    </div>

    {{-- Section 6: Combined Value Summary --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="sm" class="mb-4">Total Platform Value</flux:heading>

        {{-- Visual Comparison Bars --}}
        <div class="space-y-3">
            <div class="relative h-10 overflow-hidden rounded-lg bg-red-100 dark:bg-red-900/30">
                <div class="absolute inset-y-0 left-0 rounded-lg bg-red-500 dark:bg-red-600" style="width: 100%"></div>
                <span class="absolute inset-0 flex items-center justify-center text-sm font-bold text-white" x-text="'SaaS Stack: ' + formatMoney(totalSaasCost) + '/mo'"></span>
            </div>
            <div class="relative h-10 overflow-hidden rounded-lg bg-green-100 dark:bg-green-900/30">
                <div class="absolute inset-y-0 left-0 rounded-lg bg-green-500 dark:bg-green-600" :style="'width: ' + Math.max(2, (totalSelfHostCost / Math.max(totalSaasCost, 1) * 100)) + '%'"></div>
                <span class="absolute inset-0 flex items-center justify-center text-sm font-bold text-white" x-text="'Your Platform: ' + formatMoney(totalSelfHostCost) + '/mo'"></span>
            </div>
        </div>

        {{-- Combined Value Numbers --}}
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Cost Savings</p>
                <p class="mt-1 text-xl font-bold text-green-600 dark:text-green-400" x-text="formatMoney(annualSavings) + '/yr'"></p>
            </div>
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Time Value</p>
                <p class="mt-1 text-xl font-bold text-blue-600 dark:text-blue-400" x-text="formatMoney(annualTimeValue) + '/yr'"></p>
            </div>
            <div class="rounded-lg border-2 border-green-400 bg-green-50 p-4 dark:border-green-600 dark:bg-green-900/30">
                <p class="text-xs font-medium uppercase tracking-wider text-green-600 dark:text-green-400">Total Platform Value</p>
                <p class="mt-1 text-2xl font-extrabold text-green-700 dark:text-green-300" x-text="formatMoney(totalAnnualValue) + '/yr'"></p>
            </div>
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">ROI</p>
                <p class="mt-1 text-2xl font-extrabold" :class="roiPercent >= 100 ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'" x-text="formatNumber(roiPercent) + '% ROI'"></p>
            </div>
        </div>
    </div>

    {{-- Section 7: Scenario Management --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <flux:heading size="sm">Saved Scenarios</flux:heading>
        <div class="flex gap-2">
            <flux:input wire:model="scenarioName" placeholder="Scenario name..." class="flex-1" />
            <flux:button wire:click="saveScenario" variant="primary">Save</flux:button>
        </div>
        @if($this->scenarios->isNotEmpty())
            <div class="flex flex-wrap gap-2">
                @foreach($this->scenarios as $scenario)
                    <div class="flex items-center gap-1" wire:key="scenario-{{ $scenario->id }}">
                        <flux:button size="sm" variant="ghost" wire:click="loadScenario({{ $scenario->id }})">
                            {{ $scenario->name }}
                        </flux:button>
                        <button wire:click="deleteScenario({{ $scenario->id }})" class="text-zinc-400 hover:text-red-500 text-xs">
                            <flux:icon name="x-mark" variant="mini" class="size-3" />
                        </button>
                    </div>
                @endforeach
            </div>
        @endif
        <div class="flex gap-2">
            <flux:button wire:click="generateShareLink" variant="outline" icon="share">Share Report</flux:button>
        </div>
    </div>

    {{-- Share Modal --}}
    <flux:modal wire:model="showShareModal" class="max-w-md">
        <flux:heading size="lg">Share Platform Value Report</flux:heading>
        <p class="text-sm text-zinc-500 mt-2">Anyone with this link can view a read-only snapshot of your current settings. Link expires in 30 days.</p>
        @if($shareUrl)
            <div class="mt-4">
                <flux:input :value="$shareUrl" readonly x-ref="shareInput" />
                <flux:button variant="primary" class="mt-2 w-full" x-on:click="navigator.clipboard.writeText($refs.shareInput.value); $el.textContent = 'Copied!'">Copy Link</flux:button>
            </div>
        @endif
    </flux:modal>
</div>

@script
<script>
Alpine.data('saasSavings', () => ({
    tools: {},

    init() {
        this.tools = JSON.parse(this.$el.dataset.tools || '{}');
    },

    toolCost(slug) {
        if (!this.$wire.enabledTools[slug]) return 0;
        let t = this.tools[slug];
        if (!t) return 0;
        let u = Number(this.$wire.userCount);
        if (t.type === 'flat') return t.base;
        if (t.type === 'per_user') return t.base + (t.per_user * u);
        if (t.type === 'hybrid') {
            return t.base + (t.per_user * Math.max(0, u - (t.included || 0)));
        }
        return 0;
    },

    get sortedTools() {
        let entries = Object.entries(this.tools);
        return entries.sort((a, b) => this.toolCost(b[0]) - this.toolCost(a[0]));
    },

    get enabledToolCount() {
        return Object.keys(this.tools).filter(s => this.$wire.enabledTools[s]).length;
    },

    get totalSaasCost() {
        return Object.keys(this.tools).reduce((sum, slug) => sum + this.toolCost(slug), 0);
    },

    get totalSelfHostCost() {
        return Number(this.$wire.selfHostServer)
            + Number(this.$wire.selfHostForge)
            + Number(this.$wire.selfHostDomain)
            + Number(this.$wire.selfHostStorage)
            + Number(this.$wire.selfHostTwilio)
            + Number(this.$wire.selfHostMessaging)
            + Number(this.$wire.selfHostPython)
            + Number(this.$wire.selfHostMisc);
    },

    get monthlySavings() { return this.totalSaasCost - this.totalSelfHostCost; },
    get annualSavings() { return this.monthlySavings * 12; },

    get weeklyTimeSavings() {
        let consolidation = Number(this.$wire.hoursConsolidation);
        let estimating = Number(this.$wire.hoursPerEstimate) * Number(this.$wire.estimatesPerWeek);
        let switching = Number(this.$wire.hoursSwitching) * 5;
        return consolidation + estimating + switching;
    },

    get annualTimeSavings() {
        return (this.weeklyTimeSavings * Number(this.$wire.workWeeksPerYear)) + Number(this.$wire.trainingHoursSaved);
    },

    get annualTimeValue() {
        return this.annualTimeSavings * Number(this.$wire.employeeHourValue);
    },

    get totalAnnualValue() { return this.annualSavings + this.annualTimeValue; },

    get costPerUserSaaS() { return this.totalSaasCost / Math.max(1, Number(this.$wire.userCount)); },
    get costPerUserSelfHost() { return this.totalSelfHostCost / Math.max(1, Number(this.$wire.userCount)); },
    get savingsPerUser() { return this.costPerUserSaaS - this.costPerUserSelfHost; },

    get roiPercent() {
        if (this.totalSelfHostCost <= 0) return 0;
        return Math.round((this.annualSavings / (this.totalSelfHostCost * 12)) * 100);
    },

    formatMoney(n) { return '$' + Math.round(n).toLocaleString(); },
    formatNumber(n) { return Math.round(n).toLocaleString(); },
    formatHours(n) { return Math.round(n * 10) / 10; },
}));
</script>
@endscript
