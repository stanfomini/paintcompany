<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospector_agents', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('prospector_agents', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'is_active']);
        });
    }
};
