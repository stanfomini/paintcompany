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
        Schema::table('prospector_contacts', function (Blueprint $table) {
            $table->foreignId('claimed_by_agent_id')->nullable()->after('has_unread_sms')
                ->constrained('prospector_agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prospector_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by_agent_id');
        });
    }
};
