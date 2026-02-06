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
        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedInteger('default_required_crew_size')->default(2)->after('wood_replacement_minimum');
            $table->unsignedInteger('total_painters')->default(4)->after('default_required_crew_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['default_required_crew_size', 'total_painters']);
        });
    }
};
