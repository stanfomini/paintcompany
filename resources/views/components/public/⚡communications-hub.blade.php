<?php

use App\Models\CompanySetting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.guest')] #[Title('Communications Hub | Every Conversation, One Place')] class extends Component
{
    public function getCompanyProperty(): CompanySetting
    {
        return CompanySetting::getInstance();
    }
};
?>

<div class="min-h-screen bg-zinc-950 text-white overflow-hidden">
    {{-- Hero Section --}}
    <section class="relative min-h-[90vh] flex items-center justify-center overflow-hidden">
        {{-- Animated gradient background --}}
        <div class="absolute inset-0 bg-gradient-to-br from-zinc-950 via-blue-950/30 to-zinc-950"></div>

        {{-- Floating orbs --}}
        <div class="absolute top-20 left-[15%] w-72 h-72 bg-blue-500/10 rounded-full blur-3xl" style="animation: float 8s ease-in-out infinite"></div>
        <div class="absolute bottom-20 right-[20%] w-96 h-96 bg-purple-500/10 rounded-full blur-3xl" style="animation: float 10s ease-in-out infinite reverse"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-sky-500/5 rounded-full blur-3xl"></div>

        <div class="relative z-10 text-center px-6 max-w-5xl mx-auto">
            {{-- Badge --}}
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-blue-500/10 border border-blue-500/20 mb-8">
                <div class="w-2 h-2 rounded-full bg-blue-400" style="animation: pulse-dot 2s ease-in-out infinite"></div>
                <span class="text-sm font-medium text-blue-300">Communications Hub</span>
            </div>

            {{-- Headline --}}
            <h1 class="text-5xl sm:text-7xl lg:text-8xl font-extrabold tracking-tight leading-none mb-6">
                Every Conversation.
                <br>
                <span class="bg-gradient-to-r from-sky-400 via-blue-400 to-emerald-400 bg-clip-text text-transparent">
                    One Place.
                </span>
            </h1>

            <p class="text-lg sm:text-xl text-zinc-400 max-w-2xl mx-auto mb-10 leading-relaxed">
                Chat, email, Facebook Messenger, and deal conversations — all unified in a single, real-time inbox.
                Stop losing customers to scattered communications.
            </p>

            {{-- Channel pills --}}
            <div class="flex flex-wrap items-center justify-center gap-3 mb-12">
                @foreach([
                    ['Direct Chat', 'bg-zinc-500/20 border-zinc-500/30 text-zinc-300', 'bg-zinc-400'],
                    ['Email', 'bg-amber-500/20 border-amber-500/30 text-amber-300', 'bg-amber-400'],
                    ['Messenger', 'bg-blue-500/20 border-blue-500/30 text-blue-300', 'bg-blue-400'],
                    ['Deal Chat', 'bg-purple-500/20 border-purple-500/30 text-purple-300', 'bg-purple-400'],
                ] as [$label, $classes, $dotColor])
                    <div class="flex items-center gap-2 px-4 py-2 rounded-xl border {{ $classes }}">
                        <div class="w-2 h-2 rounded-full {{ $dotColor }}"></div>
                        <span class="text-sm font-semibold">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            {{-- CTA --}}
            <a href="{{ route('login') }}" class="inline-flex items-center gap-2 px-8 py-4 rounded-xl bg-gradient-to-r from-blue-600 to-sky-500 text-white font-bold text-lg shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40 transition-all duration-300 hover:scale-105">
                See It In Action
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
            </a>
        </div>

        {{-- Scroll indicator --}}
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2" style="animation: bounce-slow 2s ease-in-out infinite">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3" /></svg>
        </div>
    </section>

    {{-- The Problem Section --}}
    <section class="relative py-24 px-6">
        <div class="max-w-5xl mx-auto text-center">
            <h2 class="text-4xl sm:text-5xl font-extrabold mb-6">
                Your conversations are <span class="text-red-400">everywhere</span>
            </h2>
            <p class="text-lg text-zinc-400 max-w-2xl mx-auto mb-16">
                Gmail, phone calls, Facebook, texts, voicemails, sticky notes... Sound familiar?
            </p>

            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 max-w-3xl mx-auto">
                @foreach([
                    ['Missed calls', '#EF4444', 'M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z'],
                    ['Lost emails', '#F59E0B', 'M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zM22 6l-10 7L2 6'],
                    ['Scattered chats', '#8B5CF6', 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'],
                    ['No thread history', '#F97316', 'M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2zM14 2v6h6'],
                    ['Context switching', '#EC4899', 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
                    ['Missed follow-ups', '#EF4444', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                ] as [$label, $color, $path])
                    <div class="flex items-center gap-3 p-4 rounded-xl border border-red-500/10 bg-red-500/5">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background: {{ $color }}15; border: 1px solid {{ $color }}30;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="{{ $color }}" stroke-width="2" stroke-linecap="round"><path d="{{ $path }}" /></svg>
                        </div>
                        <span class="text-sm font-semibold text-red-300">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <p class="text-xl text-zinc-400 mt-12">
                The result? <span class="text-red-400 font-semibold">Stress, confusion, and lost deals.</span>
            </p>
        </div>
    </section>

    {{-- The Solution Section --}}
    <section class="relative py-24 px-6 bg-gradient-to-b from-zinc-950 via-blue-950/10 to-zinc-950">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-br from-blue-500 to-sky-400 mb-6 shadow-lg shadow-blue-500/30">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3" fill="currentColor" opacity="0.3" /><path d="M12 2v4M12 18v4M2 12h4M18 12h4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" /></svg>
                </div>
                <h2 class="text-4xl sm:text-5xl font-extrabold mb-4">
                    One Unified <span class="bg-gradient-to-r from-sky-400 to-emerald-400 bg-clip-text text-transparent">Inbox</span>
                </h2>
                <p class="text-lg text-zinc-400 max-w-xl mx-auto">
                    A Gmail-style inbox where every person you've communicated with appears, sorted by latest activity.
                </p>
            </div>

            {{-- Feature mockup cards --}}
            <div class="grid md:grid-cols-2 gap-8 max-w-5xl mx-auto">
                {{-- Left: Contact list --}}
                <div class="rounded-2xl border border-zinc-800 bg-zinc-900/80 overflow-hidden">
                    <div class="p-4 border-b border-zinc-800 flex items-center gap-3">
                        <div class="flex gap-1.5">
                            <div class="w-3 h-3 rounded-full bg-red-500/80"></div>
                            <div class="w-3 h-3 rounded-full bg-amber-500/80"></div>
                            <div class="w-3 h-3 rounded-full bg-emerald-500/80"></div>
                        </div>
                        <div class="flex-1 bg-zinc-800 rounded-md px-3 py-1.5 text-xs text-zinc-500">
                            paintingmadeeasydenver.com/communications
                        </div>
                    </div>
                    <div class="p-4">
                        <div class="bg-zinc-800/50 rounded-lg px-3 py-2 text-sm text-zinc-500 mb-4">Search conversations...</div>
                        <div class="flex gap-2 mb-4">
                            @foreach(['All', 'Customers', 'Agents', 'Crew'] as $i => $tab)
                                <div class="px-3 py-1.5 text-xs font-medium rounded-md {{ $i === 0 ? 'bg-blue-500/15 text-blue-400' : 'text-zinc-500' }}">{{ $tab }}</div>
                            @endforeach
                        </div>
                        <div class="space-y-1">
                            @foreach([
                                ['SM', 'Sarah Martinez', 'When can you start the kitchen?', 'Customer', 'emerald', 3],
                                ['JD', 'John Davis', 'The crew finished the trim today', 'Crew', 'purple', 0],
                                ['LW', 'Lisa Wong', 'I have a referral for you!', 'Agent', 'amber', 1],
                                ['MR', 'Mike Rodriguez', 'Colors confirmed - see attached', 'Customer', 'emerald', 2],
                            ] as $i => [$initials, $name, $preview, $type, $color, $unread])
                                <div class="flex items-center gap-3 p-3 rounded-lg {{ $i === 0 ? 'bg-blue-500/5 border-l-2 border-blue-500' : 'hover:bg-zinc-800/50' }}">
                                    <div class="w-10 h-10 rounded-full bg-{{ $color }}-500/20 text-{{ $color }}-400 flex items-center justify-center text-sm font-bold shrink-0">{{ $initials }}</div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-semibold text-zinc-200 {{ $unread > 0 ? 'font-bold' : '' }}">{{ $name }}</span>
                                            <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-{{ $color }}-500/15 text-{{ $color }}-400">{{ $type }}</span>
                                        </div>
                                        <p class="text-xs text-zinc-500 truncate mt-0.5">{{ $preview }}</p>
                                    </div>
                                    @if($unread > 0)
                                        <div class="w-5 h-5 rounded-full bg-blue-500 flex items-center justify-center text-[10px] font-bold text-white shrink-0">{{ $unread }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Right: Conversation --}}
                <div class="rounded-2xl border border-zinc-800 bg-zinc-900/80 overflow-hidden flex flex-col">
                    <div class="p-4 border-b border-zinc-800 flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-sm font-bold">SM</div>
                        <div>
                            <div class="text-sm font-bold text-zinc-200">Sarah Martinez</div>
                            <div class="flex items-center gap-1.5">
                                <div class="w-1.5 h-1.5 rounded-full bg-emerald-400"></div>
                                <span class="text-[10px] text-emerald-400">Online</span>
                            </div>
                        </div>
                        <div class="ml-auto flex gap-1">
                            @foreach(['All', 'Messenger', 'Deals', 'Emails'] as $i => $tab)
                                <div class="px-2.5 py-1 text-[10px] font-medium rounded {{ $i === 0 ? 'bg-blue-500/15 text-blue-400' : 'text-zinc-500' }}">{{ $tab }}</div>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex-1 p-4 flex flex-col gap-3 justify-end">
                        @foreach([
                            ['When can you start the kitchen repaint?', true, 'messenger', 'blue'],
                            ['We can start next Tuesday. I\'ll send color samples tonight.', false, 'chat', 'zinc'],
                            ['Perfect! I\'ll be home all day.', true, 'messenger', 'blue'],
                        ] as [$text, $isTheirs, $channel, $channelColor])
                            <div class="flex {{ $isTheirs ? 'justify-end' : 'justify-start' }}">
                                <div>
                                    <div class="max-w-xs px-4 py-2.5 {{ $isTheirs ? 'bg-blue-600 text-white rounded-t-2xl rounded-bl-2xl rounded-br-sm' : 'bg-zinc-800 text-zinc-200 rounded-t-2xl rounded-br-2xl rounded-bl-sm' }} text-sm leading-relaxed">
                                        {{ $text }}
                                    </div>
                                    <div class="flex items-center gap-1 mt-1 {{ $isTheirs ? 'justify-end' : 'justify-start' }}">
                                        <div class="w-1.5 h-1.5 rounded-full bg-{{ $channelColor }}-400"></div>
                                        <span class="text-[10px] text-zinc-500">via {{ $channel }}</span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="p-3 border-t border-zinc-800 flex items-center gap-2">
                        <div class="flex-1 bg-zinc-800 rounded-lg px-3 py-2 text-xs text-zinc-500">Type a message...</div>
                        <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="m22 2-7 20-4-9-9-4z" /></svg>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Features Grid --}}
    <section class="relative py-24 px-6">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-4xl sm:text-5xl font-extrabold mb-4">
                    Smart <span class="bg-gradient-to-r from-purple-400 to-pink-400 bg-clip-text text-transparent">Features</span>
                </h2>
                <p class="text-lg text-zinc-400">Intelligence built into every interaction</p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach([
                    ['Real-Time Chat', 'Instant messaging with WebSocket updates, typing indicators, and 30-second polling fallback.', 'from-blue-500/20 to-sky-500/20', 'border-blue-500/20', 'text-blue-400', 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'],
                    ['Email Integration', 'Full email client with send, reply, forward, threading, and per-account HTML signatures.', 'from-amber-500/20 to-orange-500/20', 'border-amber-500/20', 'text-amber-400', 'M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zM22 6l-10 7L2 6'],
                    ['Facebook Messenger', 'Bidirectional sync with automated bot handoff. Reply from the hub, customer gets it on Facebook.', 'from-blue-500/20 to-indigo-500/20', 'border-blue-500/20', 'text-blue-400', 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z'],
                    ['Auto-Translation', 'Messages auto-detected and translated in real-time. English-Spanish bidirectional with Google Translate.', 'from-sky-500/20 to-cyan-500/20', 'border-sky-500/20', 'text-sky-400', 'M5 8l6 6M4 14l6-6 2-3M2 5h12M7 2h1M22 22l-5-10-5 10M14 18h6'],
                    ['CRM Auto-Linking', 'Emails auto-linked to customers, deals, and properties by address matching. Zero manual effort.', 'from-emerald-500/20 to-green-500/20', 'border-emerald-500/20', 'text-emerald-400', 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'],
                    ['Smart Routing', 'Notifications automatically routed to the right person based on deal assignment and role permissions.', 'from-purple-500/20 to-violet-500/20', 'border-purple-500/20', 'text-purple-400', 'M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4'],
                ] as [$title, $description, $gradient, $border, $iconColor, $path])
                    <div class="group rounded-2xl border {{ $border }} bg-gradient-to-br {{ $gradient }} p-6 hover:scale-[1.02] transition-transform duration-300">
                        <div class="w-12 h-12 rounded-xl bg-zinc-900/50 border border-zinc-800 flex items-center justify-center mb-4 {{ $iconColor }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="{{ $path }}" /></svg>
                        </div>
                        <h3 class="text-lg font-bold text-zinc-100 mb-2">{{ $title }}</h3>
                        <p class="text-sm text-zinc-400 leading-relaxed">{{ $description }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Messenger Flow Section --}}
    <section class="relative py-24 px-6 bg-gradient-to-b from-zinc-950 via-blue-950/10 to-zinc-950">
        <div class="max-w-4xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-4xl sm:text-5xl font-extrabold mb-4">Facebook Messenger Flow</h2>
                <p class="text-lg text-zinc-400">From lead to conversation in seconds</p>
            </div>

            <div class="space-y-0">
                @foreach([
                    ['1', 'Customer messages on Facebook', 'Via Messenger', '#2563EB', 'M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.2 5.42 3.15 7.2.16.15.26.36.27.58l.06 1.82c.02.58.63.96 1.16.72l2.03-.9c.17-.08.37-.1.55-.06.9.25 1.85.38 2.78.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2z'],
                    ['2', 'Bot auto-responds instantly', 'FAQ, scheduling, quotes', '#8B5CF6', 'M3 11h18v10a2 2 0 01-2 2H5a2 2 0 01-2-2V11zM8 11V7a4 4 0 018 0v4'],
                    ['3', 'You reply from the Hub', 'Human takeover triggered', '#10B981', 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'],
                    ['4', 'Customer gets reply on Facebook', 'Seamless bidirectional sync', '#38BDF8', 'M22 2l-7 20-4-9-9-4z'],
                ] as $i => [$num, $title, $subtitle, $color, $path])
                    <div class="flex items-start gap-6">
                        <div class="flex flex-col items-center">
                            <div class="w-12 h-12 rounded-full flex items-center justify-center text-white font-extrabold text-lg shrink-0" style="background: {{ $color }}; box-shadow: 0 4px 20px {{ $color }}40;">
                                {{ $num }}
                            </div>
                            @if($i < 3)
                                <div class="w-0.5 h-12 mt-2" style="background: {{ $color }}30;"></div>
                            @endif
                        </div>
                        <div class="flex items-center gap-5 flex-1 p-5 rounded-xl border border-zinc-800 bg-zinc-900/50 mb-4">
                            <div class="w-14 h-14 rounded-xl flex items-center justify-center shrink-0" style="background: {{ $color }}12; border: 1px solid {{ $color }}25;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="{{ $color }}" stroke-width="2" stroke-linecap="round"><path d="{{ $path }}" /></svg>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-zinc-200">{{ $title }}</h3>
                                <p class="text-sm text-zinc-500 mt-1">{{ $subtitle }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-center mt-8">
                <div class="inline-flex items-center gap-3 px-6 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 7a4 4 0 100-8 4 4 0 000 8zM19 8v6M22 11h-6" /></svg>
                    <span class="text-sm font-bold text-emerald-400">Auto-creates CRM leads from Messenger conversations</span>
                </div>
            </div>
        </div>
    </section>

    {{-- Video Section --}}
    <section class="relative py-24 px-6" id="video">
        <div class="max-w-5xl mx-auto text-center">
            <h2 class="text-4xl sm:text-5xl font-extrabold mb-6">
                See It In <span class="bg-gradient-to-r from-sky-400 to-blue-400 bg-clip-text text-transparent">Motion</span>
            </h2>
            <p class="text-lg text-zinc-400 mb-12">
                Watch how everything comes together in our Communications Hub
            </p>

            {{-- Live Remotion Player --}}
            <div class="w-full">
                <div id="comms-hub-player-root" class="w-full aspect-video bg-zinc-900 rounded-2xl overflow-hidden"></div>
                @vite('resources/js/comms-hub-player.tsx')
            </div>
        </div>
    </section>

    {{-- Bottom CTA --}}
    <section class="relative py-24 px-6">
        <div class="max-w-3xl mx-auto text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500 to-sky-400 mb-8 shadow-lg shadow-blue-500/30">
                <span class="text-white font-extrabold text-2xl">A</span>
            </div>

            <h2 class="text-4xl sm:text-5xl font-extrabold mb-6 leading-tight">
                Stop Losing Customers
                <br>
                to Scattered Communications
            </h2>

            <p class="text-lg text-zinc-400 mb-10 max-w-xl mx-auto">
                Every message, email, and conversation in one unified hub. Real-time, intelligent, and effortless.
            </p>

            <a href="{{ route('login') }}" class="inline-flex items-center gap-2 px-8 py-4 rounded-xl bg-gradient-to-r from-blue-600 to-sky-500 text-white font-bold text-lg shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40 transition-all duration-300 hover:scale-105">
                Get Started Today
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
            </a>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="border-t border-zinc-800 py-8 px-6 text-center">
        <span class="text-sm text-zinc-600">{{ $this->getCompanyProperty()->company_name ?? config('app.name') }} Communications Hub</span>
    </footer>

    <style>
        @@keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-20px); } }
        @@keyframes pulse-dot { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        @@keyframes bounce-slow { 0%, 100% { transform: translateX(-50%) translateY(0); } 50% { transform: translateX(-50%) translateY(-10px); } }
    </style>
</div>
