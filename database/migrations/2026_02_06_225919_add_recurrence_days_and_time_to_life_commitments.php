<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('life_commitments', function (Blueprint $table) {
            $table->json('recurrence_days')->nullable()->after('start_date');
            $table->string('recurrence_time', 5)->nullable()->after('recurrence_days');
        });

        // Migrate existing recurrence_day (single int) to recurrence_days (JSON array)
        DB::table('life_commitments')
            ->whereNotNull('recurrence_day')
            ->eachById(function ($row) {
                DB::table('life_commitments')
                    ->where('id', $row->id)
                    ->update(['recurrence_days' => json_encode([(int) $row->recurrence_day])]);
            });

        Schema::table('life_commitments', function (Blueprint $table) {
            $table->dropColumn('recurrence_day');
        });
    }

    public function down(): void
    {
        Schema::table('life_commitments', function (Blueprint $table) {
            $table->tinyInteger('recurrence_day')->nullable()->after('start_date');
        });

        // Migrate back: take first day from JSON array
        DB::table('life_commitments')
            ->whereNotNull('recurrence_days')
            ->eachById(function ($row) {
                $days = json_decode($row->recurrence_days, true);
                DB::table('life_commitments')
                    ->where('id', $row->id)
                    ->update(['recurrence_day' => $days[0] ?? null]);
            });

        Schema::table('life_commitments', function (Blueprint $table) {
            $table->dropColumn(['recurrence_days', 'recurrence_time']);
        });
    }
};
