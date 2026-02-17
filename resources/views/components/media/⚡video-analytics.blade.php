<?php

use App\Models\VideoRecording;
use App\Models\VideoView;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $recordingId;

    #[Computed]
    public function totalViews(): int
    {
        return VideoView::where('video_recording_id', $this->recordingId)->count();
    }

    #[Computed]
    public function uniqueViewers(): int
    {
        return VideoView::where('video_recording_id', $this->recordingId)
            ->distinct()
            ->count('ip_address');
    }

    #[Computed]
    public function averageWatchPercentage(): float
    {
        return round(
            (float) VideoView::where('video_recording_id', $this->recordingId)->avg('watch_percentage') ?? 0,
            1
        );
    }

    #[Computed]
    public function recentViewers()
    {
        return VideoView::where('video_recording_id', $this->recordingId)
            ->with('viewer')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }
};
?>

<div>
    {{-- Stats Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="p-4 bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
            <flux:text class="text-sm text-zinc-500">{{ __('Total Views') }}</flux:text>
            <flux:heading size="xl">{{ $this->totalViews }}</flux:heading>
        </div>
        <div class="p-4 bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
            <flux:text class="text-sm text-zinc-500">{{ __('Unique Viewers') }}</flux:text>
            <flux:heading size="xl">{{ $this->uniqueViewers }}</flux:heading>
        </div>
        <div class="p-4 bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
            <flux:text class="text-sm text-zinc-500">{{ __('Avg. Watch %') }}</flux:text>
            <flux:heading size="xl">{{ $this->averageWatchPercentage }}%</flux:heading>
        </div>
    </div>

    {{-- Recent Viewers Table --}}
    <flux:heading size="lg" class="mb-3">{{ __('Recent Viewers') }}</flux:heading>

    @if($this->recentViewers->isEmpty())
        <flux:text class="text-zinc-500">{{ __('No views yet.') }}</flux:text>
    @else
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Viewer') }}</th>
                        <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Watch %') }}</th>
                        <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Source') }}</th>
                        <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Date') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach($this->recentViewers as $view)
                        <tr wire:key="view-{{ $view->id }}">
                            <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">
                                {{ $view->viewer?->name ?? $view->viewer_email ?? __('Anonymous') }}
                            </td>
                            <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $view->watch_percentage }}%</td>
                            <td class="px-4 py-2">
                                <flux:badge size="sm">{{ ucfirst($view->source) }}</flux:badge>
                            </td>
                            <td class="px-4 py-2 text-zinc-500">{{ $view->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
