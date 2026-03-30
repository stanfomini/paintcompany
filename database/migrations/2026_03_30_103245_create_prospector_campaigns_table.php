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
        Schema::create('prospector_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained('prospector_companies')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('phone_number_id')->nullable()->constrained('prospector_phone_numbers')->nullOnDelete();
            $table->boolean('use_local_presence')->default(true);
            $table->string('dialer_mode')->default('single');
            $table->unsignedTinyInteger('parallel_lines')->default(1);
            $table->integer('priority')->default(0);
            $table->unsignedInteger('contact_count')->default(0);
            $table->unsignedInteger('calls_attempted')->default(0);
            $table->unsignedInteger('calls_connected')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_campaigns');
    }
};
