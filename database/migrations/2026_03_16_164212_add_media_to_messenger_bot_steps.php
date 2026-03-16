<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messenger_bot_steps', function (Blueprint $table) {
            $table->string('media_url')->nullable()->after('message_text');
        });
    }

    public function down(): void
    {
        Schema::table('messenger_bot_steps', function (Blueprint $table) {
            $table->dropColumn('media_url');
        });
    }
};
