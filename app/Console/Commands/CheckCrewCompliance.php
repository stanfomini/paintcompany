<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\CrewProfile;
use App\Models\User;
use App\Notifications\CrewComplianceExpiring;
use Illuminate\Console\Command;

class CheckCrewCompliance extends Command
{
    protected $signature = 'crew:check-compliance';

    protected $description = 'Check for expiring or expired crew compliance documents and create tasks';

    public function handle(): int
    {
        $crews = User::query()
            ->where('type', 'crew')
            ->where('is_active', true)
            ->with('crewProfile')
            ->whereHas('crewProfile')
            ->get();

        $alertCount = 0;

        foreach ($crews as $crew) {
            $expiringItems = $this->getExpiringItems($crew->crewProfile);

            if (empty($expiringItems)) {
                continue;
            }

            $created = $this->createTaskIfNeeded($crew, $expiringItems);

            if ($created) {
                $this->notifyInternalUsers($crew, $expiringItems);
                $alertCount++;
            }
        }

        $this->info("Compliance check complete. {$alertCount} new alert(s) created.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{label: string, type: string, expires: string}>
     */
    protected function getExpiringItems(CrewProfile $profile): array
    {
        $items = [];

        if ($profile->isInsuranceExpired()) {
            $items[] = [
                'label' => 'General Liability Insurance',
                'type' => 'expired',
                'expires' => $profile->insurance_expiration_date->format('M j, Y'),
            ];
        } elseif ($profile->isInsuranceExpiringSoon()) {
            $items[] = [
                'label' => 'General Liability Insurance',
                'type' => 'expiring',
                'expires' => $profile->insurance_expiration_date->format('M j, Y'),
            ];
        }

        if ($profile->isWorkersCompExpired()) {
            $items[] = [
                'label' => "Workers' Compensation",
                'type' => 'expired',
                'expires' => $profile->workers_comp_expiration_date->format('M j, Y'),
            ];
        } elseif ($profile->isWorkersCompExpiringSoon()) {
            $items[] = [
                'label' => "Workers' Compensation",
                'type' => 'expiring',
                'expires' => $profile->workers_comp_expiration_date->format('M j, Y'),
            ];
        }

        if ($profile->isLicenseExpired()) {
            $items[] = [
                'label' => 'Contractor License',
                'type' => 'expired',
                'expires' => $profile->license_expiration_date->format('M j, Y'),
            ];
        } elseif ($profile->isLicenseExpiringSoon()) {
            $items[] = [
                'label' => 'Contractor License',
                'type' => 'expiring',
                'expires' => $profile->license_expiration_date->format('M j, Y'),
            ];
        }

        return $items;
    }

    protected function createTaskIfNeeded(User $crew, array $expiringItems): bool
    {
        $hasExisting = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $crew->id)
            ->where('type', 'task')
            ->where('title', 'like', 'Crew Compliance Alert:%')
            ->incomplete()
            ->exists();

        if ($hasExisting) {
            return false;
        }

        $labels = collect($expiringItems)->pluck('label')->implode(', ');
        $hasExpired = collect($expiringItems)->contains('type', 'expired');

        $firstInternal = User::where('type', 'internal')->first();

        Activity::create([
            'user_id' => $firstInternal?->id,
            'assigned_to' => $firstInternal?->id,
            'subject_type' => User::class,
            'subject_id' => $crew->id,
            'type' => 'task',
            'title' => "Crew Compliance Alert: {$crew->name}",
            'description' => ($hasExpired ? 'EXPIRED' : 'Expiring soon').": {$labels}. Reach out to crew to update their documents.",
            'due_at' => $hasExpired ? now() : now()->addDays(7),
        ]);

        return true;
    }

    protected function notifyInternalUsers(User $crew, array $expiringItems): void
    {
        $internalUsers = User::where('type', 'internal')->get();

        foreach ($internalUsers as $user) {
            $user->notify(new CrewComplianceExpiring($crew, $expiringItems));
        }
    }
}
