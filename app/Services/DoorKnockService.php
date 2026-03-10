<?php

namespace App\Services;

use App\Events\Automation\DoorKnockRecorded;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\Invitation;
use App\Models\LeadSource;
use App\Models\Property;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Database\Eloquent\Collection;

class DoorKnockService
{
    /**
     * Record a door knock. Auto-resolves territory via containsPoint().
     */
    public function recordKnock(User $user, array $data): DoorKnock
    {
        // Auto-resolve territory if not provided
        if (empty($data['territory_id']) && isset($data['lat'], $data['lng'])) {
            $territory = SalesTerritory::active()->get()->first(
                fn ($t) => $t->containsPoint((float) $data['lat'], (float) $data['lng'])
            );
            $data['territory_id'] = $territory?->id;
        }

        // Auto-match existing property by address
        if (empty($data['property_id']) && ! empty($data['address'])) {
            $property = Property::where('address', $data['address'])->first();
            if ($property) {
                $data['property_id'] = $property->id;
                $data['customer_id'] = $data['customer_id'] ?? $property->customer_id;
            }
        }

        $data['user_id'] = $user->id;
        $data['knocked_at'] = $data['knocked_at'] ?? now();

        $knock = DoorKnock::create($data);

        // Dispatch automation event when knock has customer contact info
        if (! empty($data['customer_name']) || ! empty($data['email']) || ! empty($data['phone'])) {
            DoorKnockRecorded::dispatch($knock);
        }

        return $knock;
    }

    /**
     * Convert a door knock to a CRM lead (Deal + Customer + Property).
     *
     * Rules:
     * - Name + Phone (no email): Create Customer record, no invitation
     * - Name + Phone + Email: Create Customer + send invitation email
     * - Email only (or email + name): Create Customer + send invitation email
     * - No contact info: Just create Property + Deal (no customer)
     */
    public function convertToLead(DoorKnock $knock): Deal
    {
        $hasName = ! empty($knock->customer_name);
        $hasPhone = ! empty($knock->phone);
        $hasEmail = ! empty($knock->email);

        // Find or create property
        $property = Property::firstOrCreate(
            ['address' => $knock->address],
            [
                'city' => $knock->city,
                'state' => $knock->state,
                'zip' => $knock->zip,
                'latitude' => $knock->lat,
                'longitude' => $knock->lng,
            ]
        );

        // Get or create a personal LeadSource for this door-to-door rep
        $personalLeadSource = LeadSource::firstOrCreate(
            ['user_id' => $knock->user_id],
            [
                'name' => User::find($knock->user_id)?->name.' (Door Knock)',
                'slug' => 'user-'.$knock->user_id,
                'type' => 'employee',
                'is_active' => true,
                'is_system' => false,
            ]
        );

        // Use dedup service if we have contact info
        $customer = null;
        $dealWasExisting = false;

        if ($hasName || $hasEmail || $hasPhone) {
            $nameParts = explode(' ', $knock->customer_name ?? 'Unknown', 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';

            $dedup = app(LeadDeduplicationService::class);
            $result = $dedup->resolveOrCreate(
                customerData: [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $knock->phone,
                    'email' => $knock->email,
                    'address' => $knock->address,
                    'city' => $knock->city,
                    'state' => $knock->state,
                    'zip' => $knock->zip,
                    'source' => 'door_knock',
                    'status' => 'active',
                    'created_by' => $knock->user_id,
                ],
                dealData: [
                    'property_id' => $property->id,
                    'title' => 'Door Knock Lead - '.$knock->address,
                    'stage' => 'lead',
                    'lead_source' => 'door_knock',
                    'lead_source_id' => $personalLeadSource->id,
                    'lead_generator_id' => $knock->user_id,
                    'assigned_to' => $knock->user_id,
                    'origin_door_knock_id' => $knock->id,
                    'created_by' => $knock->user_id,
                ],
                options: [
                    'source_label' => 'Door Knock',
                ],
            );

            $customer = $result->customer;
            $deal = $result->deal;
            $dealWasExisting = $result->dealWasExisting;

            // Link property to customer if not set
            if (! $property->customer_id) {
                $property->update(['customer_id' => $customer->id]);
            }
        } else {
            // No contact info — just create property + deal (no customer)
            $deal = Deal::create([
                'property_id' => $property->id,
                'title' => 'Door Knock Lead - '.$knock->address,
                'stage' => 'lead',
                'lead_source' => 'door_knock',
                'lead_source_id' => $personalLeadSource->id,
                'lead_generator_id' => $knock->user_id,
                'assigned_to' => $knock->user_id,
                'origin_door_knock_id' => $knock->id,
                'created_by' => $knock->user_id,
            ]);
        }

        // Update knock with links
        $knock->update([
            'deal_id' => $deal->id,
            'customer_id' => $customer?->id,
            'property_id' => $property->id,
        ]);

        // Send invitation if we have an email, not a reused deal, and no existing user account
        if (! $dealWasExisting && $hasEmail && $customer) {
            $this->sendCustomerInvitation($knock, $customer, $deal);
        }

        return $deal;
    }

    /**
     * Send a customer invitation email for a door knock lead.
     * Only sends if no existing user account or pending invitation exists.
     */
    private function sendCustomerInvitation(DoorKnock $knock, Customer $customer, Deal $deal): void
    {
        // Skip if user account already exists for this email
        if (User::where('email', $knock->email)->exists()) {
            return;
        }

        // Skip if there's already a pending invitation
        if (Invitation::forEmail($knock->email)->pending()->exists()) {
            return;
        }

        $invitation = Invitation::create([
            'email' => $knock->email,
            'invited_user_type' => 'customer',
            'inviter_id' => $knock->user_id,
            'deal_id' => $deal->id,
        ]);

        $invitation->notify(new InvitationNotification($invitation));
    }

    /**
     * Get knocks within a map viewport bounding box.
     */
    public function getKnocksInViewport(
        float $neLat,
        float $neLng,
        float $swLat,
        float $swLng,
        ?int $territoryId = null
    ): Collection {
        return DoorKnock::query()
            ->inBounds($neLat, $neLng, $swLat, $swLng)
            ->when($territoryId, fn ($q) => $q->where('territory_id', $territoryId))
            ->with(['user:id,name', 'territory:id,name,color'])
            ->latest('knocked_at')
            ->limit(500)
            ->get();
    }
}
