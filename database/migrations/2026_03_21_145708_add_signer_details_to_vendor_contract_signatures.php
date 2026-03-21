<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add title, phone, email to vendor signatures
        Schema::table('vendor_contract_vendor_signatures', function (Blueprint $table) {
            $table->string('signer_title')->nullable()->after('signer_email');
            $table->string('signer_phone')->nullable()->after('signer_title');
        });

        // Add title, phone, email to internal signer signatures
        Schema::table('vendor_contract_signers', function (Blueprint $table) {
            $table->string('signer_title')->nullable()->after('signature_type');
            $table->string('signer_phone')->nullable()->after('signer_title');
            $table->string('signer_email')->nullable()->after('signer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_contract_vendor_signatures', function (Blueprint $table) {
            $table->dropColumn(['signer_title', 'signer_phone']);
        });

        Schema::table('vendor_contract_signers', function (Blueprint $table) {
            $table->dropColumn(['signer_title', 'signer_phone', 'signer_email']);
        });
    }
};
