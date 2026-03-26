@props(['items' => []])

<nav aria-label="Breadcrumb" class="py-3">
    <ol class="flex items-center flex-wrap gap-1 text-sm">
        <li>
            <a href="{{ url('/') }}" class="text-zinc-400 hover:text-zinc-600 transition-colors">{{ __('Home') }}</a>
        </li>
        @foreach ($items as $item)
            <li class="flex items-center gap-1">
                <svg class="w-3.5 h-3.5 text-zinc-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                @if (!empty($item['url']) && !$loop->last)
                    <a href="{{ $item['url'] }}" class="text-zinc-400 hover:text-zinc-600 transition-colors">{{ $item['label'] }}</a>
                @else
                    <span class="text-zinc-600 font-medium">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
