<?php

use App\Models\SaasComparisonScenario;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.public')] class extends Component {
    public int $userCount = 10;

    public float $selfHostServer = 96;
    public float $selfHostForge = 19;
    public float $selfHostDomain = 1;
    public float $selfHostStorage = 25;
    public float $selfHostTwilio = 20;
    public float $selfHostMessaging = 100;
    public float $selfHostPython = 0;
    public float $selfHostMisc = 539;

    public float $hoursConsolidation = 5;
    public float $hoursPerEstimate = 2;
    public int $estimatesPerWeek = 5;
    public float $hoursSwitching = 1;
    public float $trainingHoursSaved = 40;
    public float $employeeHourValue = 35;
    public int $workWeeksPerYear = 50;

    public array $enabledTools = [];

    public string $scenarioName = '';
    public string $createdAt = '';

    public function mount(string $token): void
    {
        $scenario = SaasComparisonScenario::where('public_token', $token)->first();

        if (! $scenario) {
            abort(404, 'Report not found.');
        }

        if (! $scenario->isPublicTokenValid()) {
            abort(410, 'This report link has expired.');
        }

        $settings = $scenario->settings ?? [];

        foreach ($settings as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }

        $this->scenarioName = $scenario->name;
        $this->createdAt = $scenario->created_at->format('F j, Y');

        $defaults = self::getToolDefaults();
        foreach (array_keys($defaults) as $slug) {
            if (! isset($this->enabledTools[$slug])) {
                $this->enabledTools[$slug] = true;
            }
        }
    }

    public static function getToolDefaults(): array
    {
        return [
            'estimating' => ['name' => 'PaintScout', 'feature' => 'Estimating & Proposals', 'icon' => 'calculator', 'type' => 'per_user', 'base' => 0, 'per_user' => 168, 'included' => 0],
            'crm' => ['name' => 'GoHighLevel', 'feature' => 'CRM & Communications', 'icon' => 'users', 'type' => 'flat', 'base' => 297, 'per_user' => 0, 'included' => 0],
            'video' => ['name' => 'Loom', 'feature' => 'Video Recording & Sharing', 'icon' => 'video-camera', 'type' => 'per_user', 'base' => 0, 'per_user' => 24, 'included' => 0],
            'photos' => ['name' => 'CompanyCam', 'feature' => 'Photo Documentation', 'icon' => 'camera', 'type' => 'hybrid', 'base' => 149, 'per_user' => 34, 'included' => 3],
            'field-service' => ['name' => 'Jobber', 'feature' => 'Job Progress & Management', 'icon' => 'clipboard-document-check', 'type' => 'hybrid', 'base' => 349, 'per_user' => 29, 'included' => 10],
            'd2d-sales' => ['name' => 'SalesRabbit', 'feature' => 'Door-to-Door Sales', 'icon' => 'map-pin', 'type' => 'per_user', 'base' => 0, 'per_user' => 75, 'included' => 0],
            'accounting' => ['name' => 'QuickBooks', 'feature' => 'Accounting & Finance', 'icon' => 'banknotes', 'type' => 'flat', 'base' => 275, 'per_user' => 0, 'included' => 0],
            'payroll' => ['name' => 'Gusto', 'feature' => 'Payroll Processing', 'icon' => 'currency-dollar', 'type' => 'hybrid', 'base' => 80, 'per_user' => 12, 'included' => 0],
            'hr' => ['name' => 'BambooHR', 'feature' => 'HR & Onboarding', 'icon' => 'identification', 'type' => 'per_user', 'base' => 0, 'per_user' => 10, 'included' => 0],
            'training' => ['name' => 'Trainual', 'feature' => 'Employee Training', 'icon' => 'academic-cap', 'type' => 'hybrid', 'base' => 249, 'per_user' => 3, 'included' => 10],
            'scheduling' => ['name' => 'Homebase', 'feature' => 'Scheduling & Time Tracking', 'icon' => 'clock', 'type' => 'flat', 'base' => 70, 'per_user' => 0, 'included' => 0],
            'booking' => ['name' => 'Calendly', 'feature' => 'Appointment Booking', 'icon' => 'calendar-days', 'type' => 'per_user', 'base' => 0, 'per_user' => 20, 'included' => 0],
            'sms-bots' => ['name' => 'ManyChat', 'feature' => 'SMS & Messenger Bots', 'icon' => 'chat-bubble-left-right', 'type' => 'hybrid', 'base' => 45, 'per_user' => 39, 'included' => 3],
            'contracts' => ['name' => 'DocuSign', 'feature' => 'Contract & Document Signing', 'icon' => 'pencil-square', 'type' => 'per_user', 'base' => 0, 'per_user' => 45, 'included' => 0],
            'business-plan' => ['name' => 'LivePlan', 'feature' => 'Business Planning', 'icon' => 'presentation-chart-line', 'type' => 'flat', 'base' => 40, 'per_user' => 0, 'included' => 0],
            'website' => ['name' => 'WordPress + WP Engine', 'feature' => 'Landing Page & Website', 'icon' => 'globe-alt', 'type' => 'flat', 'base' => 96, 'per_user' => 0, 'included' => 0],
            'visualizer' => ['name' => 'Custom Visualization API', 'feature' => 'AI Color Visualizer', 'icon' => 'paint-brush', 'type' => 'flat', 'base' => 100, 'per_user' => 0, 'included' => 0],
            'blueprints' => ['name' => 'HOVER', 'feature' => 'AI Blueprint Reader', 'icon' => 'document-magnifying-glass', 'type' => 'flat', 'base' => 500, 'per_user' => 0, 'included' => 0],
            'project-mgmt' => ['name' => 'Monday.com', 'feature' => 'Project Mgmt & KPIs', 'icon' => 'squares-2x2', 'type' => 'per_user', 'base' => 0, 'per_user' => 12, 'included' => 0],
            'email-marketing' => ['name' => 'Mailchimp', 'feature' => 'Email Marketing', 'icon' => 'envelope', 'type' => 'flat', 'base' => 45, 'per_user' => 0, 'included' => 0],
            'support-chat' => ['name' => 'Freshdesk', 'feature' => 'Customer Support Chat', 'icon' => 'chat-bubble-oval-left-ellipsis', 'type' => 'per_user', 'base' => 0, 'per_user' => 15, 'included' => 0],
            'recruiting' => ['name' => 'JazzHR', 'feature' => 'Recruiting & ATS', 'icon' => 'user-plus', 'type' => 'flat', 'base' => 75, 'per_user' => 0, 'included' => 0],
            'video-calling' => ['name' => 'Zoom', 'feature' => 'Video Calling', 'icon' => 'phone', 'type' => 'per_user', 'base' => 0, 'per_user' => 22, 'included' => 0],
            'flyers' => ['name' => 'Canva', 'feature' => 'Marketing Flyers', 'icon' => 'photo', 'type' => 'flat', 'base' => 15, 'per_user' => 0, 'included' => 0],
            'vendor-mgmt' => ['name' => 'Bill.com', 'feature' => 'Vendor Management', 'icon' => 'building-storefront', 'type' => 'flat', 'base' => 45, 'per_user' => 0, 'included' => 0],
            'automation' => ['name' => 'Zapier', 'feature' => 'Workflow Automation', 'icon' => 'bolt', 'type' => 'flat', 'base' => 100, 'per_user' => 0, 'included' => 0],
        ];
    }
};
?>

