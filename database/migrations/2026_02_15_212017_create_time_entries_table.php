<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('painting_jobs')->nullOnDelete();

            // Clock in/out
            $table->timestamp('clock_in');
            $table->timestamp('clock_out')->nullable();

            // GPS coordinates
            $table->decimal('clock_in_lat', 10, 7)->nullable();
            $table->decimal('clock_in_lng', 10, 7)->nullable();
            $table->decimal('clock_out_lat', 10, 7)->nullable();
            $table->decimal('clock_out_lng', 10, 7)->nullable();

            // Reverse geocoded addresses
            $table->string('clock_in_address')->nullable();
            $table->string('clock_out_address')->nullable();

            // Geofence status
            $table->string('clock_in_geofence_status')->nullable();
            $table->string('clock_out_geofence_status')->nullable();
            $table->integer('geofence_distance_ft')->nullable();

            // Break tracking
            $table->timestamp('break_start')->nullable();
            $table->timestamp('break_end')->nullable();
            $table->integer('break_minutes')->default(0);

            // Hours
            $table->decimal('total_hours', 8, 2)->nullable();
            $table->decimal('overtime_hours', 8, 2)->nullable();

            // Status & approval
            $table->string('status')->default('active');
            $table->text('dispute_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Edit tracking
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('original_clock_in')->nullable();
            $table->timestamp('original_clock_out')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['employee_profile_id', 'clock_in']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
