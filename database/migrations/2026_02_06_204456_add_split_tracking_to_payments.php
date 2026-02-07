<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('split_status', 20)->nullable();
            $table->timestamp('split_executed_at')->nullable();
            $table->json('split_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'split_status',
                'split_executed_at',
                'split_details',
            ]);
        });
    }
};
