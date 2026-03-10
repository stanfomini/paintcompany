<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messenger_settings', function (Blueprint $table) {
            $table->boolean('bot_test_mode')->default(false)->after('is_connected');
        });
    }

    public function down(): void
    {
        Schema::table('messenger_settings', function (Blueprint $table) {
            $table->dropColumn('bot_test_mode');
        });
    }
};
