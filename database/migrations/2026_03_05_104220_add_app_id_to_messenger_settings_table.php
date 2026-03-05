<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messenger_settings', function (Blueprint $table) {
            $table->string('app_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('messenger_settings', function (Blueprint $table) {
            $table->dropColumn('app_id');
        });
    }
};
