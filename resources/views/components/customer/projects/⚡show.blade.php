<?php

use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

new #[Layout('components.layouts.customer', ['title' => 'Project Details'])] class extends Component {
    public Deal $deal;

    public function mount(Deal $deal): void
    {
        // Verify ownership
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        if (! $customer || $deal->customer_id !== $customer->id) {
            throw new NotFoundHttpException('Project not found.');
        }

        $this->deal = $deal;
    }

    #[Computed]
    public function project(): Deal
    {
        return $this->deal;
    }

    #[Computed]
    public function estimates()
    {
        return $this->deal->estimates()
            ->whereIn('status', ['sent', 'accepted', 'rejected'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function activities()
    {
        return $this->deal->activities()
            ->with('user')
            ->latest()
            ->limit(10)
            ->get();
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        <div class="mb-6">
            <flux:button variant="ghost" href="{{ route('customer.projects.index') }}" icon="arrow-left">
                {{ __('Back to Projects') }}
            </flux:button>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <flux:heading size="xl">{{ $this->project->title }}</flux:heading>
                @if ($this->project->property)
                    <flux:text class="mt-1 text-zinc-500">{{ $this->project->property->full_address }}</flux:text>
                @endif
            </div>
            <div>
                @php
                    $stageColor = match($this->project->stage) {
                        'lead' => 'zinc',
                        'quoted' => 'yellow',
                        'scheduled' => 'blue',
                        'in_progress' => 'purple',
                        'completed' => 'green',
                        'paid' => 'green',
                        'cancelled' => 'red',
                        default => 'zinc',
                    };
                @endphp
                <flux:badge size="lg" color="{{ $stageColor }}">
                    {{ $this->project->stage_label }}
                </flux:badge>
            </div>
        </div>

        <!-- Project Info Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Estimated Value') }}</flux:text>
                <flux:text class="text-lg font-semibold">
                    @if ($this->project->estimated_value)
                        ${{ number_format($this->project->estimated_value, 2) }}
                    @else
                        {{ __('Not set') }}
                    @endif
                </flux:text>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Expected Start') }}</flux:text>
                <flux:text class="text-lg font-semibold">
                    @if ($this->project->expected_start_date)
                        {{ $this->project->expected_start_date->format('M j, Y') }}
                    @else
                        {{ __('Not scheduled') }}
                    @endif
                </flux:text>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Expected Completion') }}</flux:text>
                <flux:text class="text-lg font-semibold">
                    @if ($this->project->expected_end_date)
                        {{ $this->project->expected_end_date->format('M j, Y') }}
                    @else
                        {{ __('Not set') }}
                    @endif
                </flux:text>
            </div>
        </div>

        @if ($this->project->description)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <flux:heading size="lg" class="mb-4">{{ __('Project Description') }}</flux:heading>
                <flux:text class="whitespace-pre-wrap">{{ $this->project->description }}</flux:text>
            </div>
        @endif

        <!-- Property Information -->
        @if ($this->project->property)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <flux:heading size="lg">{{ __('Property') }}</flux:heading>
                    <flux:button variant="ghost" size="sm" href="{{ route('customer.properties.show', $this->project->property) }}" icon="arrow-right">
                        {{ __('View Details') }}
                    </flux:button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <flux:text class="text-zinc-500 text-sm">{{ __('Address') }}</flux:text>
                        <flux:text class="font-medium">{{ $this->project->property->full_address }}</flux:text>
                    </div>
                    <div>
                        <flux:text class="text-zinc-500 text-sm">{{ __('Type') }}</flux:text>
                        <flux:text class="font-medium capitalize">{{ $this->project->property->type ?? __('Not specified') }}</flux:text>
                    </div>
                    @if ($this->project->property->square_footage)
                        <div>
                            <flux:text class="text-zinc-500 text-sm">{{ __('Square Footage') }}</flux:text>
                            <flux:text class="font-medium">{{ number_format($this->project->property->square_footage) }} sq ft</flux:text>
                        </div>
                    @endif
                    @if ($this->project->property->year_built)
                        <div>
                            <flux:text class="text-zinc-500 text-sm">{{ __('Year Built') }}</flux:text>
                            <flux:text class="font-medium">{{ $this->project->property->year_built }}</flux:text>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <!-- Estimates Section -->
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">{{ __('Estimates') }}</flux:heading>
            </div>
            @if ($this->estimates->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($this->estimates as $estimate)
                        <a wire:key="estimate-{{ $estimate->id }}" href="{{ route('customer.estimates.show', $estimate) }}" class="block">
                            <div class="flex items-center justify-between p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                                <div class="flex items-center gap-4">
                                    <div class="flex-shrink-0">
                                        @php
                                            $statusColor = match($estimate->status) {
                                                'sent' => 'yellow',
                                                'accepted' => 'green',
                                                'rejected' => 'red',
                                                default => 'zinc',
                                            };
                                        @endphp
                                        <flux:badge size="sm" color="{{ $statusColor }}">
                                            {{ $estimate->status_label }}
                                        </flux:badge>
                                    </div>
                                    <div>
                                        <flux:text class="font-medium">{{ $estimate->estimate_number }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $estimate->created_at->format('M j, Y') }}</flux:text>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <flux:text class="font-semibold">${{ number_format($estimate->total, 2) }}</flux:text>
                                    @if ($estimate->status === 'sent')
                                        <flux:text class="text-sm text-yellow-600 dark:text-yellow-400">{{ __('Action Required') }}</flux:text>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:text class="mt-4 text-zinc-500">{{ __('No estimates available yet.') }}</flux:text>
                </div>
            @endif
        </div>

        <!-- Activity Timeline -->
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <flux:heading size="lg" class="mb-4">{{ __('Activity Timeline') }}</flux:heading>
            @if ($this->activities->isNotEmpty())
                <div class="space-y-4">
                    @foreach ($this->activities as $activity)
                        <div wire:key="activity-{{ $activity->id }}" class="flex gap-4">
                            <div class="flex-shrink-0">
                                @php
                                    $activityIcon = match($activity->type) {
                                        'call' => 'phone',
                                        'email' => 'envelope',
                                        'meeting' => 'calendar',
                                        'note' => 'chat-bubble-left',
                                        'task' => 'check-circle',
                                        default => 'document',
                                    };
                                    $activityBgColor = match($activity->type) {
                                        'call' => 'bg-blue-100 dark:bg-blue-900',
                                        'email' => 'bg-purple-100 dark:bg-purple-900',
                                        'meeting' => 'bg-green-100 dark:bg-green-900',
                                        'note' => 'bg-yellow-100 dark:bg-yellow-900',
                                        'task' => 'bg-orange-100 dark:bg-orange-900',
                                        default => 'bg-zinc-100 dark:bg-zinc-800',
                                    };
                                    $activityIconColor = match($activity->type) {
                                        'call' => 'text-blue-600 dark:text-blue-400',
                                        'email' => 'text-purple-600 dark:text-purple-400',
                                        'meeting' => 'text-green-600 dark:text-green-400',
                                        'note' => 'text-yellow-600 dark:text-yellow-400',
                                        'task' => 'text-orange-600 dark:text-orange-400',
                                        default => 'text-zinc-600 dark:text-zinc-400',
                                    };
                                @endphp
                                <div class="rounded-lg {{ $activityBgColor }} p-2">
                                    <flux:icon name="{{ $activityIcon }}" class="h-5 w-5 {{ $activityIconColor }}" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <flux:text class="font-medium">{{ $activity->title }}</flux:text>
                                    <flux:badge size="sm" color="zinc">{{ $activity->type_label }}</flux:badge>
                                </div>
                                @if ($activity->description)
                                    <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $activity->description }}</flux:text>
                                @endif
                                <flux:text class="mt-1 text-xs text-zinc-500">
                                    {{ $activity->created_at->format('M j, Y g:i A') }}
                                    @if ($activity->user)
                                        &middot; {{ $activity->user->name }}
                                    @endif
                                </flux:text>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <flux:icon name="clock" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:text class="mt-4 text-zinc-500">{{ __('No activity recorded yet.') }}</flux:text>
                </div>
            @endif
        </div>

        <!-- Created/Updated Info -->
        <div class="mt-6 text-sm text-zinc-500">
            <flux:text>{{ __('Created') }}: {{ $this->project->created_at->format('M j, Y g:i A') }}</flux:text>
            @if ($this->project->updated_at->ne($this->project->created_at))
                <flux:text class="ml-4">{{ __('Updated') }}: {{ $this->project->updated_at->format('M j, Y g:i A') }}</flux:text>
            @endif
        </div>
    </div>
</div>
