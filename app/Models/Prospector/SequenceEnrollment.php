<?php

namespace App\Models\Prospector;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SequenceEnrollment extends Model
{
    use HasFactory;

    protected $table = 'prospector_sequence_enrollments';

    protected $fillable = [
        'sequence_id',
        'contact_id',
        'current_step_id',
        'status',
        'exit_reason',
        'next_execution_at',
        'enrolled_at',
        'completed_at',
        'enrolled_by',
        'enrolled_via',
    ];

    protected function casts(): array
    {
        return [
            'next_execution_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'sequence_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class, 'current_step_id');
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    public function stepLogs(): HasMany
    {
        return $this->hasMany(SequenceStepLog::class, 'enrollment_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function scopeDue(Builder $query): void
    {
        $query->where('status', 'active')
            ->where('next_execution_at', '<=', now());
    }

    public function scopeForSequence(Builder $query, int $sequenceId): void
    {
        $query->where('sequence_id', $sequenceId);
    }

    public function scopeForContact(Builder $query, int $contactId): void
    {
        $query->where('contact_id', $contactId);
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Check if this enrollment is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Advance the enrollment to the next step in the sequence.
     */
    public function advance(): void
    {
        $steps = $this->sequence->steps()->ordered()->get();
        $currentIndex = $steps->search(fn ($step) => $step->id === $this->current_step_id);

        $nextStep = $steps->get($currentIndex + 1);

        if ($nextStep === null) {
            $this->update([
                'status' => 'completed',
                'current_step_id' => null,
                'next_execution_at' => null,
                'completed_at' => now(),
            ]);

            return;
        }

        $this->update([
            'current_step_id' => $nextStep->id,
            'next_execution_at' => now()->addMinutes($nextStep->getDelayInMinutes()),
        ]);
    }

    /**
     * Exit the enrollment with a reason.
     */
    public function exit(string $reason): void
    {
        $this->update([
            'status' => 'exited',
            'exit_reason' => $reason,
            'next_execution_at' => null,
        ]);
    }

    /**
     * Pause the enrollment.
     */
    public function pause(): void
    {
        $this->update([
            'status' => 'paused',
            'next_execution_at' => null,
        ]);
    }

    /**
     * Resume the enrollment.
     */
    public function resume(): void
    {
        $this->update([
            'status' => 'active',
            'next_execution_at' => now(),
        ]);
    }
}