@section('robots', 'noindex, nofollow')

<div x-data="saasSavingsPublic()"
     data-tools='@json(self::getToolDefaults())'
     data-user-count="{{ $userCount }}"
     data-enabled-tools='@json($enabledTools)'
     data-self-host-server="{{ $selfHostServer }}"
     data-self-host-forge="{{ $selfHostForge }}"
     data-self-host-domain="{{ $selfHostDomain }}"
     data-self-host-storage="{{ $selfHostStorage }}"
     data-self-host-twilio="{{ $selfHostTwilio }}"
     data-self-host-messaging="{{ $selfHostMessaging }}"
     data-self-host-python="{{ $selfHostPython }}"
     data-self-host-misc="{{ $selfHostMisc }}"
     data-hours-consolidation="{{ $hoursConsolidation }}"
     data-hours-per-estimate="{{ $hoursPerEstimate }}"
     data-estimates-per-week="{{ $estimatesPerWeek }}"
     data-hours-switching="{{ $hoursSwitching }}"
     data-training-hours-saved="{{ $trainingHoursSaved }}"
     data-employee-hour-value="{{ $employeeHourValue }}"
     data-work-weeks-per-year="{{ $workWeeksPerYear }}"
     class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8 space-y-8">

    {{-- Header --}}
    <div class="text-center space-y-3">
        <div class="inline-flex items-center gap-2 px-4 py-1.5 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-full text-sm font-medium">
            <flux:icon.chart-bar-square class="size-4" />
            Platform Value Report
        </div>
        <h1 class="text-3xl sm:text-4xl font-bold text-zinc-900 dark:text-white">
            {{ $scenarioName ?: 'SaaS Savings Report' }}
        </h1>
        <p class="text-zinc-500 dark:text-zinc-400">
            Generated on {{ $createdAt }}
        </p>
        <div class="inline-flex items-center gap-2 px-3 py-1 bg-zinc-100 dark:bg-zinc-800 rounded-full text-sm text-zinc-600 dark:text-zinc-300">
            <flux:icon.users class="size-4" />
            Based on <strong class="mx-1">{{ $userCount }}</strong> users
        </div>
    </div>

    {{-- Hero Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Total SaaS Cost --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 text-center">
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-1">SaaS Stack Cost</p>
            <p class="text-2xl font-bold text-red-600 dark:text-red-400" x-text="formatMoney(totalSaasCost)"></p>
            <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-1">per month</p>
        </div>

        {{-- Platform Cost --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 text-center">
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-1">Platform Cost</p>
            <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400" x-text="formatMoney(totalSelfHostCost)"></p>
            <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-1">per month</p>
        </div>

        {{-- Monthly Savings --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-emerald-200 dark:border-emerald-800 p-5 text-center">
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-1">Monthly Savings</p>
            <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400" x-text="formatMoney(monthlySavings)"></p>
            <p class="text-xs text-emerald-500 dark:text-emerald-500 mt-1">saved each month</p>
        </div>

        {{-- Annual Savings --}}
        <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 dark:from-emerald-900/30 dark:to-emerald-800/20 rounded-xl border border-emerald-200 dark:border-emerald-800 p-5 text-center">
            <p class="text-sm font-medium text-emerald-700 dark:text-emerald-300 mb-1">Annual Savings</p>
            <p class="text-3xl font-extrabold text-emerald-700 dark:text-emerald-300" x-text="formatMoney(annualSavings)"></p>
            <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">saved per year</p>
        </div>
    </div>

    {{-- Visual Cost Comparison --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Cost Comparison</h2>

        {{-- SaaS Stack Bar --}}
        <div class="space-y-2">
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium text-zinc-700 dark:text-zinc-300">SaaS Stack (26 tools)</span>
                <span class="font-semibold text-red-600 dark:text-red-400" x-text="formatMoney(totalSaasCost) + '/mo'"></span>
            </div>
            <div class="w-full bg-zinc-100 dark:bg-zinc-700 rounded-full h-8 overflow-hidden">
                <div class="bg-red-500/80 dark:bg-red-500/60 h-8 rounded-full transition-all duration-500" style="width: 100%"></div>
            </div>
        </div>

        {{-- Self-Hosted Bar --}}
        <div class="space-y-2">
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium text-zinc-700 dark:text-zinc-300">Unified Platform</span>
                <span class="font-semibold text-emerald-600 dark:text-emerald-400" x-text="formatMoney(totalSelfHostCost) + '/mo'"></span>
            </div>
            <div class="w-full bg-zinc-100 dark:bg-zinc-700 rounded-full h-8 overflow-hidden">
                <div class="bg-emerald-500/80 dark:bg-emerald-500/60 h-8 rounded-full transition-all duration-500"
                     :style="'width: ' + (totalSaasCost > 0 ? Math.max((totalSelfHostCost / totalSaasCost) * 100, 3) : 0) + '%'"></div>
            </div>
        </div>

        <p class="text-center text-sm font-medium text-emerald-600 dark:text-emerald-400">
            <span x-text="totalSaasCost > 0 ? Math.round((1 - totalSelfHostCost / totalSaasCost) * 100) + '% lower cost' : ''"></span>
        </p>
    </div>

    {{-- Tool Breakdown Table --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
        <div class="px-6 py-4 border-b border-zinc-200 dark:border-zinc-700">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Software Replaced</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">Tools included in your scenario</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                        <th class="text-left px-6 py-3 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Tool</th>
                        <th class="text-left px-6 py-3 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Feature Replaced</th>
                        <th class="text-right px-6 py-3 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Monthly Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    <template x-for="(tool, slug) in tools" :key="slug">
                        <tr x-show="enabledTools[slug]" class="hover:bg-zinc-50 dark:hover:bg-zinc-700/30 transition-colors">
                            <td class="px-6 py-3">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white" x-text="tool.name"></span>
                            </td>
                            <td class="px-6 py-3">
                                <span class="text-sm text-zinc-500 dark:text-zinc-400" x-text="tool.feature"></span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <span class="text-sm font-medium text-red-600 dark:text-red-400" x-text="formatMoney(toolCost(slug))"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                        <td colspan="2" class="px-6 py-3 text-sm font-semibold text-zinc-900 dark:text-white">Total SaaS Cost</td>
                        <td class="px-6 py-3 text-right text-sm font-bold text-red-600 dark:text-red-400" x-text="formatMoney(totalSaasCost) + '/mo'"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- Time Savings --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">Time Savings</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            <div class="text-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <p class="text-sm font-medium text-blue-700 dark:text-blue-300 mb-1">Weekly Hours Saved</p>
                <p class="text-3xl font-bold text-blue-700 dark:text-blue-300" x-text="weeklyTimeSavings.toFixed(1)"></p>
                <p class="text-xs text-blue-600 dark:text-blue-400 mt-1">hours per week</p>
            </div>
            <div class="text-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <p class="text-sm font-medium text-blue-700 dark:text-blue-300 mb-1">Annual Hours Saved</p>
                <p class="text-3xl font-bold text-blue-700 dark:text-blue-300" x-text="Math.round(annualTimeSavings).toLocaleString()"></p>
                <p class="text-xs text-blue-600 dark:text-blue-400 mt-1">hours per year</p>
            </div>
            <div class="text-center p-4 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg">
                <p class="text-sm font-medium text-emerald-700 dark:text-emerald-300 mb-1">Annual Time Value</p>
                <p class="text-3xl font-bold text-emerald-700 dark:text-emerald-300" x-text="formatMoney(annualTimeValue)"></p>
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">at ${{ number_format($employeeHourValue) }}/hr</p>
            </div>
        </div>
    </div>

    {{-- Combined Value Summary --}}
    <div class="bg-gradient-to-br from-zinc-900 to-zinc-800 dark:from-zinc-800 dark:to-zinc-900 rounded-xl border border-zinc-700 p-6 sm:p-8 text-white">
        <h2 class="text-lg font-semibold text-zinc-200 mb-6 text-center">Combined Annual Value</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-6">
            <div class="text-center">
                <p class="text-sm text-zinc-400 mb-1">Cost Savings / Year</p>
                <p class="text-2xl font-bold text-emerald-400" x-text="formatMoney(annualSavings)"></p>
            </div>
            <div class="text-center">
                <p class="text-sm text-zinc-400 mb-1">Time Value / Year</p>
                <p class="text-2xl font-bold text-blue-400" x-text="formatMoney(annualTimeValue)"></p>
            </div>
            <div class="text-center">
                <p class="text-sm text-zinc-400 mb-1">ROI</p>
                <p class="text-2xl font-bold text-amber-400" x-text="roiPercent + '%'"></p>
            </div>
        </div>
        <div class="border-t border-zinc-600 pt-6 text-center">
            <p class="text-sm text-zinc-400 uppercase tracking-wider mb-2">Total Annual Platform Value</p>
            <p class="text-4xl sm:text-5xl font-extrabold text-emerald-400" x-text="formatMoney(totalAnnualValue)"></p>
        </div>
    </div>

    {{-- Footer --}}
    <div class="text-center space-y-2 pt-4">
        <p class="text-sm text-zinc-400 dark:text-zinc-500">
            Generated on {{ $createdAt }}
        </p>
        <p class="text-xs text-zinc-400 dark:text-zinc-500">
            Powered by {{ config('app.name') }} Platform
        </p>
        <p class="text-xs text-zinc-400 dark:text-zinc-500 max-w-xl mx-auto mt-4">
            Savings estimates are based on published pricing for each listed tool as of the report date.
            Actual costs may vary depending on plan selections, contract terms, and usage levels.
        </p>
    </div>
</div>

@script
<script>
    Alpine.data('saasSavingsPublic', () => ({
        tools: {},
        enabledTools: {},
        userCount: 10,
        selfHostServer: 96,
        selfHostForge: 19,
        selfHostDomain: 1,
        selfHostStorage: 25,
        selfHostTwilio: 20,
        selfHostMessaging: 100,
        selfHostPython: 0,
        selfHostMisc: 539,
        hoursConsolidation: 5,
        hoursPerEstimate: 2,
        estimatesPerWeek: 5,
        hoursSwitching: 1,
        trainingHoursSaved: 40,
        employeeHourValue: 35,
        workWeeksPerYear: 50,

        init() {
            this.tools = JSON.parse(this.$el.dataset.tools || '{}');
            this.enabledTools = JSON.parse(this.$el.dataset.enabledTools || '{}');
            this.userCount = Number(this.$el.dataset.userCount) || 10;
            this.selfHostServer = Number(this.$el.dataset.selfHostServer) || 0;
            this.selfHostForge = Number(this.$el.dataset.selfHostForge) || 0;
            this.selfHostDomain = Number(this.$el.dataset.selfHostDomain) || 0;
            this.selfHostStorage = Number(this.$el.dataset.selfHostStorage) || 0;
            this.selfHostTwilio = Number(this.$el.dataset.selfHostTwilio) || 0;
            this.selfHostMessaging = Number(this.$el.dataset.selfHostMessaging) || 0;
            this.selfHostPython = Number(this.$el.dataset.selfHostPython) || 0;
            this.selfHostMisc = Number(this.$el.dataset.selfHostMisc) || 0;
            this.hoursConsolidation = Number(this.$el.dataset.hoursConsolidation) || 0;
            this.hoursPerEstimate = Number(this.$el.dataset.hoursPerEstimate) || 0;
            this.estimatesPerWeek = Number(this.$el.dataset.estimatesPerWeek) || 0;
            this.hoursSwitching = Number(this.$el.dataset.hoursSwitching) || 0;
            this.trainingHoursSaved = Number(this.$el.dataset.trainingHoursSaved) || 0;
            this.employeeHourValue = Number(this.$el.dataset.employeeHourValue) || 0;
            this.workWeeksPerYear = Number(this.$el.dataset.workWeeksPerYear) || 0;
        },

        toolCost(slug) {
            let tool = this.tools[slug];
            if (!tool || !this.enabledTools[slug]) return 0;

            if (tool.type === 'flat') return tool.base;
            if (tool.type === 'per_user') return tool.per_user * this.userCount;
            if (tool.type === 'hybrid') {
                let extra = Math.max(0, this.userCount - tool.included);
                return tool.base + (tool.per_user * extra);
            }
            return 0;
        },

        get totalSaasCost() {
            let total = 0;
            for (let slug in this.tools) {
                if (this.enabledTools[slug]) {
                    total += this.toolCost(slug);
                }
            }
            return total;
        },

        get totalSelfHostCost() {
            return this.selfHostServer
                + this.selfHostForge
                + this.selfHostDomain
                + this.selfHostStorage
                + this.selfHostTwilio
                + this.selfHostMessaging
                + this.selfHostPython
                + this.selfHostMisc;
        },

        get monthlySavings() {
            return Math.max(0, this.totalSaasCost - this.totalSelfHostCost);
        },

        get annualSavings() {
            return this.monthlySavings * 12;
        },

        get weeklyTimeSavings() {
            let estimateHours = this.hoursPerEstimate * this.estimatesPerWeek;
            return this.hoursConsolidation + estimateHours + this.hoursSwitching;
        },

        get annualTimeSavings() {
            let weeklyTotal = this.weeklyTimeSavings * this.workWeeksPerYear;
            return weeklyTotal + this.trainingHoursSaved;
        },

        get annualTimeValue() {
            return this.annualTimeSavings * this.employeeHourValue;
        },

        get totalAnnualValue() {
            return this.annualSavings + this.annualTimeValue;
        },

        get roiPercent() {
            let annualPlatformCost = this.totalSelfHostCost * 12;
            if (annualPlatformCost <= 0) return 0;
            return Math.round((this.totalAnnualValue / annualPlatformCost) * 100);
        },

        formatMoney(n) {
            return '$' + Math.round(n).toLocaleString();
        },
    }));
</script>
@endscript
