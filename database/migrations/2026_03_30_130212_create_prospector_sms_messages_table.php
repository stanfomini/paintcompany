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
        Schema::create('prospector_sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('prospector_companies')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('prospector_contacts')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('prospector_agents')->nullOnDelete();
            $table->string('direction'); // outbound, inbound
            $table->string('from_number', 20);
            $table->string('to_number', 20);
            $table->text('body');
            $table->json('media_urls')->nullable(); // MMS
            $table->string('twilio_sid')->nullable();
            $table->string('status')->default('queued'); // queued, sent, delivered, failed, received
            $table->timestamps();

            $table->index(['company_id', 'contact_id']);
            $table->index('twilio_sid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_sms_messages');
    }
};
