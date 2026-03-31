<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospector_twilio_accounts', function (Blueprint $table) {
            $table->text('api_key_sid')->nullable()->after('auth_token');
            $table->text('api_key_secret')->nullable()->after('api_key_sid');
            $table->text('twiml_app_sid')->nullable()->after('api_key_secret');
        });
    }

    public function down(): void
    {
        Schema::table('prospector_twilio_accounts', function (Blueprint $table) {
            $table->dropColumn(['api_key_sid', 'api_key_secret', 'twiml_app_sid']);
        });
    }
};
