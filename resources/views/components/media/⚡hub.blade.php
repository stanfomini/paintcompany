<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Media Hub'])]
class extends Component {
    #[Url]
    public string $tab = 'videos';

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between mb-6">
        <flux:heading size="xl">{{ __('Media Hub') }}</flux:heading>
    </div>

    <div class="flex gap-2 mb-6 border-b border-zinc-200 dark:border-zinc-700 pb-3">
        <flux:button
            size="sm"
            :variant="$tab === 'videos' ? 'filled' : 'ghost'"
            icon="video-camera"
            wire:click="setTab('videos')"
        >
            {{ __('My Videos') }}
        </flux:button>

        @can('media.manage')
            <flux:button
                size="sm"
                :variant="$tab === 'company-videos' ? 'filled' : 'ghost'"
                icon="building-office"
                wire:click="setTab('company-videos')"
            >
                {{ __('All Videos') }}
            </flux:button>
        @endcan

        @can('media.manage_channels')
            <flux:button
                size="sm"
                :variant="$tab === 'channels' ? 'filled' : 'ghost'"
                icon="rectangle-stack"
                wire:click="setTab('channels')"
            >
                {{ __('Channels') }}
            </flux:button>
        @endcan

        @can('media.manage_training')
            <flux:button
                size="sm"
                :variant="$tab === 'training' ? 'filled' : 'ghost'"
                icon="academic-cap"
                wire:click="setTab('training')"
            >
                {{ __('Training') }}
            </flux:button>
        @endcan

        @canany(['media.manage', 'media.view_own_analytics'])
            <flux:button
                size="sm"
                :variant="$tab === 'analytics' ? 'filled' : 'ghost'"
                icon="chart-bar"
                wire:click="setTab('analytics')"
            >
                {{ __('Analytics') }}
            </flux:button>
        @endcanany

        @can('media.manage')
            <flux:button
                size="sm"
                :variant="$tab === 'landing-page' ? 'filled' : 'ghost'"
                icon="globe-alt"
                wire:click="setTab('landing-page')"
            >
                {{ __('Landing Page') }}
            </flux:button>

            <flux:button
                size="sm"
                :variant="$tab === 'showcase' ? 'filled' : 'ghost'"
                icon="photo"
                wire:click="setTab('showcase')"
            >
                {{ __('Showcase') }}
            </flux:button>
        @endcan
    </div>

    @if($tab === 'videos')
        @livewire('media.my-videos')
    @elseif($tab === 'company-videos')
        @livewire('media.company-videos')
    @elseif($tab === 'channels')
        @livewire('media.channel-list')
    @elseif($tab === 'training')
        @livewire('media.training-list')
    @elseif($tab === 'analytics')
        @livewire('media.analytics-overview')
    @elseif($tab === 'landing-page')
        @livewire('media.landing-page-videos')
    @elseif($tab === 'showcase')
        @livewire('media.landing-page-showcase')
    @endif
</div>
