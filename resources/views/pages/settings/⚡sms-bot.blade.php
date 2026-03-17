<?php

use App\Models\CompanySetting;
use App\Models\SmsBotFlow;
use App\Models\SmsConversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $botEnabled = false;

    // Flow management
    public bool $showCreateFlow = false;
    public string $newFlowName = '';
    public string $newFlowTrigger = 'new_lead';
    public string $newFlowKeyword = '';
    public string $newFlowCompletion = 'none';
    public int $newFlowTimeout = 10080;
    public int $newFlowDelay = 5;
    public bool $newFlowBusinessHours = false;

    public function boot(): void
    {
        if (! auth()->user()?->can('settings.company')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $settings = CompanySetting::getInstance();
        $this->botEnabled = (bool) $settings->sms_bot_enabled;
    }

    #[Computed]
    public function flows()
    {
        return SmsBotFlow::withCount('conversations')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    #[Computed]
    public function recentConversations()
    {
        return SmsConversation::with(['preview', 'currentStep'])
            ->latest('last_message_at')
            ->limit(10)
            ->get();
    }

    public function toggleBot(): void
    {
        $this->botEnabled = ! $this->botEnabled;
        CompanySetting::getInstance()->update(['sms_bot_enabled' => $this->botEnabled]);
    }

    public function createFlow(): void
    {
        $rules = [
            'newFlowName' => 'required|string|max:255',
            'newFlowTrigger' => 'required|in:new_lead,keyword,ad_lead,booking_lead',
            'newFlowCompletion' => 'required|in:none,create_lead,create_deal,handoff',
            'newFlowTimeout' => 'required|integer|min:1',
            'newFlowDelay' => 'required|integer|min:0',
            'newFlowBusinessHours' => 'boolean',
        ];

        if ($this->newFlowTrigger === 'keyword') {
            $rules['newFlowKeyword'] = 'required|string|max:50';
        }

        $this->validate($rules);

        $attributes = [
            'name' => $this->newFlowName,
            'status' => 'draft',
            'trigger_type' => $this->newFlowTrigger,
            'trigger_keyword' => $this->newFlowTrigger === 'keyword' ? strtoupper(trim($this->newFlowKeyword)) : null,
            'completion_action' => $this->newFlowCompletion === 'none' ? null : $this->newFlowCompletion,
            'timeout_minutes' => $this->newFlowTimeout,
            'delay_after_lead_minutes' => $this->newFlowDelay,
            'business_hours_only' => $this->newFlowBusinessHours,
        ];

        // SQLite can't drop columns — old columns still have NOT NULL constraints in tests
        if (\DB::getDriverName() === 'sqlite') {
            $attributes['greeting_message'] = '';
            $attributes['photo_prompt_message'] = '';
            $attributes['visualization_message'] = '';
            $attributes['portal_link_message'] = '';
        }

        $flow = SmsBotFlow::create($attributes);

        $this->reset('showCreateFlow', 'newFlowName', 'newFlowTrigger', 'newFlowKeyword', 'newFlowCompletion', 'newFlowTimeout', 'newFlowDelay', 'newFlowBusinessHours');
        $this->newFlowTimeout = 10080;
        $this->newFlowDelay = 5;

        $this->redirect(route('settings.sms-bot.flows.edit', $flow), navigate: true);
    }

    public function toggleFlowStatus(int $flowId): void
    {
        $flow = SmsBotFlow::findOrFail($flowId);
        $newStatus = $flow->status === 'active' ? 'disabled' : 'active';

        // Only one active flow per trigger_type at a time
        if ($newStatus === 'active') {
            SmsBotFlow::where('trigger_type', $flow->trigger_type)
                ->where('status', 'active')
                ->update(['status' => 'disabled']);
        }

        $flow->update(['status' => $newStatus]);
        unset($this->flows);
    }

    public function deleteFlow(int $flowId): void
    {
        SmsBotFlow::findOrFail($flowId)->delete();
        unset($this->flows);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('SMS Bot Settings')" :subheading="__('Configure the SMS bot system and conversation flows.')">

        {{-- Global Toggle --}}
        <div class="my-6 flex items-center justify-between rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon name="chat-bubble-left-ellipsis" class="h-5 w-5 text-zinc-600 dark:text-zinc-400" />
                    <flux:heading size="sm">{{ __('SMS Bot System') }}</flux:heading>
                </div>
                <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('Enable or disable the automated SMS bot for lead engagement.') }}
                </flux:text>
            </div>
            <flux:switch wire:click="toggleBot" :checked="$botEnabled" />
        </div>

        <flux:separator />

        {{-- Flow Management --}}
        <div class="my-6 w-full space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="sm">{{ __('Bot Flows') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">{{ __('Define automated SMS conversation flows for lead engagement.') }}</flux:text>
                </div>
                <flux:button variant="primary" size="sm" icon="plus" wire:click="$toggle('showCreateFlow')">
                    {{ __('Create Flow') }}
                </flux:button>
            </div>

            {{-- Create Flow Form --}}
            @if ($showCreateFlow)
                <form wire:submit="createFlow" class="space-y-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="sm">{{ __('New Flow') }}</flux:heading>

                    <flux:input wire:model="newFlowName" :label="__('Flow Name')" placeholder="Default Lead Flow" />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:select wire:model.live="newFlowTrigger" :label="__('Trigger Type')">
                            <flux:select.option value="new_lead">{{ __('New Lead') }}</flux:select.option>
                            <flux:select.option value="keyword">{{ __('Keyword') }}</flux:select.option>
                            <flux:select.option value="ad_lead">{{ __('Ad Lead') }}</flux:select.option>
                            <flux:select.option value="booking_lead">{{ __('Booking Lead') }}</flux:select.option>
                        </flux:select>

                        @if ($newFlowTrigger === 'keyword')
                            <flux:input wire:model="newFlowKeyword" :label="__('Trigger Keyword')" placeholder="COLOR" description="{{ __('Stored UPPERCASE. Customer texts this word to start the flow.') }}" />
                        @endif
                    </div>

                    <flux:select wire:model="newFlowCompletion" :label="__('Completion Action')">
                        <flux:select.option value="none">{{ __('None') }}</flux:select.option>
                        <flux:select.option value="create_lead">{{ __('Create Lead') }}</flux:select.option>
                        <flux:select.option value="create_deal">{{ __('Create Deal') }}</flux:select.option>
                        <flux:select.option value="handoff">{{ __('Handoff to Human') }}</flux:select.option>
                    </flux:select>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:input wire:model.number="newFlowTimeout" :label="__('Timeout (minutes)')" type="number" min="1" />

                        @if ($newFlowTrigger === 'new_lead')
                            <flux:input wire:model.number="newFlowDelay" :label="__('Delay After Lead (minutes)')" type="number" min="0" />
                        @endif
                    </div>

                    <flux:switch wire:model="newFlowBusinessHours" :label="__('Business Hours Only')" />

                    <div class="flex items-center gap-3">
                        <flux:button type="submit" variant="primary">{{ __('Create Flow') }}</flux:button>
                        <flux:button variant="ghost" wire:click="$toggle('showCreateFlow')">{{ __('Cancel') }}</flux:button>
                    </div>
                </form>
            @endif

            {{-- Flow List --}}
            @if ($this->flows->count() > 0)
                <div class="space-y-3">
                    @foreach ($this->flows as $flow)
                        <div wire:key="flow-{{ $flow->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium">{{ $flow->name }}</span>
                                    @if ($flow->trigger_type === 'keyword' && $flow->trigger_keyword)
                                        <flux:badge size="sm" color="purple">{{ $flow->trigger_keyword }}</flux:badge>
                                    @endif
                                </div>
                                <flux:text class="text-xs text-zinc-500">
                                    {{ ucfirst(str_replace('_', ' ', $flow->trigger_type)) }}
                                    &middot; {{ $flow->conversations_count }} {{ __('conversations') }}
                                    &middot; {{ $flow->steps_count ?? $flow->steps()->count() }} {{ __('steps') }}
                                    @if ($flow->business_hours_only)
                                        &middot; {{ __('Business hours only') }}
                                    @endif
                                </flux:text>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:badge size="sm" :color="match($flow->status) { 'active' => 'green', 'draft' => 'yellow', default => 'zinc' }">
                                    {{ ucfirst($flow->status) }}
                                </flux:badge>
                                <flux:button size="sm" variant="ghost" :href="route('settings.sms-bot.flows.edit', $flow)" wire:navigate icon="pencil-square" />
                                <flux:button size="sm" variant="ghost" wire:click="toggleFlowStatus({{ $flow->id }})" :icon="$flow->status === 'active' ? 'pause' : 'play'" />
                                <flux:button size="sm" variant="ghost" wire:click="deleteFlow({{ $flow->id }})" wire:confirm="{{ __('Are you sure you want to delete this flow?') }}" icon="trash" class="text-red-500 hover:text-red-700" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-lg border border-dashed border-zinc-300 py-8 text-center dark:border-zinc-600">
                    <flux:text class="text-sm text-zinc-500">{{ __('No bot flows created yet. Create one to automate SMS lead engagement.') }}</flux:text>
                </div>
            @endif
        </div>

        <flux:separator />

        {{-- Recent Activity --}}
        <div class="my-6 w-full space-y-6">
            <div>
                <flux:heading size="sm">{{ __('Recent Activity') }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">{{ __('Recent SMS bot conversations.') }}</flux:text>
            </div>

            @if ($this->recentConversations->count() > 0)
                <div class="space-y-2">
                    @foreach ($this->recentConversations as $conversation)
                        <div wire:key="conv-{{ $conversation->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-2">
                                    @if ($conversation->isActive())
                                        <div class="h-2.5 w-2.5 rounded-full bg-green-500"></div>
                                    @elseif ($conversation->isComplete())
                                        <div class="h-2.5 w-2.5 rounded-full bg-blue-500"></div>
                                    @else
                                        <div class="h-2.5 w-2.5 rounded-full bg-zinc-400"></div>
                                    @endif
                                    <flux:text class="text-sm font-medium">{{ $conversation->phone }}</flux:text>
                                </div>
                                <flux:badge size="sm" :color="match($conversation->status) {
                                    'in_progress' => 'yellow',
                                    'completed' => 'blue',
                                    'human_takeover' => 'red',
                                    'abandoned' => 'zinc',
                                    default => 'zinc',
                                }">
                                    {{ ucfirst(str_replace('_', ' ', $conversation->status ?? 'unknown')) }}
                                </flux:badge>
                                @if ($conversation->currentStep)
                                    <flux:badge size="sm" color="zinc">
                                        {{ str_replace('_', ' ', $conversation->currentStep->step_type) }}
                                    </flux:badge>
                                @endif
                            </div>
                            <div class="flex items-center gap-3">
                                <flux:text class="text-xs text-zinc-500">
                                    {{ $conversation->message_count ?? 0 }} {{ __('msgs') }}
                                    &middot; {{ $conversation->photo_count ?? 0 }} {{ __('photos') }}
                                </flux:text>
                                <flux:text class="text-xs text-zinc-400">
                                    {{ $conversation->last_message_at?->diffForHumans() ?? '-' }}
                                </flux:text>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-lg border border-dashed border-zinc-300 py-8 text-center dark:border-zinc-600">
                    <flux:text class="text-sm text-zinc-500">{{ __('No recent bot conversations.') }}</flux:text>
                </div>
            @endif
        </div>

    </x-pages::settings.layout>
</section>
