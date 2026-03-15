<?php

use App\Models\CompanySetting;
use App\Models\SmsBotFlow;
use App\Models\SmsConversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $botEnabled = false;
    public array $defaultColors = [];

    // Flow management
    public bool $showCreateFlow = false;
    public string $newFlowName = '';
    public string $newFlowTrigger = 'new_lead';
    public string $newFlowGreeting = '';
    public string $newFlowPhotoPrompt = '';
    public string $newFlowVisualization = '';
    public string $newFlowPortalLink = '';
    public int $newFlowTimeout = 10080;
    public int $newFlowDelay = 5;

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
        $this->defaultColors = $settings->getDefaultVisualizationColors();
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
        return SmsConversation::with('preview')
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
        $this->validate([
            'newFlowName' => 'required|string|max:255',
            'newFlowGreeting' => 'required|string',
            'newFlowPhotoPrompt' => 'required|string',
            'newFlowVisualization' => 'required|string',
            'newFlowPortalLink' => 'required|string',
        ]);

        SmsBotFlow::create([
            'name' => $this->newFlowName,
            'status' => 'draft',
            'trigger_type' => $this->newFlowTrigger,
            'greeting_message' => $this->newFlowGreeting,
            'photo_prompt_message' => $this->newFlowPhotoPrompt,
            'visualization_message' => $this->newFlowVisualization,
            'portal_link_message' => $this->newFlowPortalLink,
            'timeout_minutes' => $this->newFlowTimeout,
            'delay_after_lead_minutes' => $this->newFlowDelay,
        ]);

        $this->reset('showCreateFlow', 'newFlowName', 'newFlowGreeting', 'newFlowPhotoPrompt', 'newFlowVisualization', 'newFlowPortalLink');
        unset($this->flows);
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

    public function saveColors(): void
    {
        $this->validate([
            'defaultColors' => 'array',
            'defaultColors.*.name' => 'required|string|max:255',
            'defaultColors.*.hex' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'defaultColors.*.brand' => 'nullable|string|max:255',
        ]);

        CompanySetting::getInstance()->update([
            'default_visualization_colors' => $this->defaultColors,
        ]);

        $this->dispatch('sms-bot-settings-saved');
    }

    public function addColor(): void
    {
        $this->defaultColors[] = ['name' => '', 'hex' => '#FFFFFF', 'brand' => ''];
    }

    public function removeColor(int $index): void
    {
        unset($this->defaultColors[$index]);
        $this->defaultColors = array_values($this->defaultColors);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('SMS Bot Settings')" :subheading="__('Configure the SMS bot system, flows, and default visualization colors')">

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

        {{-- Default Visualization Colors --}}
        <div class="my-6 w-full space-y-6">
            <div>
                <flux:heading size="sm">{{ __('Default Visualization Colors') }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">
                    {{ __('The default color options shown to leads when they receive a wall color visualization.') }}
                </flux:text>
            </div>

            @if (count($defaultColors) > 0)
                <div class="space-y-3">
                    @foreach ($defaultColors as $index => $color)
                        <div wire:key="color-{{ $index }}" class="flex items-end gap-3">
                            <div class="flex-1">
                                <flux:input wire:model="defaultColors.{{ $index }}.name" :label="$index === 0 ? __('Color Name') : ''" placeholder="Agreeable Gray" />
                            </div>
                            <div class="w-24">
                                <flux:input wire:model="defaultColors.{{ $index }}.hex" :label="$index === 0 ? __('Hex') : ''" type="color" />
                            </div>
                            <div class="flex-1">
                                <flux:input wire:model="defaultColors.{{ $index }}.brand" :label="$index === 0 ? __('Brand') : ''" placeholder="Sherwin-Williams" />
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="removeColor({{ $index }})" icon="x-mark" class="text-red-500 hover:text-red-700" />
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-lg border border-dashed border-zinc-300 py-8 text-center dark:border-zinc-600">
                    <flux:text class="text-sm text-zinc-500">{{ __('No default colors configured. Add colors to offer leads.') }}</flux:text>
                </div>
            @endif

            <div class="flex items-center gap-4">
                <flux:button size="sm" variant="ghost" wire:click="addColor" icon="plus">
                    {{ __('Add Color') }}
                </flux:button>
            </div>

            <div class="flex items-center gap-4">
                <flux:button wire:click="saveColors" variant="primary">{{ __('Save Colors') }}</flux:button>
                <x-action-message class="me-3" on="sms-bot-settings-saved">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
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

                    <flux:select wire:model="newFlowTrigger" :label="__('Trigger Type')">
                        <flux:select.option value="new_lead">{{ __('New Lead') }}</flux:select.option>
                        <flux:select.option value="ad_lead">{{ __('Ad Lead') }}</flux:select.option>
                        <flux:select.option value="booking_lead">{{ __('Booking Lead') }}</flux:select.option>
                    </flux:select>

                    <flux:textarea wire:model="newFlowGreeting" :label="__('Greeting Message')" rows="2" :placeholder="__('Hi {name}! Thanks for reaching out...')" />

                    <flux:textarea wire:model="newFlowPhotoPrompt" :label="__('Photo Prompt Message')" rows="2" :placeholder="__('Could you send a photo of the area you want painted?')" />

                    <flux:textarea wire:model="newFlowVisualization" :label="__('Visualization Message')" rows="2" :placeholder="__('Here is a preview of your room with new colors!')" />

                    <flux:textarea wire:model="newFlowPortalLink" :label="__('Portal Link Message')" rows="2" :placeholder="__('View your full project details here: {link}')" />

                    <div class="flex items-center gap-3">
                        <div class="flex-1">
                            <flux:input wire:model.number="newFlowTimeout" :label="__('Timeout (minutes)')" type="number" min="1" />
                        </div>
                        <div class="flex-1">
                            <flux:input wire:model.number="newFlowDelay" :label="__('Delay After Lead (minutes)')" type="number" min="0" />
                        </div>
                    </div>

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
                                <div class="text-sm font-medium">{{ $flow->name }}</div>
                                <flux:text class="text-xs text-zinc-500">
                                    {{ ucfirst(str_replace('_', ' ', $flow->trigger_type)) }}
                                    &middot; {{ $flow->conversations_count }} {{ __('conversations') }}
                                    @if ($flow->business_hours_only)
                                        &middot; {{ __('Business hours only') }}
                                    @endif
                                </flux:text>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:badge size="sm" :color="match($flow->status) { 'active' => 'green', 'draft' => 'yellow', default => 'zinc' }">
                                    {{ ucfirst($flow->status) }}
                                </flux:badge>
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
                                <flux:badge size="sm" :color="match($conversation->current_state) {
                                    'initiated' => 'yellow',
                                    'photo_received', 'visualization_ready' => 'blue',
                                    'human_takeover' => 'red',
                                    'dormant' => 'zinc',
                                    default => 'zinc',
                                }">
                                    {{ ucfirst(str_replace('_', ' ', $conversation->current_state)) }}
                                </flux:badge>
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
