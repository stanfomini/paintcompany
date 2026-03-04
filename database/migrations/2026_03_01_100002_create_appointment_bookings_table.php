<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('estimator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('confirmed');

            // Prospect info snapshot
            $table->string('prospect_first_name');
            $table->string('prospect_last_name');
            $table->string('prospect_email');
            $table->string('prospect_phone')->nullable();
            $table->string('prospect_address');
            $table->string('prospect_city')->nullable();
            $table->string('prospect_state')->nullable();
            $table->string('prospect_zip')->nullable();
            $table->text('prospect_description')->nullable();
            $table->string('estimate_preference')->default('in_person');

            // Booking details
            $table->date('booked_date');
            $table->time('booked_start_time');
            $table->time('booked_end_time');
            $table->string('booked_timezone')->default('America/New_York');
            $table->string('booking_token')->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('booked_by')->default('prospect');
            $table->timestamp('reminder_sent_at')->nullable();

            $table->timestamps();

            $table->index(['booked_date', 'estimator_id']);
            $table->index(['vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_bookings');
    }
};
