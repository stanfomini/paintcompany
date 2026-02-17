<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columnsToDrop = [];

        if (Schema::hasColumn('employee_profiles', 'commission_type')) {
            $columnsToDrop[] = 'commission_type';
        }

        if (Schema::hasColumn('employee_profiles', 'commission_rate')) {
            $columnsToDrop[] = 'commission_rate';
        }

        if (! empty($columnsToDrop)) {
            Schema::table('employee_profiles', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }

        if (! Schema::hasColumn('employee_profiles', 'commission_rules')) {
            Schema::table('employee_profiles', function (Blueprint $table) {
                $table->json('commission_rules')->nullable()->after('pay_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_profiles', 'commission_rules')) {
            Schema::table('employee_profiles', function (Blueprint $table) {
                $table->dropColumn('commission_rules');
            });
        }

        Schema::table('employee_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_profiles', 'commission_type')) {
                $table->string('commission_type')->nullable()->after('pay_type');
            }

            if (! Schema::hasColumn('employee_profiles', 'commission_rate')) {
                $table->decimal('commission_rate', 8, 4)->nullable()->after('commission_type');
            }
        });
    }
};
