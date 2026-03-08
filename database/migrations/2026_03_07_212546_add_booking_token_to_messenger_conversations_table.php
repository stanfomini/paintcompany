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
        Schema::table('messenger_conversations', function (Blueprint $table) {
            $table->string('booking_token', 64)->nullable()->unique()->after('deal_id');
            $table->timestamp('booking_token_expires_at')->nullable()->after('booking_token');
        });
    }

    public function down(): void
    {
        Schema::table('messenger_conversations', function (Blueprint $table) {
            $table->dropColumn(['booking_token', 'booking_token_expires_at']);
        });
    }
};
