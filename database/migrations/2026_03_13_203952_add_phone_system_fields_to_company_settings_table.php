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
        Schema::table('company_settings', function (Blueprint $table) {
            // Phone System
            $table->boolean('phone_system_enabled')->default(false);
            $table->string('phone_routing_mode')->default('ring_all');
            $table->foreignId('default_call_group_id')->nullable()->constrained('call_groups')->nullOnDelete();
            $table->foreignId('after_hours_call_group_id')->nullable()->constrained('call_groups')->nullOnDelete();
            $table->boolean('call_recording_enabled')->default(false);

            // Voicemail
            $table->string('voicemail_greeting_type')->default('tts');
            $table->text('voicemail_greeting_text')->nullable();
            $table->string('voicemail_greeting_path')->nullable();
            $table->integer('voicemail_timeout_seconds')->default(25);

            // Business Hours (for phone routing)
            $table->time('phone_business_hours_start')->nullable();
            $table->time('phone_business_hours_end')->nullable();
            $table->json('phone_business_days')->nullable();

            // IVR
            $table->boolean('ivr_enabled')->default(false);
            $table->json('ivr_menu')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropForeign(['default_call_group_id']);
            $table->dropForeign(['after_hours_call_group_id']);
            $table->dropColumn([
                'phone_system_enabled',
                'phone_routing_mode',
                'default_call_group_id',
                'after_hours_call_group_id',
                'call_recording_enabled',
                'voicemail_greeting_type',
                'voicemail_greeting_text',
                'voicemail_greeting_path',
                'voicemail_timeout_seconds',
                'phone_business_hours_start',
                'phone_business_hours_end',
                'phone_business_days',
                'ivr_enabled',
                'ivr_menu',
            ]);
        });
    }
};
