<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('content');
            $table->string('pdf_path')->nullable();
            $table->json('field_values')->nullable();
            $table->string('status')->default('draft'); // draft, sent, viewed, signed, expired, voided
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('signature_data')->nullable();
            $table->string('signature_type')->nullable(); // drawn, typed
            $table->string('signer_name')->nullable();
            $table->string('signer_ip')->nullable();
            $table->string('signer_user_agent')->nullable();
            $table->foreignId('countersigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('countersigned_at')->nullable();
            $table->text('countersign_data')->nullable();
            $table->string('token')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }
};
