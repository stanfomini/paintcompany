@props(['compact' => false])

@php
    $currentLocale = app()->getLocale();
    $otherLocale = $currentLocale === 'es' ? 'en' : 'es';
    $labels = ['en' => 'EN', 'es' => 'ES'];
    $fullLabels = ['en' => 'English', 'es' => 'Español'];
@endphp

@if($compact)
    {{-- Compact version for sidebars --}}
    <a href="{{ route('locale.switch', $otherLocale) }}"
       class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium text-zinc-500 transition hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
       title="{{ $fullLabels[$otherLocale] }}">
        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m10.5 21 5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 0 1 6-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 0 1-3.827-5.802" />
        </svg>
        <span class="uppercase">{{ $labels[$currentLocale] }}</span>
        <svg xmlns="http://www.w3.org/2000/svg" class="size-3 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
        </svg>
        <span class="uppercase">{{ $labels[$otherLocale] }}</span>
    </a>
@else
    {{-- Dropdown version for menus --}}
    <flux:menu.item href="{{ route('locale.switch', $otherLocale) }}" icon="language">
        {{ $fullLabels[$otherLocale] }}
    </flux:menu.item>
@endif
