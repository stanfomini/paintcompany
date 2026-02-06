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
        Schema::create('painting_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('estimate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_number')->unique();
            $table->string('stage')->default('estimate_accepted')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->integer('allowed_colors')->default(0);
            $table->integer('required_crew_size')->default(1);
            $table->boolean('company_date_confirmed')->default(false);
            $table->boolean('customer_date_confirmed')->default(false);
            $table->boolean('company_colors_confirmed')->default(false);
            $table->boolean('customer_colors_confirmed')->default(false);
            $table->timestamp('dispatched_at')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('painting_jobs');
    }
};
