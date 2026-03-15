<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $activities = DB::table('activities')
            ->whereNotNull('meeting_type')
            ->whereIn('meeting_status', ['proposed', 'confirmed'])
            ->where('subject_type', 'App\\Models\\Deal')
            ->get();

        $slotDuration = DB::table('company_settings')->value('booking_slot_duration_minutes') ?? 60;

        foreach ($activities as $activity) {
            $deal = DB::table('deals')->find($activity->subject_id);
            if (! $deal) {
                continue;
            }

            $customer = $deal->customer_id ? DB::table('customers')->find($deal->customer_id) : null;

            if (! $activity->due_at) {
                continue;
            }

            $dueAt = Carbon::parse($activity->due_at);

            // Check if booking already exists for this deal+date to avoid duplicates
            $exists = DB::table('appointment_bookings')
                ->where('deal_id', $deal->id)
                ->where('booked_date', $dueAt->toDateString())
                ->where('booked_start_time', $dueAt->format('H:i:s'))
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('appointment_bookings')->insert([
                'customer_id' => $deal->customer_id,
                'deal_id' => $deal->id,
                'estimator_id' => $activity->assigned_to,
                'booked_date' => $dueAt->toDateString(),
                'booked_start_time' => $dueAt->format('H:i:s'),
                'booked_end_time' => $dueAt->copy()->addMinutes($slotDuration)->format('H:i:s'),
                'booked_timezone' => config('app.timezone'),
                'status' => 'confirmed',
                'prospect_first_name' => $customer->first_name ?? '',
                'prospect_last_name' => $customer->last_name ?? '',
                'prospect_email' => $customer->email ?? '',
                'prospect_phone' => $customer->phone ?? '',
                'prospect_address' => $customer->address ?? '',
                'estimate_preference' => $activity->meeting_type ?? 'in_person',
                'booked_by' => 'internal',
                'booking_token' => (string) Str::uuid(),
                'confirmed_at' => now(),
                'created_at' => $activity->created_at,
                'updated_at' => now(),
            ]);

            // Clean up activity — preserve record but remove from meeting queries
            DB::table('activities')
                ->where('id', $activity->id)
                ->update([
                    'type' => 'note',
                    'meeting_type' => null,
                    'meeting_status' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // One-way data migration — cannot be reversed
    }
};
