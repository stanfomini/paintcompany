<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained('company_settings')->cascadeOnDelete();
            $table->string('name');
            $table->string('entity_type', 30);
            $table->string('state_of_formation', 2)->nullable();
            $table->string('ein', 20)->nullable();
            $table->date('formation_date')->nullable();
            $table->string('registered_agent')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporations');
    }
};
