<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prospector_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('twilio_account_id')->constrained('prospector_twilio_accounts')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('prospector_companies')->nullOnDelete();
            $table->string('phone_number', 20);
            $table->string('friendly_name')->nullable();
            $table->string('area_code', 3);
            $table->string('twilio_sid');
            $table->string('role')->default('unassigned');
            $table->string('caller_id_override', 20)->nullable();
            $table->json('capabilities')->nullable();
            $table->string('inbound_action')->default('forward_main');
            $table->string('inbound_forward_to', 20)->nullable();
            $table->decimal('monthly_cost', 8, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('phone_number');
            $table->index(['company_id', 'role']);
            $table->index(['company_id', 'area_code']);
            $table->index('twilio_account_id');
        });

        // Add FK from companies.main_phone_number_id to phone_numbers
        Schema::table('prospector_companies', function (Blueprint $table) {
            $table->foreign('main_phone_number_id')
                ->references('id')
                ->on('prospector_phone_numbers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospector_companies', function (Blueprint $table) {
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign(['main_phone_number_id']);
            }
        });

        Schema::dropIfExists('prospector_phone_numbers');
    }
};
