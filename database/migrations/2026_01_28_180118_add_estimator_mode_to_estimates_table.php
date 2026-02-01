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
        Schema::table('estimates', function (Blueprint $table) {
            // Estimator mode
            $table->enum('estimator_mode', ['simple', 'template', 'detailed'])->default('detailed')->after('status');

            // Simple mode fields
            $table->decimal('simple_sqft', 12, 2)->nullable()->after('estimator_mode');
            $table->decimal('simple_rate_per_sqft', 10, 4)->nullable()->after('simple_sqft');
            $table->enum('project_type', ['interior', 'exterior', 'both'])->nullable()->after('simple_rate_per_sqft');

            // Deposit/payment fields
            $table->decimal('deposit_percent', 5, 2)->default(30)->after('discount_amount');
            $table->decimal('deposit_amount', 12, 2)->default(0)->after('deposit_percent');
            $table->decimal('balance_due', 12, 2)->default(0)->after('deposit_amount');

            // Selected package reference
            $table->foreignId('selected_package_id')->nullable()->after('balance_due');

            $table->index('estimator_mode');
            $table->index('selected_package_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropIndex(['estimator_mode']);
            $table->dropIndex(['selected_package_id']);
            $table->dropColumn([
                'estimator_mode',
                'simple_sqft',
                'simple_rate_per_sqft',
                'project_type',
                'deposit_percent',
                'deposit_amount',
                'balance_due',
                'selected_package_id',
            ]);
        });
    }
};
