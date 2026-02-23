<?php

namespace App\Jobs;

use App\Models\EmailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(public EmailMessage $message)
    {
        $this->onConnection('redis');
    }

    /**
     * Send the email via Mailgun HTTP API.
     */
    public function handle(): void
    {
        $domain = config('email.domain');
        $secret = config('services.mailgun.secret');
        $endpoint = config('services.mailgun.endpoint', 'api.mailgun.net');

        $url = "https://{$endpoint}/v3/{$domain}/messages";

        // Build form data
        $formData = [
            'from' => $this->message->from_name
                ? "{$this->message->from_name} <{$this->message->from_address}>"
                : $this->message->from_address,
            'subject' => $this->message->subject,
            'html' => $this->message->body_html,
            'text' => $this->message->body_text,
            'h:Message-Id' => $this->message->message_id,
            'h:Reply-To' => $this->message->from_address,
        ];

        // Add recipients
        $formData['to'] = $this->formatAddressList($this->message->to_addresses);

        // CC
        if ($this->message->cc_addresses) {
            $formData['cc'] = $this->formatAddressList($this->message->cc_addresses);
        }

        // BCC
        if ($this->message->bcc_addresses) {
            $formData['bcc'] = $this->formatAddressList($this->message->bcc_addresses);
        }

        // Threading headers
        if ($this->message->in_reply_to) {
            $formData['h:In-Reply-To'] = $this->message->in_reply_to;
        }
        if ($this->message->references) {
            $formData['h:References'] = implode(' ', $this->message->references);
        }

        // Build multipart request with attachments
        $request = Http::withBasicAuth('api', $secret)->timeout(60);

        $attachments = $this->message->attachments;
        if ($attachments->isNotEmpty()) {
            $multipart = [];
            foreach ($formData as $key => $value) {
                $multipart[] = ['name' => $key, 'contents' => $value];
            }
            foreach ($attachments as $attachment) {
                if (Storage::exists($attachment->path)) {
                    $multipart[] = [
                        'name' => $attachment->is_inline ? 'inline' : 'attachment',
                        'contents' => Storage::get($attachment->path),
                        'filename' => $attachment->original_filename,
                    ];
                }
            }
            $response = $request->asMultipart()->post($url, $multipart);
        } else {
            $response = $request->asForm()->post($url, $formData);
        }

        if ($response->successful()) {
            $mailgunId = $response->json('id');
            $this->message->update(['mailgun_message_id' => $mailgunId]);
        } else {
            Log::error('SendEmailJob failed', [
                'message_id' => $this->message->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $this->fail(new \RuntimeException('Mailgun API error: '.$response->status()));
        }
    }

    /**
     * Format an address array into a comma-separated string.
     *
     * @param  array{address: string, name: string|null}[]  $addresses
     */
    private function formatAddressList(array $addresses): string
    {
        return collect($addresses)->map(function (array $addr): string {
            return isset($addr['name']) && $addr['name']
                ? "{$addr['name']} <{$addr['address']}>"
                : $addr['address'];
        })->implode(', ');
    }
}
