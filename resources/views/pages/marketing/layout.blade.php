<div class="flex items-start max-md:flex-col"
    x-data="{ navOpen: JSON.parse(localStorage.getItem('marketing-nav-open') ?? 'true') }"
    x-effect="localStorage.setItem('marketing-nav-open', JSON.stringify(navOpen))">
    {{-- Nav Toggle (desktop only, visible when collapsed) --}}
    <button x-show="!navOpen" x-cloak @click="navOpen = true" class="me-4 hidden shrink-0 rounded-lg border border-zinc-200 p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:border-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300 md:block" title="{{ __('Show marketing menu') }}">
        <flux:icon name="bars-3" class="size-4" />
    </button>
    <div x-show="navOpen" x-cloak class="me-10 w-full pb-4 md:w-[220px]">
        <div class="mb-2 hidden items-center justify-between md:flex">
            <span class="text-xs font-medium uppercase tracking-wider text-zinc-400">{{ __('Marketing') }}</span>
            <button @click="navOpen = false" class="rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-300" title="{{ __('Collapse menu') }}">
                <flux:icon name="chevron-left" class="size-4" />
            </button>
        </div>
        <flux:navlist aria-label="{{ __('Marketing') }}">
            <flux:navlist.item :href="route('marketing.bot-tracker')" wire:navigate>{{ __('Bot Tracker') }}</flux:navlist.item>
            <flux:navlist.item :href="route('marketing.dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:navlist.item>
            <flux:navlist.item :href="route('marketing.automated')" wire:navigate>{{ __('Automated Campaigns') }}</flux:navlist.item>
            <flux:navlist.item :href="route('marketing.paid-ads')" wire:navigate>{{ __('Paid Advertising') }}</flux:navlist.item>
            <flux:navlist.item :href="route('marketing.new-homeowners')" wire:navigate>{{ __('New Homeowners') }}</flux:navlist.item>
            <flux:navlist.item :href="route('marketing.partnerships')" wire:navigate>{{ __('Partnerships & Offline') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" x-show="navOpen" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-5xl">
            {{ $slot }}
        </div>
    </div>
</div>
