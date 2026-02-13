<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->text('agent_notes')->nullable()->after('customer_checklist');
            $table->json('agent_checklist')->nullable()->after('agent_notes');
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn(['agent_notes', 'agent_checklist']);
        });
    }
};
