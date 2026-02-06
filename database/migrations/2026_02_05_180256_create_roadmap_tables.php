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
        // Roadmap Phases (e.g., "Foundation", "Team Building", "Shared Future")
        Schema::create('roadmap_phases', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('not_started'); // not_started, in_progress, completed
            $table->timestamps();
        });

        // Roadmap Categories (e.g., "Marketing", "Estimating", "Sales" within a phase)
        Schema::create('roadmap_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_phase_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['roadmap_phase_id', 'slug']);
        });

        // Roadmap Items (individual checklist items within a category)
        Schema::create('roadmap_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_category_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('not_started'); // not_started, in_progress, done
            $table->text('notes')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->date('completed_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable(); // For flexible data (links, resources, etc.)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roadmap_items');
        Schema::dropIfExists('roadmap_categories');
        Schema::dropIfExists('roadmap_phases');
    }
};
