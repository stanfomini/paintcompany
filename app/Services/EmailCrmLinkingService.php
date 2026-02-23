<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmailCrmLink;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EmailCrmLinkingService
{
    /**
     * Auto-link an email message to CRM records based on email addresses.
     */
    public function autoLink(EmailMessage $message): void
    {
        $addresses = $this->extractAllAddresses($message);

        foreach ($addresses as $address) {
            // Match against customers
            $customers = Customer::where('email', $address)->get();
            foreach ($customers as $customer) {
                $this->createLink($message, $customer, 'auto');

                // Also link to the customer's active deals
                $deals = Deal::where('customer_id', $customer->id)
                    ->whereNotIn('stage', ['lost', 'cancelled', 'completed'])
                    ->get();
                foreach ($deals as $deal) {
                    $this->createLink($message, $deal, 'auto');
                }
            }

            // Match against agent users (type = 'agent')
            $agents = User::where('email', $address)
                ->where('type', 'agent')
                ->get();
            foreach ($agents as $agent) {
                // Link to deals where this agent is the referrer
                $deals = Deal::where('agent_id', $agent->id)
                    ->whereNotIn('stage', ['lost', 'cancelled', 'completed'])
                    ->get();
                foreach ($deals as $deal) {
                    $this->createLink($message, $deal, 'auto');
                }
            }
        }
    }

    /**
     * Manually link an email to a CRM record.
     */
    public function manualLink(EmailMessage $message, Model $linkable, User $linkedBy): EmailCrmLink
    {
        return EmailCrmLink::firstOrCreate(
            [
                'email_message_id' => $message->id,
                'linkable_type' => get_class($linkable),
                'linkable_id' => $linkable->id,
            ],
            [
                'link_type' => 'manual',
                'linked_by' => $linkedBy->id,
            ]
        );
    }

    /**
     * Remove a CRM link.
     */
    public function unlink(EmailCrmLink $link): void
    {
        $link->delete();
    }

    /**
     * Extract all email addresses from a message (from, to, cc).
     *
     * @return string[]
     */
    private function extractAllAddresses(EmailMessage $message): array
    {
        $addresses = [];

        // From address
        $addresses[] = $message->from_address;

        // To addresses
        if (is_array($message->to_addresses)) {
            foreach ($message->to_addresses as $addr) {
                if (isset($addr['address'])) {
                    $addresses[] = $addr['address'];
                }
            }
        }

        // CC addresses
        if (is_array($message->cc_addresses)) {
            foreach ($message->cc_addresses as $addr) {
                if (isset($addr['address'])) {
                    $addresses[] = $addr['address'];
                }
            }
        }

        // Filter out our own domain addresses
        $domain = config('email.domain');
        $addresses = array_filter($addresses, function ($addr) use ($domain) {
            return ! str_ends_with(strtolower($addr), '@'.strtolower($domain));
        });

        return array_unique(array_map('strtolower', $addresses));
    }

    private function createLink(EmailMessage $message, Model $linkable, string $type): void
    {
        EmailCrmLink::firstOrCreate(
            [
                'email_message_id' => $message->id,
                'linkable_type' => get_class($linkable),
                'linkable_id' => $linkable->id,
            ],
            [
                'link_type' => $type,
                'linked_by' => null,
            ]
        );
    }
}
