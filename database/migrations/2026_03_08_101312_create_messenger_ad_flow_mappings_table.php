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
        Schema::create('messenger_ad_flow_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('facebook_ad_id')->unique()->index();
            $table->foreignId('flow_id')->nullable()->constrained('messenger_bot_flows')->nullOnDelete();
            $table->string('facebook_ad_name')->nullable();
            $table->string('facebook_campaign_name')->nullable();
            $table->string('facebook_adset_name')->nullable();
            $table->string('facebook_ad_status')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messenger_ad_flow_mappings');
    }
};
