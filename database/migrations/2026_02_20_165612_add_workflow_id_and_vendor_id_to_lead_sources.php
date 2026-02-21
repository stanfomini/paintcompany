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
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->foreignId('workflow_id')->nullable()->constrained('workflows')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_id');
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
