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
        Schema::create('customer_duplicate_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('duplicate_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('duplicate_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('match_type'); // email, phone, name
            $table->string('status')->default('pending'); // pending, merged, dismissed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['duplicate_customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_duplicate_flags');
    }
};
