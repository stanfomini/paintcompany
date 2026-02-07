<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('life_commitments', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('sort_order');
            $table->date('start_date')->nullable()->after('is_active');
            $table->tinyInteger('recurrence_day')->nullable()->after('start_date');
            $table->tinyInteger('recurrence_day_of_month')->nullable()->after('recurrence_day');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('calendar_token')->nullable()->unique()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('life_commitments', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'start_date', 'recurrence_day', 'recurrence_day_of_month']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('calendar_token');
        });
    }
};
