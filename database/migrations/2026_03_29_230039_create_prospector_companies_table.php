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
        Schema::create('prospector_companies', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('name');
            $table->foreignId('twilio_account_id')->constrained('prospector_twilio_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('main_phone_number_id')->nullable();
            $table->string('timezone')->default('America/Denver');
            $table->time('business_hours_start')->nullable();
            $table->time('business_hours_end')->nullable();
            $table->json('business_days')->nullable();
            $table->string('webhook_secret', 64);
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_companies');
    }
};
