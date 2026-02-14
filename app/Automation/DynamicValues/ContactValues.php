<?php

namespace App\Automation\DynamicValues;

use App\Automation\Contracts\DynamicValueInterface;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Models\WorkflowRun;

class ContactValues implements DynamicValueInterface
{
    public static function getPrefix(): string
    {
        return 'contact';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableKeys(): array
    {
        return ['name', 'email', 'phone'];
    }

    public function resolve(string $key, WorkflowRun $run): ?string
    {
        $user = $this->resolveUser($run);

        if (! $user) {
            return null;
        }

        return match ($key) {
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? null,
            default => null,
        };
    }

    /**
     * Resolve the contact user from the run's subject.
     */
    protected function resolveUser(WorkflowRun $run): ?User
    {
        $subject = $run->subject;

        if (! $subject) {
            return null;
        }

        if ($subject instanceof User) {
            return $subject;
        }

        if ($subject instanceof Customer) {
            return $subject->user;
        }

        if ($subject instanceof Deal) {
            return $subject->customer?->user;
        }

        if ($subject instanceof Job) {
            return $subject->deal?->customer?->user;
        }

        return null;
    }
}
