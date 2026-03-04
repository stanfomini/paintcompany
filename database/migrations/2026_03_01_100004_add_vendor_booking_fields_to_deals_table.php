<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('origin_vendor_id')->nullable()->after('origin_door_knock_id')->constrained('vendors')->nullOnDelete();
            $table->foreignId('appointment_booking_id')->nullable()->after('origin_vendor_id')->constrained('appointment_bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_vendor_id');
            $table->dropConstrainedForeignId('appointment_booking_id');
        });
    }
};
