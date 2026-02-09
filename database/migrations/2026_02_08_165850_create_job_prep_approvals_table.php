<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_prep_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_prep_plan_id')->constrained('job_prep_plans')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->string('party');
            $table->boolean('approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['job_prep_plan_id', 'service_category_id', 'party'], 'prep_approval_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_prep_approvals');
    }
};
