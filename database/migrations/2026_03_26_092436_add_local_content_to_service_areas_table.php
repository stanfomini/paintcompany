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
        Schema::table('service_areas', function (Blueprint $table) {
            $table->json('local_content')->nullable()->after('specialties_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->dropColumn('local_content');
        });
    }
};
