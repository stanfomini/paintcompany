<?php

namespace App\Jobs;

use App\Events\MessageTranslated;
use App\Models\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;

class TranslateChatMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public ChatMessage $message
    ) {}

    public function handle(): void
    {
        if (! $this->message->exists) {
            return;
        }

        if ($this->message->isSystem()) {
            return;
        }

        $text = trim($this->message->content);

        if (mb_strlen($text) < 5) {
            return;
        }

        $metadata = $this->message->metadata ?? [];

        if (isset($metadata['detected_lang'])) {
            return;
        }

        if (mb_strlen($text) > 5000) {
            $metadata['translation_error'] = 'text_too_large';
            $this->message->metadata = $metadata;
            $this->message->save();

            return;
        }

        try {
            $translator = app(GoogleTranslate::class);
            $translator->setSource(null);
            $translator->setTarget('en');

            $translated = $translator->translate($text);
            $detectedLang = $translator->getLastDetectedSource();

            // Determine target language
            if ($detectedLang === 'es') {
                $targetLang = 'en';
            } elseif ($detectedLang === 'en') {
                $targetLang = 'es';
            } else {
                $targetLang = 'en';
            }

            // If detected language matches target, no translation needed
            if ($detectedLang === $targetLang) {
                $metadata['detected_lang'] = $detectedLang;
                $this->message->metadata = $metadata;
                $this->message->save();

                broadcast(new MessageTranslated($this->message));

                return;
            }

            // If initial target was correct, use that translation
            if ($targetLang === 'en') {
                $finalTranslation = $translated;
            } else {
                // Re-translate with correct target
                $translator->setSource(null);
                $translator->setTarget($targetLang);
                $finalTranslation = $translator->translate($text);
            }

            $metadata['detected_lang'] = $detectedLang;
            $metadata['translations'][$targetLang] = $finalTranslation;

            $this->message->metadata = $metadata;
            $this->message->save();

            broadcast(new MessageTranslated($this->message));
        } catch (\Exception $e) {
            if (preg_match('/429|rate/i', $e->getMessage())) {
                $this->release(120);

                return;
            }

            Log::error('Chat translation failed', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $metadata['translation_error'] = $e->getMessage();
            $this->message->metadata = $metadata;
            $this->message->save();
        }
    }
}
