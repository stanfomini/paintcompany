<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_areas', function (Blueprint $table) {
            $table->boolean('is_customer_added')->default(false)->after('customer_checklist');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_areas', function (Blueprint $table) {
            $table->dropColumn('is_customer_added');
        });
    }
};
