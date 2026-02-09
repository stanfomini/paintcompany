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
        Schema::create('estimate_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amended_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('amendment_number');
            $table->json('changes');
            $table->decimal('previous_total', 12, 2);
            $table->decimal('new_total', 12, 2);
            $table->datetime('customer_acknowledged_at')->nullable();
            $table->string('customer_initials', 10)->nullable();
            $table->string('customer_ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('estimate_id');
            $table->unique(['estimate_id', 'amendment_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_amendments');
    }
};
