<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_package_items', function (Blueprint $table) {
            $table->boolean('is_customer_selectable')->default(false)->after('customer_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_package_items', function (Blueprint $table) {
            $table->dropColumn('is_customer_selectable');
        });
    }
};
