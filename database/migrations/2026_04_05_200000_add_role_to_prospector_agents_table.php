<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospector_agents', function (Blueprint $table) {
            $table->string('role')->default('agent')->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('prospector_agents', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
