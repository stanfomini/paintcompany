<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Contact;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceEnrollment;
use App\Models\User;

class SequenceEnrollmentService
{
    public function __construct(
        private SequenceEngine $engine,
    ) {}

    /**
     * Enroll a single contact in a sequence.
     */
    public function enrollContact(Contact $contact, Sequence $sequence, ?User $enrolledBy = null): SequenceEnrollment
    {
        return $this->engine->enrollContact($contact, $sequence, $enrolledBy);
    }

    /**
     * Bulk enroll multiple contacts in a sequence, skipping already enrolled.
     */
    public function bulkEnroll(array $contactIds, Sequence $sequence, ?User $enrolledBy = null): int
    {
        $existingContactIds = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->whereIn('contact_id', $contactIds)
            ->pluck('contact_id')
            ->all();

        $toEnroll = array_diff($contactIds, $existingContactIds);
        $enrolled = 0;

        foreach ($toEnroll as $contactId) {
            $contact = Contact::find($contactId);

            if (! $contact) {
                continue;
            }

            $this->engine->enrollContact($contact, $sequence, $enrolledBy);
            $enrolled++;
        }

        return $enrolled;
    }

    /**
     * Unenroll (exit) an enrollment with a reason.
     */
    public function unenroll(SequenceEnrollment $enrollment, string $reason = 'manual'): void
    {
        $enrollment->exit($reason);
    }

    /**
     * Pause an enrollment.
     */
    public function pauseEnrollment(SequenceEnrollment $enrollment): void
    {
        $enrollment->pause();
    }

    /**
     * Resume a paused enrollment.
     */
    public function resumeEnrollment(SequenceEnrollment $enrollment): void
    {
        $enrollment->resume();
    }
}
