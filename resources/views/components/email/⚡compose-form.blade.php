<?php

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\EmailService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public string $mode = 'new'; // new, reply, reply-all, forward

    public ?int $replyToId = null;

    public ?int $accountId = null;

    #[Validate('required|string')]
    public string $to = '';

    public string $cc = '';

    public string $bcc = '';

    #[Validate('required|string|max:255')]
    public string $subject = '';

    public string $body = '';

    public array $uploadedAttachments = [];

    public bool $showCc = false;

    public bool $showBcc = false;

    public ?int $draftId = null;

    public function mount(string $mode = 'new', ?int $replyToId = null, ?int $accountId = null): void
    {
        $this->mode = $mode;
        $this->replyToId = $replyToId;
        $this->accountId = $accountId;

        if ($replyToId) {
            $this->prefillFromReply();
        }
    }

    #[Computed]
    public function account()
    {
        if ($this->accountId) {
            $account = EmailAccount::find($this->accountId);
            if ($account) {
                $user = auth()->user();

                if ($account->isPersonal() && $account->user_id === $user->id) {
                    return $account;
                }

                if ($account->isShared() && $account->members()->where('users.id', $user->id)->wherePivot('can_send', true)->exists()) {
                    return $account;
                }
            }
        }

        return auth()->user()->emailAccount;
    }

    #[Computed]
    public function availableAccounts()
    {
        $user = auth()->user();
        $accounts = collect();

        $personal = $user->emailAccount;
        if ($personal) {
            $accounts->push($personal);
        }

        $shared = $user->sharedEmailAccounts()
            ->active()
            ->wherePivot('can_send', true)
            ->get();

        return $accounts->merge($shared);
    }

    public function updatedAccountId(): void
    {
        unset($this->account);
    }

    #[Computed]
    public function replyToMessage(): ?EmailMessage
    {
        return $this->replyToId ? EmailMessage::find($this->replyToId) : null;
    }

    private function prefillFromReply(): void
    {
        $message = $this->replyToMessage;
        if (! $message) {
            return;
        }

        if ($this->mode === 'reply') {
            $this->to = $message->from_address;
            $this->subject = str_starts_with(strtolower($message->subject), 're:')
                ? $message->subject
                : 'Re: '.$message->subject;
        } elseif ($this->mode === 'reply-all') {
            $this->to = $message->from_address;
            $myAddress = $this->account?->full_address;
            $ccAddresses = collect($message->to_addresses)
                ->pluck('address')
                ->merge(collect($message->cc_addresses ?? [])->pluck('address'))
                ->filter(fn ($addr) => $addr !== $myAddress)
                ->unique()
                ->implode(', ');
            if ($ccAddresses) {
                $this->cc = $ccAddresses;
                $this->showCc = true;
            }
            $this->subject = str_starts_with(strtolower($message->subject), 're:')
                ? $message->subject
                : 'Re: '.$message->subject;
        } elseif ($this->mode === 'forward') {
            $this->subject = str_starts_with(strtolower($message->subject), 'fwd:')
                ? $message->subject
                : 'Fwd: '.$message->subject;
            $this->body = '<br><br>---------- Forwarded message ----------<br>'
                .'From: '.e($message->from_name ?? $message->from_address).'<br>'
                .'Date: '.($message->sent_at?->format('M j, Y g:i A') ?? '').'<br>'
                .'Subject: '.e($message->subject).'<br>'
                .'To: '.e(collect($message->to_addresses)->pluck('address')->implode(', ')).'<br><br>'
                .$message->body_html;
        }

        // Quote original message for replies
        if (in_array($this->mode, ['reply', 'reply-all'])) {
            $date = $message->sent_at?->format('M j, Y \a\t g:i A') ?? '';
            $from = $message->from_name ?? $message->from_address;
            $this->body = '<br><br><div class="gmail_quote">'
                .'<p>On '.$date.', '.$from.' wrote:</p>'
                .'<blockquote style="border-left: 2px solid #ccc; padding-left: 12px; color: #666;">'
                .$message->body_html
                .'</blockquote></div>';
        }

        // Append signature (convert newlines to <br> for plain-text signatures)
        if ($this->account?->signature_html) {
            $sig = $this->account->signature_html;
            // If the signature has no HTML tags, treat it as plain text and convert newlines
            if (strip_tags($sig) === $sig) {
                $sig = nl2br(e($sig));
            }
            $this->body = '<br><br><div class="signature">--<br>'.$sig.'</div>'.$this->body;
        }
    }

    /**
     * Parse comma-separated addresses into structured array.
     * @return array<int, array{address: string, name: string|null}>
     */
    private function parseRecipients(string $input): array
    {
        if (! trim($input)) {
            return [];
        }

        return collect(explode(',', $input))
            ->map(fn ($addr) => trim($addr))
            ->filter()
            ->map(fn ($addr) => ['address' => $addr, 'name' => null])
            ->values()
            ->toArray();
    }

    public function send(): void
    {
        $this->validate();

        $account = $this->account;
        if (! $account) {
            return;
        }

        $emailService = app(EmailService::class);

        $toAddresses = $this->parseRecipients($this->to);
        $ccAddresses = $this->cc ? $this->parseRecipients($this->cc) : null;
        $bccAddresses = $this->bcc ? $this->parseRecipients($this->bcc) : null;

        $replyTo = $this->replyToMessage;

        // If sending an existing draft
        if ($this->draftId) {
            $draft = EmailMessage::find($this->draftId);
            if ($draft) {
                $draft->update([
                    'to_addresses' => $toAddresses,
                    'cc_addresses' => $ccAddresses,
                    'bcc_addresses' => $bccAddresses,
                    'subject' => $this->subject,
                    'body_html' => $this->body,
                    'body_text' => strip_tags($this->body),
                ]);
                $emailService->sendDraft($draft, $this->uploadedAttachments);
                $this->dispatch('email-sent');

                return;
            }
        }

        $emailService->send(
            account: $account,
            to: $toAddresses,
            subject: $this->subject,
            bodyHtml: $this->body,
            cc: $ccAddresses,
            bcc: $bccAddresses,
            replyTo: $replyTo,
            uploadedFiles: $this->uploadedAttachments,
        );

        $this->dispatch('email-sent');
    }

    public function saveDraft(): void
    {
        $account = $this->account;
        if (! $account) {
            return;
        }

        $emailService = app(EmailService::class);

        $existingDraft = $this->draftId ? EmailMessage::find($this->draftId) : null;

        $draft = $emailService->saveDraft(
            account: $account,
            existingDraft: $existingDraft,
            to: $this->parseRecipients($this->to),
            subject: $this->subject,
            bodyHtml: $this->body,
            cc: $this->cc ? $this->parseRecipients($this->cc) : null,
            bcc: $this->bcc ? $this->parseRecipients($this->bcc) : null,
            replyTo: $this->replyToMessage,
        );

        $this->draftId = $draft->id;
        $this->dispatch('email-drafted');
    }

    public function discard(): void
    {
        if ($this->draftId) {
            $draft = EmailMessage::find($this->draftId);
            if ($draft) {
                app(EmailService::class)->permanentDelete($draft);
            }
        }

        $this->dispatch('email-sent'); // reuse to close compose
    }

    public function removeAttachment(int $index): void
    {
        unset($this->uploadedAttachments[$index]);
        $this->uploadedAttachments = array_values($this->uploadedAttachments);
    }
};
?>

