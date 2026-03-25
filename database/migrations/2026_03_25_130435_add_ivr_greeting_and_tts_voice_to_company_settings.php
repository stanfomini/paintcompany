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
            $table->string('tts_voice')->default('Polly.Joanna')->after('ivr_menu');
            $table->string('ivr_greeting_type')->default('auto')->after('tts_voice');
            $table->text('ivr_greeting_text')->nullable()->after('ivr_greeting_type');
            $table->string('ivr_greeting_path')->nullable()->after('ivr_greeting_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['tts_voice', 'ivr_greeting_type', 'ivr_greeting_text', 'ivr_greeting_path']);
        });
    }
};
