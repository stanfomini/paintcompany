<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messenger_bot_flows', function (Blueprint $table) {
            $table->string('trigger_keyword')->nullable()->after('status');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('messenger_bot_flows', function (Blueprint $table) {
                $table->unique('trigger_keyword');
            });
        }
    }

    public function down(): void
    {
        Schema::table('messenger_bot_flows', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropUnique(['trigger_keyword']);
            }
            $table->dropColumn('trigger_keyword');
        });
    }
};
