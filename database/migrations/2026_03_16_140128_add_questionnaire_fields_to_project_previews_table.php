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
        Schema::table('project_previews', function (Blueprint $table) {
            $table->json('questionnaire_data')->nullable()->after('notes');
            $table->timestamp('questionnaire_completed_at')->nullable()->after('questionnaire_data');
            $table->decimal('questionnaire_discount_percent', 5, 2)->nullable()->after('questionnaire_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_previews', function (Blueprint $table) {
            $table->dropColumn(['questionnaire_data', 'questionnaire_completed_at', 'questionnaire_discount_percent']);
        });
    }
};
