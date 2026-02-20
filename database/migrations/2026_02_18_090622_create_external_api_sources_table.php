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
        Schema::create('external_api_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->uuid('token')->unique();
            $table->text('url');
            $table->string('http_method', 10)->default('GET');
            $table->boolean('is_active')->default(true);
            $table->string('auth_type', 20)->default('none');
            $table->string('auth_header')->nullable();
            $table->text('auth_value')->nullable();
            $table->json('custom_headers')->nullable();
            $table->string('poll_interval', 20)->default('every_5_min');
            $table->string('data_path')->nullable();
            $table->string('dedup_field')->nullable();
            $table->json('last_response')->nullable();
            $table->string('last_response_hash')->nullable();
            $table->json('processed_ids')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedInteger('poll_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_api_sources');
    }
};
