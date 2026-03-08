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
            $table->json('fb_profile_data')->nullable()->after('facebook_psid');
        });
    }

    public function down(): void
    {
        Schema::table('messenger_conversations', function (Blueprint $table) {
            $table->dropColumn('fb_profile_data');
        });
    }
};
