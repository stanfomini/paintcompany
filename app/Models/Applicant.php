<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Applicant extends Model
{
    /** @use HasFactory<\Database\Factories\ApplicantFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (Applicant $applicant) {
            event(new \App\Events\Automation\ApplicationReceived($applicant));
        });
    }

    public const STAGES = [
        'applied',
        'screening',
        'phone_interview',
        'in_person',
        'background_check',
        'offer',
        'hired',
        'rejected',
    ];

    public const STAGE_LABELS = [
        'applied' => 'Applied',
        'screening' => 'Screening',
        'phone_interview' => 'Phone Interview',
        'in_person' => 'In-Person Interview',
        'background_check' => 'Background Check',
        'offer' => 'Offer',
        'hired' => 'Hired',
        'rejected' => 'Rejected',
    ];

    public const SOURCES = [
        'web' => 'Website',
        'qr_code' => 'QR Code',
        'manual' => 'Manual Entry',
        'referral' => 'Referral',
    ];

    protected $fillable = [
        'job_listing_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'resume_path',
        'cover_letter',
        'source',
        'referral_source',
        'stage',
        'rejection_reason',
        'notes',
        'rating',
        'interview_date',
        'offer_amount',
        'offer_type',
        'hired_at',
        'converted_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'interview_date' => 'datetime',
            'hired_at' => 'datetime',
            'offer_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<JobListing, $this>
     */
    public function jobListing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function convertedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }

    public function getFullName(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function isHired(): bool
    {
        return $this->stage === 'hired' && $this->hired_at !== null;
    }

    /**
     * Advance the applicant to the next pipeline stage.
     */
    public function advanceStage(): void
    {
        $currentIndex = array_search($this->stage, self::STAGES);

        if ($currentIndex === false || $this->stage === 'hired' || $this->stage === 'rejected') {
            return;
        }

        $nextIndex = $currentIndex + 1;

        if ($nextIndex < count(self::STAGES) && self::STAGES[$nextIndex] !== 'rejected') {
            $this->stage = self::STAGES[$nextIndex];

            if ($this->stage === 'hired') {
                $this->hired_at = now();
            }

            $this->save();
        }
    }

    /**
     * Reject the applicant with a reason.
     */
    public function reject(?string $reason = null): void
    {
        $this->stage = 'rejected';
        $this->rejection_reason = $reason;
        $this->save();
    }
}
