<?php

use App\Models\CompanySetting;
use App\Models\VideoChannel;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.watch', ['title' => 'Video Library'])]
class extends Component {
    #[Computed]
    public function channels()
    {
        return VideoChannel::query()
            ->public()
            ->active()
            ->withCount(['items' => function ($query) {
                $query->published()
                    ->whereHas('video', fn ($q) => $q->where('status', 'ready'));
            }])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function companyName(): string
    {
        return CompanySetting::getInstance()->company_name ?? config('app.name');
    }
};
?>

<div>
    <div class="bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12 text-center">
            <h1 class="text-3xl font-bold text-zinc-900 dark:text-white sm:text-4xl">
                {{ $this->companyName }} {{ __('Video Library') }}
            </h1>
            <p class="mt-3 text-lg text-zinc-600 dark:text-zinc-400">
                {{ __('Browse our video channels') }}
            </p>
        </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
        @if($this->channels->isEmpty())
            <div class="text-center py-12">
                <flux:icon name="film" class="mx-auto size-12 text-zinc-400" />
                <p class="mt-4 text-lg text-zinc-500 dark:text-zinc-400">{{ __('No videos available yet.') }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($this->channels as $channel)
                    <livewire:public.watch-channel-card :channel="$channel" :key="'channel-'.$channel->id" />
                @endforeach
            </div>
        @endif
    </div>
</div>
