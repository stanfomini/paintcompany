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
        Schema::table('prospector_companies', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('is_active');
            $table->foreignId('default_campaign_id')->nullable()->after('notes')
                ->constrained('prospector_campaigns')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospector_companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_campaign_id');
            $table->dropColumn('notes');
        });
    }
};
