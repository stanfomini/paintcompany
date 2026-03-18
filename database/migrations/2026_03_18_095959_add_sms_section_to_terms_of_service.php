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
            '18. TEXT MESSAGING AND COMMUNICATIONS',
            '',
            'By providing your phone number and agreeing to service message consent on our forms, you authorize '.config('app.name').' to send automated text messages to that number for service-related purposes (appointment confirmations, estimate delivery, invoices, project updates).',
            '',
            'Marketing text messages require separate opt-in and are never a condition of service.',
            '',
            'To stop receiving text messages, reply STOP at any time. For help, reply HELP. Message and data rates may apply. Message frequency varies.',
            '',
            'This section supplements and does not replace any consent given via our website forms.',
        ]);

        DB::table('company_settings')
            ->whereNotNull('terms_and_conditions')
            ->where('terms_and_conditions', 'not like', '%TEXT MESSAGING AND COMMUNICATIONS%')
            ->orderBy('id')
            ->each(function ($setting) use ($smsSection) {
                DB::table('company_settings')
                    ->where('id', $setting->id)
                    ->update(['terms_and_conditions' => $setting->terms_and_conditions."\n\n".$smsSection]);
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
