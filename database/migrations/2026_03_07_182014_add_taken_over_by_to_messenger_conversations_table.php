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
        Schema::table('messenger_conversations', function (Blueprint $table) {
            $table->foreignId('taken_over_by')->nullable()->after('deal_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messenger_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('taken_over_by');
        });
    }
};
