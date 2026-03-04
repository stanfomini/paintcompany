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
        Schema::create('color_visualizations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('estimate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->string('original_image_path');
            $table->string('result_image_path')->nullable();
            $table->text('prompt');
            $table->json('parsed_colors')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->string('ai_provider')->nullable();
            $table->string('disk')->default('photos');
            $table->string('lead_name')->nullable();
            $table->string('lead_email')->nullable();
            $table->string('lead_phone')->nullable();
            $table->text('lead_address')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('color_visualizations');
    }
};
