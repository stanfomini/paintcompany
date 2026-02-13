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
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_source_id')->constrained()->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->boolean('is_active')->default(false);
            $table->string('auth_type', 20)->default('none');
            $table->string('auth_header', 255)->nullable();
            $table->text('auth_value')->nullable();
            $table->string('content_type', 20)->default('json');
            $table->json('sample_payload')->nullable();
            $table->timestamp('last_payload_at')->nullable();
            $table->unsignedInteger('total_leads')->default(0);
            $table->foreignId('default_assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
