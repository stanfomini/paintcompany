<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('booking_slug')->unique()->nullable()->after('notes');
            $table->boolean('booking_enabled')->default(false)->after('booking_slug');
            $table->string('booking_display_name')->nullable()->after('booking_enabled');
            $table->text('booking_bio')->nullable()->after('booking_display_name');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['booking_slug', 'booking_enabled', 'booking_display_name', 'booking_bio']);
        });
    }
};
