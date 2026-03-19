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
        Schema::table('call_logs', function (Blueprint $table) {
            $table->foreignId('voicemail_for_user_id')->nullable()->after('answered_by_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('call_group_id')->nullable()->after('voicemail_for_user_id')->constrained('call_groups')->nullOnDelete();
            $table->foreignId('follow_up_activity_id')->nullable()->after('call_group_id')->constrained('activities')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voicemail_for_user_id');
            $table->dropConstrainedForeignId('call_group_id');
            $table->dropConstrainedForeignId('follow_up_activity_id');
        });
    }
};
