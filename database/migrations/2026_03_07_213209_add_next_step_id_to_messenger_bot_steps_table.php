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
        Schema::table('messenger_bot_steps', function (Blueprint $table) {
            $table->foreignId('next_step_id')
                ->nullable()
                ->after('delay_seconds')
                ->constrained('messenger_bot_steps')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messenger_bot_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_step_id');
        });
    }
};
