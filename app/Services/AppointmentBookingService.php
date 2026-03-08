<?php

namespace App\Services;

use App\Events\NewLeadCreated;
use App\Exceptions\SlotUnavailableException;
use App\Models\AppointmentBooking;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Invitation;
use App\Models\Property;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\AppointmentBookedNotification;
use App\Notifications\AppointmentCancelledNotification;
use App\Notifications\InvitationNotification;
use App\Notifications\NewAppointmentNotification;
use Carbon\Carbon;

class AppointmentBookingService
{
    public function __construct(
        protected AppointmentAvailabilityService $availabilityService,
    ) {}

    /**
     * Book an appointment. Creates Customer, Property, Deal, and AppointmentBooking.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone?: string, address: string, city?: string, state?: string, zip?: string, description?: string, estimate_preference?: string}  $prospectData
     */
    public function bookAppointment(
        array $prospectData,
        int $estimatorId,
        Carbon $date,
        string $startTime,
        string $endTime,
        ?Vendor $vendor = null,
        string $bookedBy = 'prospect',
        ?string $sessionId = null,
    ): AppointmentBooking {
        // 1. Release any existing slot hold for this session BEFORE checking availability
        // (otherwise the hold itself blocks the slot we're trying to book)
        if ($sessionId) {
            $this->availabilityService->releaseHold($sessionId);
        }

        // 2. Validate slot availability
        if (! $this->availabilityService->isSlotAvailable($estimatorId, $date, $startTime, $endTime)) {
            throw new SlotUnavailableException;
        }

        // 3. Find or create Customer
        $customer = null;
        if (! empty($prospectData['email'])) {
            $customer = Customer::firstOrCreate(
                ['email' => $prospectData['email']],
                [
                    'first_name' => $prospectData['first_name'],
                    'last_name' => $prospectData['last_name'],
                    'phone' => $prospectData['phone'] ?? null,
                    'address' => $prospectData['address'],
                    'city' => $prospectData['city'] ?? null,
                    'state' => $prospectData['state'] ?? null,
                    'zip' => $prospectData['zip'] ?? null,
                    'source' => 'vendor_booking',
                    'status' => 'active',
                    'created_by' => $estimatorId,
                ]
            );

            app(\App\Services\CustomerDuplicateService::class)->checkAndFlagDuplicates($customer);
        }

        // 4. Find or create Property
        $property = Property::firstOrCreate(
            ['address' => $prospectData['address']],
            [
                'city' => $prospectData['city'] ?? null,
                'state' => $prospectData['state'] ?? null,
                'zip' => $prospectData['zip'] ?? null,
                'customer_id' => $customer?->id,
            ]
        );

        // 5. Create Deal
        $deal = Deal::create([
            'customer_id' => $customer?->id,
            'property_id' => $property->id,
            'title' => 'Vendor Booking - '.$prospectData['address'],
            'stage' => 'lead',
            'lead_source' => 'vendor_booking',
            'assigned_to' => $estimatorId,
            'created_by' => $estimatorId,
            'origin_vendor_id' => $vendor?->id,
            'expected_start_date' => $date->toDateString(),
        ]);

        // 5b. Create draft Estimate for the deal
        Estimate::create([
            'deal_id' => $deal->id,
            'created_by' => $estimatorId,
            'title' => $deal->title,
            'status' => 'draft',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        // 6. Create AppointmentBooking record
        $estimator = User::findOrFail($estimatorId);
        $booking = AppointmentBooking::create([
            'vendor_id' => $vendor?->id,
            'estimator_id' => $estimatorId,
            'deal_id' => $deal->id,
            'customer_id' => $customer?->id,
            'schedule_id' => null,
            'status' => AppointmentBooking::STATUS_CONFIRMED,
            'prospect_first_name' => $prospectData['first_name'],
            'prospect_last_name' => $prospectData['last_name'],
            'prospect_email' => $prospectData['email'],
            'prospect_phone' => $prospectData['phone'] ?? null,
            'prospect_address' => $prospectData['address'],
            'prospect_city' => $prospectData['city'] ?? null,
            'prospect_state' => $prospectData['state'] ?? null,
            'prospect_zip' => $prospectData['zip'] ?? null,
            'prospect_description' => $prospectData['description'] ?? null,
            'estimate_preference' => $prospectData['estimate_preference'] ?? 'in_person',
            'booked_date' => $date->toDateString(),
            'booked_start_time' => $startTime,
            'booked_end_time' => $endTime,
            'booked_timezone' => $this->getTimezone(),
            'confirmed_at' => now(),
            'booked_by' => $bookedBy,
        ]);

        // 7. Update deal with booking reference
        $deal->update(['appointment_booking_id' => $booking->id]);

        // 8. Fire new lead event for real-time pipeline updates
        NewLeadCreated::dispatch($deal);

        // 9. Send notifications
        if ($customer) {
            $customer->notify(new AppointmentBookedNotification($booking));
        }
        $estimator->notify(new NewAppointmentNotification($booking));

        // 10. Send customer invitation if applicable
        if (! empty($prospectData['email']) && $customer) {
            $this->sendCustomerInvitation($prospectData['email'], $customer, $deal);
        }

        return $booking;
    }

    /**
     * Cancel a booking by its token.
     */
    public function cancelBooking(string $token, ?string $reason = null): AppointmentBooking
    {
        $booking = AppointmentBooking::where('booking_token', $token)->firstOrFail();

        if ($booking->isCancelled()) {
            return $booking;
        }

        $booking->cancel($reason);

        // Notify estimator
        $estimator = User::find($booking->estimator_id);
        if ($estimator) {
            $estimator->notify(new AppointmentCancelledNotification($booking));
        }

        return $booking;
    }

    /**
     * Reschedule a booking to a new date/time.
     */
    public function rescheduleBooking(
        string $token,
        Carbon $newDate,
        string $newStartTime,
        string $newEndTime,
        int $estimatorId,
    ): AppointmentBooking {
        $oldBooking = AppointmentBooking::where('booking_token', $token)->firstOrFail();

        // Mark old booking as rescheduled
        $oldBooking->markRescheduled();

        // Create a new booking with same prospect data
        return $this->bookAppointment(
            [
                'first_name' => $oldBooking->prospect_first_name,
                'last_name' => $oldBooking->prospect_last_name,
                'email' => $oldBooking->prospect_email,
                'phone' => $oldBooking->prospect_phone,
                'address' => $oldBooking->prospect_address,
                'city' => $oldBooking->prospect_city,
                'state' => $oldBooking->prospect_state,
                'zip' => $oldBooking->prospect_zip,
                'description' => $oldBooking->prospect_description,
                'estimate_preference' => $oldBooking->estimate_preference,
            ],
            $estimatorId,
            $newDate,
            $newStartTime,
            $newEndTime,
            $oldBooking->vendor,
            $oldBooking->booked_by,
        );
    }

    /**
     * Mark a booking as completed.
     */
    public function markCompleted(AppointmentBooking $booking): void
    {
        $booking->markCompleted();
    }

    /**
     * Mark a booking as no-show.
     */
    public function markNoShow(AppointmentBooking $booking): void
    {
        $booking->markNoShow();
    }

    /**
     * Send a customer invitation email.
     */
    protected function sendCustomerInvitation(string $email, Customer $customer, Deal $deal): void
    {
        if (User::where('email', $email)->exists()) {
            return;
        }

        if (Invitation::forEmail($email)->pending()->exists()) {
            return;
        }

        $invitation = Invitation::create([
            'email' => $email,
            'invited_user_type' => 'customer',
            'inviter_id' => $deal->assigned_to,
            'deal_id' => $deal->id,
        ]);

        $invitation->notify(new InvitationNotification($invitation));
    }

    /**
     * Get the configured timezone.
     */
    protected function getTimezone(): string
    {
        return CompanySetting::getInstance()->timezone ?? 'America/New_York';
    }
}
