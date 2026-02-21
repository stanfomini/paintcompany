<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_territories', function (Blueprint $table) {
            $table->string('level')->default('zone')->after('status');
            $table->foreignId('parent_id')->nullable()->after('level')
                ->constrained('sales_territories')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->after('parent_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_territories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('level');
        });
    }
};
