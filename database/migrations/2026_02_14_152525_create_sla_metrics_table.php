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
        Schema::create('sla_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('metric_type'); // speed_to_lead, follow_up_compliance, response_time, estimate_turnaround
            $table->string('period'); // daily, weekly, monthly
            $table->date('period_date');
            $table->decimal('value', 10, 2)->default(0);
            $table->integer('sample_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'metric_type', 'period', 'period_date']);
            $table->index(['metric_type', 'period', 'period_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sla_metrics');
    }
};
