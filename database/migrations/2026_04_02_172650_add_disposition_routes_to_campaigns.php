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
        Schema::table('prospector_campaigns', function (Blueprint $table) {
            $table->json('disposition_routes')->nullable()->after('auto_dial');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospector_campaigns', function (Blueprint $table) {
            $table->dropColumn('disposition_routes');
        });
    }
};
