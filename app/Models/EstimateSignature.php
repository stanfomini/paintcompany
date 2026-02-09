<?php

namespace App\Models;

use App\Notifications\EstimateAccepted;
use App\Services\InvoiceService;
use App\Services\JobService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateSignature extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateSignatureFactory> */
    use HasFactory;

    public const SIGNATURE_TYPES = [
        'drawn' => 'Drawn',
        'typed' => 'Typed',
    ];

    protected $fillable = [
        'estimate_id',
        'signer_name',
        'signer_email',
        'signature_data',
        'signature_type',
        'ip_address',
        'user_agent',
        'accepted_terms',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_terms' => 'boolean',
            'signed_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::created(function (EstimateSignature $signature) {
            $signature->estimate->markAsAccepted();

            EstimateActivity::log(
                $signature->estimate,
                'signature_added',
                "Estimate signed by {$signature->signer_name}",
                [
                    'signer_name' => $signature->signer_name,
                    'signer_email' => $signature->signer_email,
                    'signature_type' => $signature->signature_type,
                ]
            );

            // Notify all internal users about the accepted estimate
            $internalUsers = User::where('type', 'internal')->get();
            foreach ($internalUsers as $user) {
                $user->notify(new EstimateAccepted($signature->estimate));
            }

            // Create a deal chat thread for the accepted estimate
            $deal = $signature->estimate->deal;
            if ($deal) {
                $thread = $deal->getOrCreateChatThread();

                // Add internal users as participants
                foreach ($internalUsers as $user) {
                    if (! $thread->hasParticipant($user)) {
                        $thread->addParticipant($user);
                    }
                }

                // Post a system message about the acceptance
                $thread->messages()->create([
                    'user_id' => null,
                    'content' => "Estimate #{$signature->estimate->estimate_number} has been accepted by {$signature->signer_name}.",
                    'type' => ChatMessage::TYPE_SYSTEM,
                ]);

                // Advance deal to 'signed_accepted' stage and create job
                $deal->moveTo('signed_accepted');

                // Auto-create job from the accepted estimate
                app(JobService::class)->createFromEstimate($signature->estimate);

                // Advance referral status if this estimate was referred
                app(\App\Services\ReferralService::class)->onEstimateSigned($signature->estimate);

                // Auto-create projected job cost card and deposit invoice
                $signature->estimate->load('areas.surfaces');
                $job = $signature->estimate->job;
                if ($job) {
                    app(\App\Services\JobCostCardService::class)->createFromEstimate($signature->estimate, $job);
                    app(InvoiceService::class)->createDepositInvoice($signature->estimate, $job);
                }
            }
        });
    }

    /**
     * Get the estimate for this signature.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Create a signature from a request.
     */
    public static function createFromRequest(
        Estimate $estimate,
        string $signerName,
        string $signerEmail,
        string $signatureData,
        string $signatureType
    ): self {
        return static::create([
            'estimate_id' => $estimate->id,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'signature_data' => $signatureData,
            'signature_type' => $signatureType,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'accepted_terms' => true,
            'signed_at' => now(),
        ]);
    }
}
