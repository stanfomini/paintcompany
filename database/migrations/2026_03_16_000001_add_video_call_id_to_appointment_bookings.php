<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_bookings', function (Blueprint $table) {
            $table->foreignId('video_call_id')->nullable()->after('override_note')->constrained('video_calls')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_call_id');
        });
    }
};
