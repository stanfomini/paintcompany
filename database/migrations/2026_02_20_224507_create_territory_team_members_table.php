<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territory_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('sales_territories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['territory_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_team_members');
    }
};
