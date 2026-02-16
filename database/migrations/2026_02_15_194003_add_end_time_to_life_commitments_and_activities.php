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
        Schema::table('life_commitments', function (Blueprint $table) {
            $table->string('recurrence_end_time', 5)->nullable()->after('recurrence_time');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->timestamp('ends_at')->nullable()->after('due_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('life_commitments', function (Blueprint $table) {
            $table->dropColumn('recurrence_end_time');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('ends_at');
        });
    }
};