<div class="flex h-full flex-col">
    {{-- Compose Header --}}
    <div class="flex items-center justify-between border-b border-zinc-200 px-3 py-2.5 dark:border-zinc-700 sm:px-4 sm:py-3">
        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white sm:text-base">
            @if ($mode === 'reply') {{ __('Reply') }}
            @elseif ($mode === 'reply-all') {{ __('Reply All') }}
            @elseif ($mode === 'forward') {{ __('Forward') }}
            @else {{ __('New Email') }}
            @endif
        </h3>
        <div class="flex items-center gap-1 sm:gap-2">
            <flux:button size="xs" variant="primary" icon="paper-airplane" wire:click="send" wire:loading.attr="disabled" class="sm:hidden">
                <span wire:loading.remove wire:target="send">{{ __('Send') }}</span>
                <span wire:loading wire:target="send">{{ __('Sending...') }}</span>
            </flux:button>
            <flux:button size="xs" wire:click="saveDraft" wire:loading.attr="disabled">{{ __('Draft') }}</flux:button>
            <flux:button size="xs" wire:click="discard" variant="ghost">{{ __('Discard') }}</flux:button>
        </div>
    </div>

    {{-- Recipients --}}
    <div class="space-y-2 border-b border-zinc-200 px-3 py-2.5 dark:border-zinc-700 sm:px-4 sm:py-3">
        @if ($this->availableAccounts->count() > 1)
            <div class="flex items-center gap-2">
                <label class="w-8 shrink-0 text-xs text-zinc-500 sm:w-10 sm:text-sm">{{ __('From') }}</label>
                <div class="min-w-0 flex-1">
                    <flux:select wire:model.live="accountId" size="sm">
                        @foreach ($this->availableAccounts as $acc)
                            <option value="{{ $acc->id }}">
                                {{ $acc->isShared() ? '(' . __('Shared') . ') ' : '' }}{{ $acc->display_name ?? $acc->address }} &lt;{{ $acc->full_address }}&gt;
                            </option>
                        @endforeach
                    </flux:select>
                </div>
            </div>
        @endif

        <div class="flex items-center gap-2">
            <label class="w-8 shrink-0 text-xs text-zinc-500 sm:w-10 sm:text-sm">{{ __('To') }}</label>
            <div class="min-w-0 flex-1">
                <flux:input wire:model="to" size="sm" placeholder="recipient@example.com" />
            </div>
            @if (! $showCc || ! $showBcc)
                <div class="flex shrink-0 gap-1">
                    @if (! $showCc)
                        <button wire:click="$set('showCc', true)" class="text-xs text-blue-600 hover:underline dark:text-blue-400">CC</button>
                    @endif
                    @if (! $showBcc)
                        <button wire:click="$set('showBcc', true)" class="text-xs text-blue-600 hover:underline dark:text-blue-400">BCC</button>
                    @endif
                </div>
            @endif
        </div>

        @if ($showCc)
            <div class="flex items-center gap-2">
                <label class="w-8 shrink-0 text-xs text-zinc-500 sm:w-10 sm:text-sm">{{ __('CC') }}</label>
                <div class="min-w-0 flex-1">
                    <flux:input wire:model="cc" size="sm" placeholder="cc@example.com" />
                </div>
            </div>
        @endif

        @if ($showBcc)
            <div class="flex items-center gap-2">
                <label class="w-8 shrink-0 text-xs text-zinc-500 sm:w-10 sm:text-sm">{{ __('BCC') }}</label>
                <div class="min-w-0 flex-1">
                    <flux:input wire:model="bcc" size="sm" placeholder="bcc@example.com" />
                </div>
            </div>
        @endif

        <div class="flex items-center gap-2">
            <label class="w-8 shrink-0 text-xs text-zinc-500 sm:w-10 sm:text-sm">{{ __('Subj') }}</label>
            <div class="min-w-0 flex-1">
                <flux:input wire:model="subject" size="sm" placeholder="{{ __('Subject') }}" />
            </div>
        </div>
    </div>

    {{-- Rich Text Editor --}}
    <div class="min-h-0 flex-1 border-b border-zinc-200 dark:border-zinc-700"
        x-data="{
            content: $wire.entangle('body'),
            doc: null,
            updateIframe() {
                const iframe = this.$refs.emailFrame;
                if (!iframe) return;
                this.doc = iframe.contentDocument || iframe.contentWindow.document;
                this.doc.open();
                this.doc.write(`<!DOCTYPE html><html><head><style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; line-height: 1.6; color: #333; margin: 0; padding: 12px; word-wrap: break-word; overflow-wrap: break-word; min-height: 150px; }
                    body:empty:before { content: '{{ __('Write your email...') }}'; color: #999; }
                    h1 { font-size: 24px; font-weight: 700; margin: 8px 0; }
                    h2 { font-size: 20px; font-weight: 600; margin: 6px 0; }
                    h3 { font-size: 16px; font-weight: 600; margin: 4px 0; }
                    img { max-width: 100%; height: auto; }
                    table { max-width: 100%; }
                    blockquote { border-left: 2px solid #ccc; padding-left: 12px; margin-left: 0; color: #666; }
                    a { color: #2563eb; }
                    hr { border: none; border-top: 1px solid #ddd; margin: 12px 0; }
                </style></head><body contenteditable='true'>` + (this.content || '') + `</body></html>`);
                this.doc.close();
                this.doc.body.addEventListener('input', () => {
                    this.content = this.doc.body.innerHTML;
                });
            },
            execCmd(cmd, value = null) {
                if (!this.doc) return;
                this.doc.body.focus();
                this.doc.execCommand(cmd, false, value);
                this.content = this.doc.body.innerHTML;
            },
            insertLink() {
                const url = prompt('Enter URL');
                if (url) this.execCmd('createLink', url);
            },
            insertImage() {
                const url = prompt('Enter image URL');
                if (url) this.execCmd('insertImage', url);
            },
            init() {
                this.$nextTick(() => this.updateIframe());
                this.$watch('content', (value) => {
                    if (!this.doc || !this.doc.body) return;
                    if (this.doc.body.innerHTML !== value) {
                        this.updateIframe();
                    }
                });
            }
        }">
        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center gap-0.5 border-b border-zinc-100 px-2 py-1 dark:border-zinc-800 sm:gap-1 sm:px-3 sm:py-1.5">
            {{-- Heading dropdown --}}
            <select @change="if ($event.target.value === 'p') { execCmd('formatBlock', 'p'); } else { execCmd('formatBlock', $event.target.value); } $event.target.value = '';"
                class="h-7 rounded border-none bg-transparent px-1 py-0 text-xs text-zinc-600 hover:bg-zinc-100 focus:ring-0 dark:text-zinc-400 dark:hover:bg-zinc-800">
                <option value="" disabled selected>{{ __('Style') }}</option>
                <option value="p">{{ __('Normal') }}</option>
                <option value="h1">{{ __('Heading 1') }}</option>
                <option value="h2">{{ __('Heading 2') }}</option>
                <option value="h3">{{ __('Heading 3') }}</option>
            </select>
            <div class="mx-1 h-5 w-px bg-zinc-200 dark:bg-zinc-700"></div>
            <button type="button" @click="execCmd('bold')"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Bold">
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/></svg>
            </button>
            <button type="button" @click="execCmd('italic')"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Italic">
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="19" y1="4" x2="10" y2="4" stroke-width="2"/><line x1="14" y1="20" x2="5" y2="20" stroke-width="2"/><line x1="15" y1="4" x2="9" y2="20" stroke-width="2"/></svg>
            </button>
            <button type="button" @click="execCmd('underline')"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Underline">
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 3v7a6 6 0 0 0 6 6 6 6 0 0 0 6-6V3" stroke-width="2"/><line x1="4" y1="21" x2="20" y2="21" stroke-width="2"/></svg>
            </button>
            <button type="button" @click="execCmd('strikeThrough')"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Strikethrough">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="4" y1="12" x2="20" y2="12"/><path d="M17.5 7.5c0-2-2-3.5-5.5-3.5S6.5 5.5 6.5 7.5c0 1.5 1 2.5 3 3.5"/><path d="M6.5 16.5c0 2 2 3.5 5.5 3.5s5.5-1.5 5.5-3.5c0-1.5-1-2.5-3-3.5"/></svg>
            </button>
            <div class="mx-1 h-5 w-px bg-zinc-200 dark:bg-zinc-700"></div>
            {{-- Text color --}}
            <label class="relative rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 cursor-pointer" title="Text Color">
                <flux:icon name="paint-brush" class="size-4" />
                <input type="color" value="#000000" @input="execCmd('foreColor', $event.target.value)"
                    class="absolute inset-0 h-full w-full cursor-pointer opacity-0" />
            </label>
            <div class="mx-1 h-5 w-px bg-zinc-200 dark:bg-zinc-700"></div>
            <button type="button" @click="execCmd('insertUnorderedList')"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Bullet List">
                <flux:icon name="list-bullet" class="size-4" />
            </button>
            <button type="button" @click="execCmd('insertOrderedList')"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Numbered List">
                <flux:icon name="queue-list" class="size-4" />
            </button>
            <button type="button" @click="execCmd('insertHorizontalRule')"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Horizontal Rule">
                <flux:icon name="minus" class="size-4" />
            </button>
            <div class="mx-1 h-5 w-px bg-zinc-200 dark:bg-zinc-700"></div>
            <button type="button" @click="insertLink()"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Link">
                <flux:icon name="link" class="size-4" />
            </button>
            <button type="button" @click="insertImage()"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Image">
                <flux:icon name="photo" class="size-4" />
            </button>
            <div class="mx-1 h-5 w-px bg-zinc-200 dark:bg-zinc-700"></div>
            <button type="button" @click="execCmd('removeFormat')"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Clear Formatting">
                <flux:icon name="x-circle" class="size-4" />
            </button>
            <button type="button" @click="execCmd('undo')"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Undo">
                <flux:icon name="arrow-uturn-left" class="size-4" />
            </button>
            <button type="button" @click="execCmd('redo')"
                class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" title="Redo">
                <flux:icon name="arrow-uturn-right" class="size-4" />
            </button>
        </div>

        {{-- Editor Area (contenteditable iframe) --}}
        <iframe x-ref="emailFrame" class="w-full flex-1 bg-white" style="min-height: 200px; height: 100%; border: none;"></iframe>
    </div>

    {{-- Attachments --}}
    @if (count($uploadedAttachments) > 0)
        <div class="flex flex-wrap gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700 sm:px-4">
            @foreach ($uploadedAttachments as $index => $file)
                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-800">
                    <flux:icon name="paper-clip" class="size-3" />
                    {{ $file->getClientOriginalName() }}
                    <button wire:click="removeAttachment({{ $index }})" class="ml-1 text-zinc-400 hover:text-red-500">&times;</button>
                </span>
            @endforeach
        </div>
    @endif

    {{-- Footer --}}
    <div class="shrink-0 border-t border-zinc-200 bg-white px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-900 sm:px-4 sm:py-3">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <flux:button variant="primary" wire:click="send" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="send">{{ __('Send') }}</span>
                    <span wire:loading wire:target="send">{{ __('Sending...') }}</span>
                </flux:button>
                <label class="cursor-pointer rounded p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <flux:icon name="paper-clip" class="size-5" />
                    <input type="file" wire:model="uploadedAttachments" multiple class="hidden" />
                </label>
            </div>
            <p class="hidden text-xs text-zinc-400 sm:block">
                {{ __('From') }}: {{ $this->account?->full_address }}
            </p>
        </div>
    </div>
</div>
