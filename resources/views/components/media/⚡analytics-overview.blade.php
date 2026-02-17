<?php

use App\Models\VideoEmail;
use App\Models\VideoRecording;
use App\Models\VideoView;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function boot(): void
    {
        abort_unless(auth()->user()?->can('media.manage'), 403);
    }

    #[Computed]
    public function totalViews(): int
    {
        return VideoView::count();
    }

    #[Computed]
    public function viewsLast7Days(): int
    {
        return VideoView::where('created_at', '>=', now()->subDays(7))->count();
    }

    #[Computed]
    public function viewsLast30Days(): int
    {
        return VideoView::where('created_at', '>=', now()->subDays(30))->count();
    }

    #[Computed]
    public function averageWatchPercentage(): float
    {
        return round((float) (VideoView::avg('watch_percentage') ?? 0), 1);
    }

    #[Computed]
    public function topVideos()
    {
        return VideoView::query()
            ->select('video_recording_id')
            ->selectRaw('COUNT(*) as views_count')
            ->selectRaw('ROUND(AVG(watch_percentage), 1) as avg_watch')
            ->selectRaw('MAX(created_at) as last_viewed_at')
            ->groupBy('video_recording_id')
            ->orderByDesc('views_count')
            ->limit(5)
            ->with('video:id,title')
            ->get();
    }

    #[Computed]
    public function recentViews()
    {
        return VideoView::query()
            ->with(['video:id,title', 'viewer:id,name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function unwatchedVideos()
    {
        return VideoRecording::query()
            ->where('status', 'ready')
            ->whereDoesntHave('views')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'title', 'share_token', 'created_at']);
    }

    #[Computed]
    public function emailStats(): ?array
    {
        $total = VideoEmail::count();

        if ($total === 0) {
            return null;
        }

        $opened = VideoEmail::whereNotNull('opened_at')->count();
        $clicked = VideoEmail::whereNotNull('clicked_at')->count();

        return [
            'total' => $total,
            'opened' => $opened,
            'clicked' => $clicked,
            'open_rate' => round(($opened / $total) * 100, 1),
            'click_rate' => round(($clicked / $total) * 100, 1),
        ];
    }
};
?>

<div class="space-y-8">
    {{-- Stats Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach([
            ['label' => __('Total Views'), 'value' => $this->totalViews, 'icon' => 'eye'],
            ['label' => __('Last 7 Days'), 'value' => $this->viewsLast7Days, 'icon' => 'calendar'],
            ['label' => __('Last 30 Days'), 'value' => $this->viewsLast30Days, 'icon' => 'chart-bar'],
            ['label' => __('Avg. Watch %'), 'value' => $this->averageWatchPercentage . '%', 'icon' => 'clock'],
        ] as $stat)
            <div class="p-4 bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center gap-2 mb-1">
                    <flux:icon :name="$stat['icon']" variant="mini" class="text-zinc-400" />
                    <flux:text class="text-sm text-zinc-500">{{ $stat['label'] }}</flux:text>
                </div>
                <flux:heading size="xl">{{ $stat['value'] }}</flux:heading>
            </div>
        @endforeach
    </div>

    {{-- Email Stats --}}
    @if($this->emailStats)
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Email Performance') }}</flux:heading>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                @foreach([
                    ['label' => __('Sent'), 'value' => $this->emailStats['total']],
                    ['label' => __('Opened'), 'value' => $this->emailStats['opened']],
                    ['label' => __('Clicked'), 'value' => $this->emailStats['clicked']],
                    ['label' => __('Open Rate'), 'value' => $this->emailStats['open_rate'] . '%'],
                    ['label' => __('Click Rate'), 'value' => $this->emailStats['click_rate'] . '%'],
                ] as $emailStat)
                    <div class="p-3 bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 text-center">
                        <flux:text class="text-xs text-zinc-500">{{ $emailStat['label'] }}</flux:text>
                        <flux:heading size="lg">{{ $emailStat['value'] }}</flux:heading>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Top 5 Most Viewed --}}
    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Top Videos') }}</flux:heading>
        @if($this->topVideos->isEmpty())
            <flux:text class="text-zinc-500">{{ __('No views recorded yet.') }}</flux:text>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Video') }}</th>
                            <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Views') }}</th>
                            <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Avg. Watch %') }}</th>
                            <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Last Viewed') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($this->topVideos as $row)
                            <tr wire:key="top-{{ $row->video_recording_id }}">
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $row->video?->title ?? __('Deleted Video') }}</td>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ number_format($row->views_count) }}</td>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $row->avg_watch }}%</td>
                                <td class="px-4 py-2 text-zinc-500">{{ \Carbon\Carbon::parse($row->last_viewed_at)->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Recent Views --}}
    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Recent Views') }}</flux:heading>
        @if($this->recentViews->isEmpty())
            <flux:text class="text-zinc-500">{{ __('No views recorded yet.') }}</flux:text>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Video') }}</th>
                            <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Viewer') }}</th>
                            <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Watch %') }}</th>
                            <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Source') }}</th>
                            <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('When') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($this->recentViews as $view)
                            <tr wire:key="recent-{{ $view->id }}">
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $view->video?->title ?? __('Deleted Video') }}</td>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $view->viewer?->name ?? $view->viewer_email ?? $view->ip_address ?? __('Anonymous') }}</td>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $view->watch_percentage }}%</td>
                                <td class="px-4 py-2">
                                    @if($view->source)
                                        <flux:badge size="sm">{{ ucfirst($view->source) }}</flux:badge>
                                    @else
                                        <flux:text class="text-zinc-400">{{ __('Direct') }}</flux:text>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-zinc-500">{{ $view->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Unwatched Videos --}}
    @if($this->unwatchedVideos->isNotEmpty())
        <div>
            <div class="flex items-center gap-2 mb-3">
                <flux:heading size="lg">{{ __('Opportunity to Promote') }}</flux:heading>
                <flux:badge size="sm" color="amber">{{ $this->unwatchedVideos->count() }}</flux:badge>
            </div>
            <flux:text class="text-zinc-500 mb-3">{{ __('Ready videos with no views yet.') }}</flux:text>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($this->unwatchedVideos as $video)
                    <div wire:key="unwatched-{{ $video->id }}" class="flex items-center justify-between p-3 bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div>
                            <flux:text class="font-medium text-zinc-700 dark:text-zinc-300">{{ $video->title }}</flux:text>
                            <flux:text class="text-xs text-zinc-500">{{ __('Created') }} {{ $video->created_at->diffForHumans() }}</flux:text>
                        </div>
                        @if($video->share_token)
                            <flux:button size="xs" variant="ghost" icon="link" onclick="navigator.clipboard.writeText('{{ route('video-recording.public', $video->share_token) }}')">
                                {{ __('Copy Link') }}
                            </flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
