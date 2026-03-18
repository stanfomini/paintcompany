<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $smsSection = implode("\n", [
            '12. TEXT MESSAGING AND SMS COMMUNICATIONS',
            '',
            'By providing your phone number on our website forms, you consent to receive service-related text messages from '.config('app.name').', including appointment confirmations and reminders, estimate notifications, invoice alerts, and project updates.',
            '',
            'If you separately opt in to marketing communications, you may also receive promotional messages, seasonal offers, and painting tips.',
            '',
            'No mobile information will be shared with third parties or affiliates for marketing or promotional purposes. Text messaging opt-in data and consent will not be sold or shared with any third parties.',
            '',
            'Message frequency varies. Message and data rates may apply.',
            '',
            'To opt out of text messages at any time, reply STOP. For help, reply HELP. Carriers are not liable for delayed or undelivered messages.',
        ]);

        DB::table('company_settings')
            ->whereNotNull('privacy_policy')
            ->where('privacy_policy', 'not like', '%TEXT MESSAGING AND SMS%')
            ->orderBy('id')
            ->each(function ($setting) use ($smsSection) {
                DB::table('company_settings')
                    ->where('id', $setting->id)
                    ->update(['privacy_policy' => $setting->privacy_policy."\n\n".$smsSection]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot safely undo text append
    }
};
