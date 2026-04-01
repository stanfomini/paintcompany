<?php

namespace App\Jobs\Prospector;

use App\Models\Prospector\Call;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Services\Prospector\AmdService;
use App\Services\Prospector\ConferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DialContactIntoConferenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $conferenceName,
        public Contact $contact,
        public PhoneNumber $fromNumber,
        public Call $call,
    ) {
        $this->onQueue('default');
    }

    public function handle(ConferenceService $conferenceService): void
    {
        try {
            $amdParams = [];
            if (config('prospector.defaults.amd_enabled')) {
                $amdParams = app(AmdService::class)->getAmdParams();
            }

            $callSid = $conferenceService->dialContactIntoConference(
                $this->conferenceName,
                $this->contact,
                $this->fromNumber,
                $amdParams,
            );

            $this->call->update(['twilio_call_sid' => $callSid]);
        } catch (\Exception $e) {
            Log::error('DialContactIntoConferenceJob: failed', [
                'call_id' => $this->call->id,
                'contact_id' => $this->contact->id,
                'error' => $e->getMessage(),
            ]);
            $this->call->markFailed();
        }
    }
}
