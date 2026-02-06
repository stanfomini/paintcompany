<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates warranty reserve tracking for jobs.
     * When a customer signs a package with a warranty, a reserve is created
     * to set aside funds for potential future warranty claims.
     */
    public function up(): void
    {
        Schema::create('warranty_reserves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->foreignId('estimate_package_id')->constrained('estimate_packages')->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('painting_jobs')->nullOnDelete();
            $table->integer('warranty_years');
            $table->decimal('amount_reserved', 10, 2);      // Amount set aside for warranty
            $table->decimal('amount_claimed', 10, 2)->default(0);  // Amount already used
            $table->date('warranty_starts_at');
            $table->date('warranty_expires_at');
            $table->enum('status', ['pending', 'active', 'expired', 'fully_claimed'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Index for finding active warranties
            $table->index(['status', 'warranty_expires_at']);
            $table->index('estimate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warranty_reserves');
    }
};
