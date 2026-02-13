<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_signed_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('contract_type'); // colorado_compliance, contractor_agreement, background_check_consent
            $table->longText('contract_content');
            $table->text('signature_data');
            $table->string('signature_type'); // drawn or typed
            $table->string('signer_name');
            $table->string('signer_email');
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->unique(['user_id', 'contract_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_signed_contracts');
    }
};
