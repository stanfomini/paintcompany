<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceEnrollment;
use Illuminate\Support\Facades\Log;

class SequenceAutoEnrollmentService
{
    public function __construct(
        private SequenceEngine $engine,
    ) {}

    public function handleDisposition(Contact $contact, string $disposition, ?Campaign $campaign = null): void
    {
        if ($disposition === 'dnc') {
            $this->handleDnc($contact);

            return;
        }

        $sequence = $this->resolveDispositionSequence($contact, $disposition, $campaign);

        if (! $sequence) {
            return;
        }

        $this->exitActiveEnrollments($contact, 'disposition_change', 'disposition');

        $existing = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact->id)
            ->whereIn('status', ['active', 'paused'])
            ->exists();

        if ($existing) {
            return;
        }

        $enrollment = $this->engine->enrollContact($contact, $sequence);
        $enrollment->update(['enrolled_via' => 'disposition']);

        Log::info('SequenceAutoEnrollment: disposition enrollment', [
            'contact_id' => $contact->id,
            'disposition' => $disposition,
            'sequence_id' => $sequence->id,
        ]);
    }

    public function handleDnc(Contact $contact): void
    {
        $this->exitActiveEnrollments($contact, 'do_not_call');

        Log::info('SequenceAutoEnrollment: DNC cascade', [
            'contact_id' => $contact->id,
        ]);
    }

    public function handleNewContact(Contact $contact): void
    {
        $sequences = Sequence::where('company_id', $contact->company_id)
            ->where('trigger_type', 'new_contact')
            ->active()
            ->get();

        foreach ($sequences as $sequence) {
            $sources = $sequence->trigger_config['sources'] ?? null;
            if ($sources && ! in_array($contact->source, $sources)) {
                continue;
            }

            $existing = SequenceEnrollment::where('sequence_id', $sequence->id)
                ->where('contact_id', $contact->id)
                ->exists();

            if ($existing) {
                continue;
            }

            $enrollment = $this->engine->enrollContact($contact, $sequence);
            $enrollment->update(['enrolled_via' => 'new_contact']);

            Log::info('SequenceAutoEnrollment: new_contact enrollment', [
                'contact_id' => $contact->id,
                'sequence_id' => $sequence->id,
            ]);
        }
    }

    public function handleTagAdded(Contact $contact, array $newTags): void
    {
        if (empty($newTags)) {
            return;
        }

        $sequences = Sequence::where('company_id', $contact->company_id)
            ->where('trigger_type', 'tag_added')
            ->active()
            ->get();

        foreach ($sequences as $sequence) {
            $triggerTags = $sequence->trigger_config['tags'] ?? [];
            if (empty($triggerTags)) {
                continue;
            }

            if (empty(array_intersect($newTags, $triggerTags))) {
                continue;
            }

            $existing = SequenceEnrollment::where('sequence_id', $sequence->id)
                ->where('contact_id', $contact->id)
                ->whereIn('status', ['active', 'paused'])
                ->exists();

            if ($existing) {
                continue;
            }

            $enrollment = $this->engine->enrollContact($contact, $sequence);
            $enrollment->update(['enrolled_via' => 'tag_added']);

            Log::info('SequenceAutoEnrollment: tag_added enrollment', [
                'contact_id' => $contact->id,
                'sequence_id' => $sequence->id,
                'matched_tags' => array_intersect($newTags, $triggerTags),
            ]);
        }
    }

    private function exitActiveEnrollments(Contact $contact, string $reason, ?string $enrolledVia = null): void
    {
        $query = SequenceEnrollment::where('contact_id', $contact->id)
            ->where('status', 'active');

        if ($enrolledVia) {
            $query->where('enrolled_via', $enrolledVia);
        }

        $query->each(fn (SequenceEnrollment $e) => $e->exit($reason));
    }

    private function resolveDispositionSequence(Contact $contact, string $disposition, ?Campaign $campaign): ?Sequence
    {
        $sequenceId = null;

        if ($campaign) {
            $route = $campaign->disposition_routes[$disposition] ?? null;
            $sequenceId = $route['target_sequence_id'] ?? null;
        }

        if (! $sequenceId) {
            $company = $contact->company ?? Company::find($contact->company_id);
            $defaults = $company?->default_disposition_sequences ?? [];
            $sequenceId = $defaults[$disposition] ?? null;
        }

        if (! $sequenceId) {
            return null;
        }

        return Sequence::where('id', $sequenceId)->active()->first();
    }
}
