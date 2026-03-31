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
        Schema::table('prospector_contacts', function (Blueprint $table) {
            $table->foreignId('pipeline_stage_id')->nullable()->after('priority')
                ->constrained('prospector_pipeline_stages')->nullOnDelete();
            $table->datetime('follow_up_at')->nullable()->after('pipeline_stage_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospector_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pipeline_stage_id');
            $table->dropColumn('follow_up_at');
        });
    }
};
