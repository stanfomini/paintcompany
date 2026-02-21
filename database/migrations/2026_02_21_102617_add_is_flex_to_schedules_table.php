<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->boolean('is_flex')->default(false)->after('schedule_type');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `schedules` MODIFY `employee_profile_id` BIGINT UNSIGNED NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `schedules` MODIFY `employee_profile_id` BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('is_flex');
        });
    }
};
