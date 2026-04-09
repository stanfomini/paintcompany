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
        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword')->unique();
            $table->unsignedInteger('search_volume')->nullable();
            $table->enum('competition', ['low', 'medium', 'high'])->nullable();
            $table->decimal('cpc', 8, 2)->nullable();
            $table->enum('intent', ['informational', 'commercial', 'transactional', 'navigational', 'local'])->nullable();
            $table->enum('status', ['researched', 'targeted', 'ranking', 'won', 'dropped'])->default('researched');
            $table->enum('source', ['keyword_planner', 'search_console', 'manual', 'ai_suggested'])->default('manual');
            $table->text('default_sentence')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_researched_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('source');
            $table->index('intent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};
