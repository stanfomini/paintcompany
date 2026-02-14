<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->uuid('token')->unique();
            $table->boolean('is_active')->default(true);
            $table->string('auth_type', 20)->default('none'); // none, api_key, bearer
            $table->string('auth_header', 255)->nullable();
            $table->text('auth_value')->nullable();
            $table->json('last_payload')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedInteger('trigger_count')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_webhooks');
    }
};
