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
        Schema::table('messenger_settings', function (Blueprint $table) {
            $table->string('ad_account_id')->nullable()->after('page_id');
        });
    }

    public function down(): void
    {
        Schema::table('messenger_settings', function (Blueprint $table) {
            $table->dropColumn('ad_account_id');
        });
    }
};
