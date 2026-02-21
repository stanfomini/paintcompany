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
            Schema::table('schedules', function (Blueprint $table) {
                $table->foreignId('employee_profile_id')->nullable()->constrained()->cascadeOnDelete()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('schedules', function (Blueprint $table) {
                $table->foreignId('employee_profile_id')->nullable(false)->constrained()->cascadeOnDelete()->change();
            });
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('is_flex');
        });
    }
};
