<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\RvmTemplate;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceEnrollment;
use App\Models\Prospector\SequenceStep;
use App\Models\Prospector\SequenceStepLog;
use App\Models\Prospector\SmsMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SequenceEngine
{
    public function __construct(
        private ProspectorSmsService $smsService,
    ) {}

    /**
     * Process all enrollments that are due for execution.
     */
    public function processDueEnrollments(int $limit = 100): int
    {
        $enrollments = SequenceEnrollment::due()
            ->with(['sequence.company', 'contact', 'currentStep'])
            ->limit($limit)
            ->get();

        $processed = 0;

        foreach ($enrollments as $enrollment) {
            try {
                $this->executeStep($enrollment);
                $processed++;
            } catch (\Exception $e) {
                Log::error('Sequence step execution failed', [
                    'enrollment_id' => $enrollment->id,
                    'sequence_id' => $enrollment->sequence_id,
                    'contact_id' => $enrollment->contact_id,
                    'error' => $e->getMessage(),
                ]);

                SequenceStepLog::create([
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $enrollment->current_step_id,
                    'status' => 'failed',
                    'result' => ['error' => $e->getMessage()],
                    'executed_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * Execute the current step for an enrollment.
     */
    public function executeStep(SequenceEnrollment $enrollment): void
    {
        $enrollment->loadMissing(['sequence.company', 'contact', 'currentStep']);

        $step = $enrollment->currentStep;

        if (! $step) {
            $enrollment->update([
                'status' => 'completed',
                'completed_at' => now(),
                'next_execution_at' => null,
            ]);

            return;
        }

        // Check exit conditions before executing
        if ($this->checkExitConditions($enrollment)) {
            return;
        }

        // Check business hours
        $sequence = $enrollment->sequence;
        if ($sequence->business_hours_only && ! $sequence->company->isBusinessHours()) {
            $enrollment->update([
                'next_execution_at' => $this->getNextBusinessHoursStart($sequence->company),
            ]);

            return;
        }

        match ($step->type) {
            'sms' => $this->executeSmsStep($enrollment, $step),
            'email' => $this->executeEmailStep($enrollment, $step),
            'rvm' => $this->executeRvmStep($enrollment, $step),
            'call' => $this->executeCallStep($enrollment, $step),
            'wait' => $this->executeWaitStep($enrollment, $step),
            'condition' => $this->executeConditionStep($enrollment, $step),
            default => Log::warning('Unknown sequence step type', [
                'type' => $step->type,
                'step_id' => $step->id,
            ]),
        };
    }

    /**
     * Execute an SMS step — send SMS via ProspectorSmsService.
     */
    public function executeSmsStep(SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        $contact = $enrollment->contact;
        $company = $enrollment->sequence->company;
        $config = $step->config;

        $body = $this->replaceVariables($config['body'] ?? '', $contact, $company);
        $mediaUrls = ! empty($config['media_url']) ? [$config['media_url']] : null;

        try {
            $this->smsService->sendSms($company, $contact, $body, mediaUrls: $mediaUrls);

            SequenceStepLog::create([
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'status' => 'sent',
                'result' => ['body' => $body],
                'executed_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            SequenceStepLog::create([
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'status' => 'failed',
                'result' => ['error' => $e->getMessage()],
                'executed_at' => now(),
                'created_at' => now(),
            ]);

            throw $e;
        }

        $this->advanceToNextStep($enrollment);
    }

    /**
     * Execute an email step — send email using Laravel Mail.
     */
    public function executeEmailStep(SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        $contact = $enrollment->contact;
        $company = $enrollment->sequence->company;
        $config = $step->config;

        if (! $contact->email) {
            SequenceStepLog::create([
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'status' => 'skipped',
                'result' => ['reason' => 'Contact has no email address'],
                'executed_at' => now(),
                'created_at' => now(),
            ]);

            $this->advanceToNextStep($enrollment);

            return;
        }

        $subject = $this->replaceVariables($config['subject'] ?? '', $contact, $company);
        $body = $this->replaceVariables($config['template'] ?? '', $contact, $company);

        Mail::raw($body, function ($message) use ($contact, $subject, $company) {
            $message->to($contact->email)
                ->subject($subject)
                ->from(config('mail.from.address'), $company->name);
        });

        SequenceStepLog::create([
            'enrollment_id' => $enrollment->id,
            'step_id' => $step->id,
            'status' => 'sent',
            'result' => ['subject' => $subject],
            'executed_at' => now(),
            'created_at' => now(),
        ]);

        $this->advanceToNextStep($enrollment);
    }

    /**
     * Execute an RVM step — drop a ringless voicemail to the contact.
     */
    public function executeRvmStep(SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        $contact = $enrollment->contact;
        $company = $enrollment->sequence->company;
        $config = $step->config ?? [];

        $templateId = $config['rvm_template_id'] ?? null;
        $template = $templateId ? RvmTemplate::find($templateId) : null;

        if (! $template) {
            SequenceStepLog::create([
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'status' => 'failed',
                'result' => ['reason' => 'RVM template not found', 'template_id' => $templateId],
                'executed_at' => now(),
                'created_at' => now(),
            ]);

            $this->advanceToNextStep($enrollment);

            return;
        }

        try {
            $rvmService = app(RvmService::class);
            $method = $config['rvm_method'] ?? null;
            $drop = $rvmService->drop($company, $contact, $template, $method, $enrollment);

            SequenceStepLog::create([
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'status' => 'completed',
                'result' => [
                    'rvm_drop_id' => $drop->id,
                    'method' => $drop->method,
                    'to' => $drop->to_number,
                ],
                'executed_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Sequence RVM step failed', [
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);

            SequenceStepLog::create([
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'status' => 'failed',
                'result' => ['error' => $e->getMessage()],
                'executed_at' => now(),
                'created_at' => now(),
            ]);
        }

        $this->advanceToNextStep($enrollment);
    }

    /**
     * Execute a call step — add contact to campaign queue with high priority.
     */
    public function executeCallStep(SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        $contact = $enrollment->contact;
        $company = $enrollment->sequence->company;

        // Find the company's active campaign or the first campaign
        $campaign = Campaign::where('company_id', $company->id)
            ->where('status', 'active')
            ->first();

        if (! $campaign) {
            SequenceStepLog::create([
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'status' => 'skipped',
                'result' => ['reason' => 'No active campaign found for company'],
                'executed_at' => now(),
                'created_at' => now(),
            ]);

            $this->advanceToNextStep($enrollment);

            return;
        }

        // Add to campaign queue with sort_order 0 (high priority)
        $existing = CampaignContact::where('campaign_id', $campaign->id)
            ->where('contact_id', $contact->id)
            ->first();

        if (! $existing) {
            CampaignContact::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'queued',
                'sort_order' => 0,
                'attempts' => 0,
                'added_at' => now(),
            ]);
        } elseif ($existing->status === 'completed' || $existing->status === 'skipped') {
            $existing->reQueue();
        }

        SequenceStepLog::create([
            'enrollment_id' => $enrollment->id,
            'step_id' => $step->id,
            'status' => 'sent',
            'result' => [
                'campaign_id' => $campaign->id,
                'script' => $step->config['script'] ?? null,
            ],
            'executed_at' => now(),
            'created_at' => now(),
        ]);

        $this->advanceToNextStep($enrollment);
    }

    /**
     * Execute a wait step — calculate next_execution_at from delay.
     */
    public function executeWaitStep(SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        $delayMinutes = $step->getDelayInMinutes();

        SequenceStepLog::create([
            'enrollment_id' => $enrollment->id,
            'step_id' => $step->id,
            'status' => 'sent',
            'result' => ['delay_minutes' => $delayMinutes],
            'executed_at' => now(),
            'created_at' => now(),
        ]);

        // Advance to next step but set its execution time in the future
        $steps = $enrollment->sequence->steps()->ordered()->get();
        $currentIndex = $steps->search(fn ($s) => $s->id === $step->id);
        $nextStep = $steps->get($currentIndex + 1);

        if ($nextStep === null) {
            $enrollment->update([
                'status' => 'completed',
                'current_step_id' => null,
                'next_execution_at' => null,
                'completed_at' => now(),
            ]);

            return;
        }

        $nextExecution = now()->addMinutes($delayMinutes);

        // If business hours only, defer to next business hours window
        if ($enrollment->sequence->business_hours_only) {
            $company = $enrollment->sequence->company;
            $tz = $company->timezone ?? 'America/Denver';
            $nextInTz = $nextExecution->copy()->setTimezone($tz);

            if (! $company->isBusinessHours() || $nextInTz->lt(Carbon::now($tz))) {
                $nextExecution = $this->getNextBusinessHoursStart($company);
            }
        }

        $enrollment->update([
            'current_step_id' => $nextStep->id,
            'next_execution_at' => $nextExecution,
        ]);
    }

    /**
     * Execute a condition step — evaluate condition and branch.
     */
    public function executeConditionStep(SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        $contact = $enrollment->contact;
        $config = $step->config;

        $field = $config['field'] ?? '';
        $operator = $config['operator'] ?? 'equals';
        $value = $config['value'] ?? '';

        $contactValue = match ($field) {
            'disposition', 'last_disposition' => $contact->last_disposition,
            'tags' => $contact->tags,
            'do_not_call' => $contact->do_not_call ? 'true' : 'false',
            'total_calls' => (string) $contact->total_calls,
            'total_sms' => (string) $contact->total_sms,
            'city' => $contact->city,
            'state' => $contact->state,
            default => $contact->custom_fields[$field] ?? null,
        };

        $passed = match ($operator) {
            'equals' => $contactValue === $value,
            'not_equals' => $contactValue !== $value,
            'contains' => is_array($contactValue) ? in_array($value, $contactValue) : str_contains((string) $contactValue, $value),
            'not_contains' => is_array($contactValue) ? ! in_array($value, $contactValue) : ! str_contains((string) $contactValue, $value),
            'greater_than' => (float) $contactValue > (float) $value,
            'less_than' => (float) $contactValue < (float) $value,
            'is_empty' => empty($contactValue),
            'is_not_empty' => ! empty($contactValue),
            default => false,
        };

        $logStatus = $passed ? 'sent' : 'skipped';

        SequenceStepLog::create([
            'enrollment_id' => $enrollment->id,
            'step_id' => $step->id,
            'status' => $logStatus,
            'result' => [
                'field' => $field,
                'operator' => $operator,
                'expected' => $value,
                'actual' => $contactValue,
                'passed' => $passed,
            ],
            'executed_at' => now(),
            'created_at' => now(),
        ]);

        if ($passed) {
            $this->advanceToNextStep($enrollment);
        } else {
            // Condition not met — exit the sequence
            $enrollment->exit("Condition not met: {$field} {$operator} {$value}");
        }
    }

    /**
     * Enroll a contact in a sequence.
     */
    public function enrollContact(Contact $contact, Sequence $sequence, ?User $enrolledBy = null): SequenceEnrollment
    {
        $firstStep = $sequence->steps()->ordered()->first();

        return SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'current_step_id' => $firstStep?->id,
            'status' => 'active',
            'next_execution_at' => now(),
            'enrolled_at' => now(),
            'enrolled_by' => $enrolledBy?->id,
        ]);
    }

    /**
     * Unenroll a contact from a sequence.
     */
    public function unenrollContact(Contact $contact, Sequence $sequence, string $reason = 'manual'): void
    {
        $enrollment = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact->id)
            ->where('status', 'active')
            ->first();

        $enrollment?->exit($reason);
    }

    /**
     * Check if exit conditions are met for an enrollment.
     *
     * Returns true if the enrollment was exited.
     */
    public function checkExitConditions(SequenceEnrollment $enrollment): bool
    {
        $contact = $enrollment->contact;

        // Check if contact replied (inbound SMS since enrollment)
        $hasReply = SmsMessage::where('contact_id', $contact->id)
            ->where('direction', 'inbound')
            ->where('created_at', '>=', $enrollment->enrolled_at)
            ->exists();

        if ($hasReply) {
            $enrollment->exit('contact_replied');

            return true;
        }

        // Check if contact is now DNC
        if ($contact->do_not_call) {
            $enrollment->exit('do_not_call');

            return true;
        }

        // Check custom exit conditions from sequence config
        $exitConditions = $enrollment->sequence->exit_conditions ?? [];

        foreach ($exitConditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? 'equals';
            $value = $condition['value'] ?? '';

            $contactValue = $contact->custom_fields[$field] ?? ($contact->{$field} ?? null);

            $shouldExit = match ($operator) {
                'equals' => $contactValue == $value,
                'not_equals' => $contactValue != $value,
                'contains' => str_contains((string) $contactValue, $value),
                default => false,
            };

            if ($shouldExit) {
                $enrollment->exit("exit_condition: {$field} {$operator} {$value}");

                return true;
            }
        }

        return false;
    }

    /**
     * Replace variable placeholders in text with contact/company values.
     */
    public function replaceVariables(string $text, Contact $contact, Company $company): string
    {
        return str_replace(
            ['{first_name}', '{last_name}', '{phone}', '{email}', '{company_name}'],
            [$contact->first_name ?? '', $contact->last_name ?? '', $contact->phone ?? '', $contact->email ?? '', $company->name ?? ''],
            $text
        );
    }

    /**
     * Advance enrollment to the next step in the sequence.
     */
    public function advanceToNextStep(SequenceEnrollment $enrollment): void
    {
        $steps = $enrollment->sequence->steps()->ordered()->get();
        $currentIndex = $steps->search(fn ($s) => $s->id === $enrollment->current_step_id);

        $nextStep = $steps->get($currentIndex + 1);

        if ($nextStep === null) {
            $enrollment->update([
                'status' => 'completed',
                'current_step_id' => null,
                'next_execution_at' => null,
                'completed_at' => now(),
            ]);

            return;
        }

        $nextExecution = now()->addMinutes($nextStep->getDelayInMinutes());

        $enrollment->update([
            'current_step_id' => $nextStep->id,
            'next_execution_at' => $nextExecution,
        ]);
    }

    /**
     * Calculate the next business hours start for a company.
     */
    private function getNextBusinessHoursStart(Company $company): Carbon
    {
        $tz = $company->timezone ?? 'America/Denver';
        $businessDays = $company->business_days ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $startTime = $company->business_hours_start ?? '09:00';

        $candidate = Carbon::now($tz)->addDay()->setTimeFromTimeString($startTime);

        // Walk forward until we hit a business day
        for ($i = 0; $i < 7; $i++) {
            $dayName = strtolower($candidate->format('l'));

            if (in_array($dayName, $businessDays)) {
                return $candidate->setTimezone('UTC');
            }

            $candidate->addDay();
        }

        // Fallback: next day at start time
        return Carbon::now($tz)->addDay()->setTimeFromTimeString($startTime)->setTimezone('UTC');
    }
}
