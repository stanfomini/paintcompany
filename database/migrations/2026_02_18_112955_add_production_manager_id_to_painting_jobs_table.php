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
        Schema::table('painting_jobs', function (Blueprint $table) {
            $table->foreignId('production_manager_id')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('painting_jobs', function (Blueprint $table) {
            $table->dropForeign(['production_manager_id']);
            $table->dropColumn('production_manager_id');
        });
    }
};
