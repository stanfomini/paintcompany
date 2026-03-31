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
        Schema::create('prospector_messaging_services', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('twilio_account_id')->constrained('prospector_twilio_accounts')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('prospector_companies')->nullOnDelete();

            // Twilio Messaging Service
            $table->string('twilio_sid')->nullable()->unique();
            $table->string('friendly_name');
            $table->string('status')->default('pending'); // pending, active, suspended

            // Brand Registration
            $table->string('brand_sid')->nullable();
            $table->string('brand_status')->nullable(); // PENDING, IN_REVIEW, APPROVED, FAILED
            $table->json('brand_details')->nullable(); // business name, EIN, type, etc.

            // Campaign Registration
            $table->string('campaign_sid')->nullable();
            $table->string('campaign_status')->nullable(); // IN_PROGRESS, VERIFIED, FAILED
            $table->string('campaign_use_case')->nullable(); // MARKETING, MIXED, LOW_VOLUME, etc.
            $table->json('campaign_details')->nullable(); // description, message samples, etc.

            // Number count
            $table->integer('phone_number_count')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_messaging_services');
    }
};
