<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_previews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('public_token', 64)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('estimate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope_type')->nullable();
            $table->json('rooms_data')->nullable();
            $table->string('timeline')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('estimator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('active');
            $table->integer('portal_views_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
            $table->index('phone');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_previews');
    }
};
