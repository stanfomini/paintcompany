<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_w9_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('business_name')->nullable();
            $table->string('tax_classification'); // individual, c_corp, s_corp, partnership, trust, llc, other
            $table->string('llc_tax_classification')->nullable();
            $table->string('other_classification')->nullable();
            $table->string('exempt_payee_code')->nullable();
            $table->string('fatca_exemption_code')->nullable();
            $table->string('address');
            $table->string('city_state_zip');
            $table->string('requester_name')->nullable();
            $table->string('account_numbers')->nullable();
            $table->string('tin_type'); // ssn or ein
            $table->text('tin'); // encrypted at model level
            $table->text('signature_data');
            $table->string('signature_type'); // drawn or typed
            $table->timestamp('signed_at');
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_w9_forms');
    }
};
