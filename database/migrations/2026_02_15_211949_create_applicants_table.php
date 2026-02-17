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
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_listing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('resume_path')->nullable();
            $table->longText('cover_letter')->nullable();
            $table->string('source')->default('web');
            $table->string('referral_source')->nullable();
            $table->string('stage')->default('applied');
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->tinyInteger('rating')->nullable();
            $table->timestamp('interview_date')->nullable();
            $table->decimal('offer_amount', 10, 2)->nullable();
            $table->string('offer_type')->nullable();
            $table->timestamp('hired_at')->nullable();
            $table->foreignId('converted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applicants');
    }
};
