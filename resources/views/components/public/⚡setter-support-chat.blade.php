<?php

use App\Events\NewThreadNotification;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\GuestChatSession;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Notifications\NewSupportChatNotification;
use App\Services\ChatService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $isOpen = false;
    public string $email = '';
    public string $name = '';
    public string $newMessage = '';
    public ?int $sessionId = null;

    public function mount(): void
    {
        $uuid = request()->cookie('setter_chat_uuid');
        if ($uuid) {
            $session = GuestChatSession::where('uuid', $uuid)
                ->whereIn('status', ['active', 'converted'])
                ->first();
            if ($session) {
                $this->sessionId = $session->id;
            }
        }
    }

    public function startChat(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        // Find or create a User for this setter (type = null, not a customer)
        $setterUser = User::where('email', $this->email)->first();

        if (! $setterUser) {
            $setterUser = new User([
                'name' => $this->name ?: explode('@', $this->email)[0],
                'email' => $this->email,
                'password' => bcrypt(Str::random(32)),
            ]);
            $setterUser->type = null;
            $setterUser->save();
        }

        // Create a support thread with setter_support source
        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SETTER_SUPPORT,
            'subject' => 'Setter Support: ' . ($this->name ?: $this->email),
            'last_message_at' => now(),
        ]);

        // Add setter as participant
        $thread->addParticipant($setterUser);

        // Only add internal users who have permission to view setter support chats
        $internalUsers = User::where('type', 'internal')->get()->filter(function (User $user) {
            if ($user->roles->isEmpty()) {
                return true;
            }

            return $user->can('chat.view_setter_support');
        });

        foreach ($internalUsers as $user) {
            $thread->addParticipant($user);
        }

        // Send a welcome greeting
        $greeting = MessengerSetting::getInstance()->widget_greeting_logged_out;
        if ($greeting) {
            $greetingUser = $internalUsers->first();
            if ($greetingUser) {
                app(ChatService::class)->sendMessage($thread, $greetingUser, $greeting);
            }
        }

        $uuid = Str::uuid()->toString();
        $session = GuestChatSession::create([
            'uuid' => $uuid,
            'email' => $this->email,
            'name' => $this->name ?: null,
            'ip_address' => request()->ip(),
            'city' => null,
            'user_agent' => request()->userAgent(),
            'chat_thread_id' => $thread->id,
            'user_id' => $setterUser->id,
            'status' => 'active',
            'started_at' => now(),
            'metadata' => [
                'page_url' => request()->header('Referer'),
                'referrer' => request()->header('Referer'),
                'source' => 'setter_support',
            ],
        ]);

        $this->sessionId = $session->id;

        cookie()->queue('setter_chat_uuid', $uuid, 60 * 24 * 30);

        try {
            broadcast(new NewThreadNotification($thread));
        } catch (\Throwable) {
        }

        // Only notify internal users with permission
        foreach ($internalUsers as $user) {
            $user->notify(new NewSupportChatNotification($session));
        }
    }

    public function sendMessage(): void
    {
        $this->validate(['newMessage' => ['required', 'string', 'max:5000']]);

        $session = GuestChatSession::find($this->sessionId);
        if (! $session || ! $session->thread) {
            return;
        }

        app(ChatService::class)->sendGuestMessage(
            $session->thread,
            $this->newMessage,
            ['guest_email' => $session->email, 'guest_name' => $session->name, 'source' => 'setter_support'],
        );

        $this->newMessage = '';
    }

    #[Computed]
    public function chatMessages(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->sessionId) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $session = GuestChatSession::find($this->sessionId);
        if (! $session) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return ChatMessage::where('thread_id', $session->chat_thread_id)
            ->where('is_internal', false)
            ->orderBy('created_at', 'asc')
            ->get();
    }
};
?>

<div>
    {{-- Chat toggle button --}}
    @if (! $isOpen)
        <button
            wire:click="$set('isOpen', true)"
            class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:border-emerald-400 dark:hover:border-emerald-500 transition-colors text-left"
        >
            <div class="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900 flex items-center justify-center shrink-0">
                <flux:icon name="chat-bubble-left-right" class="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
            </div>
            <div>
                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('Setter Support') }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Need help? Chat with us') }}</p>
            </div>
        </button>
    @endif

    {{-- Chat panel --}}
    @if ($isOpen)
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            {{-- Header --}}
            <div class="bg-emerald-600 text-white px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <flux:icon name="chat-bubble-left-right" class="w-5 h-5" />
                    <span class="font-semibold text-sm">{{ __('Setter Support') }}</span>
                </div>
                <button wire:click="$set('isOpen', false)" class="text-white/80 hover:text-white">
                    <flux:icon name="x-mark" variant="mini" class="w-5 h-5" />
                </button>
            </div>

            @if (! $sessionId)
                {{-- Email form to start chat --}}
                <form wire:submit="startChat" class="p-4 space-y-3">
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-300">
                        {{ __('Enter your info to start a support chat.') }}
                    </flux:text>
                    <flux:input wire:model="name" :placeholder="__('Your name')" size="sm" />
                    <flux:input wire:model="email" type="email" :placeholder="__('Your email')" size="sm" required />
                    <flux:button type="submit" variant="primary" size="sm" class="w-full">
                        <span wire:loading.remove wire:target="startChat">{{ __('Start Chat') }}</span>
                        <span wire:loading wire:target="startChat">{{ __('Starting...') }}</span>
                    </flux:button>
                </form>
            @else
                {{-- Messages --}}
                <div
                    x-data="{
                        scroll() { this.$el.scrollTop = this.$el.scrollHeight },
                        init() {
                            this.$nextTick(() => this.scroll());
                            new MutationObserver(() => this.scroll()).observe(this.$el, { childList: true, subtree: true });
                        }
                    }"
                    class="h-48 overflow-y-auto p-3 space-y-2"
                    wire:poll.5s.visible="$refresh"
                >
                    @forelse ($this->chatMessages as $message)
                        <div wire:key="msg-{{ $message->id }}" class="flex {{ $message->user_id ? 'justify-start' : 'justify-end' }}">
                            <div class="max-w-[80%] rounded-lg px-3 py-2 text-sm
                                {{ $message->user_id
                                    ? 'bg-zinc-100 dark:bg-zinc-700 text-zinc-900 dark:text-white'
                                    : 'bg-emerald-600 text-white' }}">
                                @if ($message->user_id)
                                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-0.5">{{ $message->user?->name ?? __('Team') }}</p>
                                @endif
                                <p>{{ $message->content }}</p>
                                <p class="text-[10px] mt-1 {{ $message->user_id ? 'text-zinc-400' : 'text-emerald-200' }}">{{ $message->created_at->format('g:i A') }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-sm text-zinc-400 py-4">
                            {{ __('Send a message to get started.') }}
                        </div>
                    @endforelse
                </div>

                {{-- Input --}}
                <form wire:submit="sendMessage" class="border-t border-zinc-200 dark:border-zinc-700 p-3 flex gap-2">
                    <input wire:model="newMessage" type="text" class="flex-1 rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-2 text-sm text-zinc-900 dark:text-white bg-white dark:bg-zinc-800 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('Type a message...') }}" />
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-2 transition-colors shrink-0">
                        <svg wire:loading.remove wire:target="sendMessage" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                        <svg wire:loading wire:target="sendMessage" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </button>
                </form>
                @error('newMessage') <p class="text-xs text-red-500 px-3 pb-2">{{ $message }}</p> @enderror
            @endif
        </div>
    @endif
</div>
