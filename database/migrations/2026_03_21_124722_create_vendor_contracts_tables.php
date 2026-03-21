<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('contract_templates')->nullOnDelete();
            $table->string('title');
            $table->longText('content');
            $table->string('status')->default('draft');
            $table->boolean('is_valid')->default(true);
            $table->text('notes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('expiry_warning_sent_at')->nullable();
            $table->foreignId('renewed_from_id')->nullable()->constrained('vendor_contracts')->nullOnDelete();
            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamp('public_token_expires_at')->nullable();
            $table->string('executed_pdf_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('vendor_contract_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('order')->default(0);
            $table->timestamp('signed_at')->nullable();
            $table->text('signature_data')->nullable();
            $table->string('signature_type')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->text('signer_user_agent')->nullable();
            $table->timestamps();

            $table->unique(['vendor_contract_id', 'user_id']);
        });

        Schema::create('vendor_contract_vendor_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_contract_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('signer_name');
            $table->string('signer_email');
            $table->text('signature_data');
            $table->string('signature_type');
            $table->string('signer_ip', 45)->nullable();
            $table->text('signer_user_agent')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();
        });

        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->foreignId('vendor_contract_id')->nullable()->after('vendor_id')
                ->constrained('vendor_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_contract_id');
        });

        Schema::dropIfExists('vendor_contract_vendor_signatures');
        Schema::dropIfExists('vendor_contract_signers');
        Schema::dropIfExists('vendor_contracts');
    }
};
