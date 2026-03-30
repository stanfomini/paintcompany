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
        Schema::create('prospector_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained('prospector_companies')->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('source')->nullable();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('phone', 20);
            $table->string('phone_2', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('timezone')->nullable();
            $table->json('tags')->nullable();
            $table->json('custom_fields')->nullable();
            $table->boolean('do_not_call')->default(false);
            $table->timestamp('last_contacted_at')->nullable();
            $table->string('last_disposition')->nullable();
            $table->unsignedInteger('total_calls')->default(0);
            $table->unsignedInteger('total_sms')->default(0);
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'phone']);
            $table->index(['company_id', 'do_not_call']);
            $table->index(['company_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_contacts');
    }
};
