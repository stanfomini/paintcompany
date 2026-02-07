<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('destination_name');
            $table->string('destination_type', 20);
            $table->string('destination_account_id');
            $table->decimal('amount', 10, 2);
            $table->decimal('percentage', 5, 2);
            $table->string('status', 20)->default('pending');
            $table->string('transfer_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_splits');
    }
};
