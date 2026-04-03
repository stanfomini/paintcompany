<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospector_companies', function (Blueprint $table) {
            $table->foreignId('default_rvm_template_id')
                ->nullable()
                ->after('default_campaign_id')
                ->constrained('prospector_rvm_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prospector_companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_rvm_template_id');
        });
    }
};
