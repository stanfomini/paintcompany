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
        Schema::table('job_cost_cards', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('is_manual');
            $table->foreignId('estimate_id')->nullable()->after('job_id')->constrained('estimates')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_cost_cards', function (Blueprint $table) {
            $table->dropForeign(['estimate_id']);
            $table->dropColumn(['status', 'estimate_id']);
        });
    }
};
