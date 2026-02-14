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
        Schema::table('ai_build_requests', function (Blueprint $table) {
            $table->dropForeign(['estimate_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_build_requests', function (Blueprint $table) {
            $table->foreign('estimate_id')->references('id')->on('estimates')->cascadeOnDelete();
        });
    }
};
