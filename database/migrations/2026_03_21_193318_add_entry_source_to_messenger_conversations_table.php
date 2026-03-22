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
            $table->string('entry_source', 20)->nullable()->after('status');
            $table->index('entry_source');
        });
    }

    public function down(): void
    {
        Schema::table('messenger_conversations', function (Blueprint $table) {
            $table->dropIndex(['entry_source']);
            $table->dropColumn('entry_source');
        });
    }
};
