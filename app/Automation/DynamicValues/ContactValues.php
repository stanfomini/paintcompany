<?php

namespace App\Automation\DynamicValues;

use App\Automation\Contracts\DynamicValueInterface;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\MessengerConversation;
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
        return ['name', 'email', 'phone', 'messenger_psid'];
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
            'messenger_psid' => $this->resolveMessengerPsid($run),
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

    /**
     * Resolve the Messenger PSID for the contact.
     */
    protected function resolveMessengerPsid(WorkflowRun $run): ?string
    {
        $subject = $run->subject;

        if (! $subject) {
            return null;
        }

        $customerId = null;

        if ($subject instanceof Customer) {
            $customerId = $subject->id;
        } elseif ($subject instanceof Deal) {
            $customerId = $subject->customer_id;
        } elseif ($subject instanceof Job) {
            $customerId = $subject->deal?->customer_id;
        } elseif ($subject instanceof User) {
            $customer = Customer::where('user_id', $subject->id)->first();
            $customerId = $customer?->id;
        }

        if (! $customerId) {
            return null;
        }

        return MessengerConversation::where('customer_id', $customerId)
            ->whereNotNull('facebook_psid')
            ->value('facebook_psid');
    }
}
