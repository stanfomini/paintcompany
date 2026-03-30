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
        Schema::create('prospector_rvm_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('prospector_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('audio_path');
            $table->unsignedInteger('duration_seconds');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_rvm_templates');
    }
};
