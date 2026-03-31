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
        Schema::table('prospector_campaign_contact', function (Blueprint $table) {
            $table->boolean('is_priority')->default(false)->after('status');
            $table->string('priority_reason')->nullable()->after('is_priority'); // inbound_call, unread_sms, voicemail, callback_due
            $table->foreignId('claimed_by_agent_id')->nullable()->after('priority_reason')
                ->constrained('prospector_agents')->nullOnDelete();
        });

        Schema::table('prospector_contacts', function (Blueprint $table) {
            $table->boolean('has_unread_sms')->default(false)->after('total_sms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospector_campaign_contact', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by_agent_id');
            $table->dropColumn(['is_priority', 'priority_reason']);
        });

        Schema::table('prospector_contacts', function (Blueprint $table) {
            $table->dropColumn('has_unread_sms');
        });
    }
};
