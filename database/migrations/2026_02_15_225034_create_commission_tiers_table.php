<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->decimal('threshold_min', 12, 2)->default(0);
            $table->decimal('threshold_max', 12, 2)->nullable();
            $table->decimal('rate', 8, 4);
            $table->string('period_type')->default('monthly'); // monthly, quarterly, yearly
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['employee_profile_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_tiers');
    }
};
