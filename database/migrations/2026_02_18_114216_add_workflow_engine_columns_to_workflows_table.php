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
        Schema::table('workflows', function (Blueprint $table) {
            $table->integer('priority')->default(0)->after('is_active');
            $table->string('user_scope_type')->default('any')->after('priority');
            $table->string('user_scope_value')->nullable()->after('user_scope_type');
            $table->foreignId('service_category_id')->nullable()->after('user_scope_value')
                ->constrained('service_categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropForeign(['service_category_id']);
            $table->dropColumn(['priority', 'user_scope_type', 'user_scope_value', 'service_category_id']);
        });
    }
};
