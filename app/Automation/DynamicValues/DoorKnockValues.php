<?php

namespace App\Automation\DynamicValues;

use App\Automation\Contracts\DynamicValueInterface;
use App\Models\DoorKnock;
use App\Models\WorkflowRun;

class DoorKnockValues implements DynamicValueInterface
{
    public static function getPrefix(): string
    {
        return 'doorknock';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableKeys(): array
    {
        return [
            'customer_name', 'first_name', 'last_name',
            'email', 'phone', 'address', 'city', 'state', 'zip',
            'status', 'notes',
        ];
    }

    public function resolve(string $key, WorkflowRun $run): ?string
    {
        $knock = $this->resolveKnock($run);

        if (! $knock) {
            return null;
        }

        return match ($key) {
            'customer_name' => $knock->customer_name,
            'first_name' => $this->extractFirstName($knock->customer_name),
            'last_name' => $this->extractLastName($knock->customer_name),
            'email' => $knock->email,
            'phone' => $knock->phone,
            'address' => $knock->address,
            'city' => $knock->city,
            'state' => $knock->state,
            'zip' => $knock->zip,
            'status' => $knock->status,
            'notes' => $knock->notes,
            default => null,
        };
    }

    protected function resolveKnock(WorkflowRun $run): ?DoorKnock
    {
        $subject = $run->subject;

        if ($subject instanceof DoorKnock) {
            return $subject;
        }

        $knockId = $run->context['doorknock_id'] ?? null;

        if ($knockId) {
            return DoorKnock::find($knockId);
        }

        return null;
    }

    protected function extractFirstName(?string $fullName): ?string
    {
        if (empty($fullName)) {
            return null;
        }

        $parts = explode(' ', $fullName, 2);

        return $parts[0];
    }

    protected function extractLastName(?string $fullName): ?string
    {
        if (empty($fullName)) {
            return null;
        }

        $parts = explode(' ', $fullName, 2);

        return $parts[1] ?? '';
    }
}
