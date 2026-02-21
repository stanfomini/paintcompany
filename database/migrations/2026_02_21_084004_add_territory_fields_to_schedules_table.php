<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->string('schedule_type')->default('job')->after('employee_profile_id');
            $table->foreignId('territory_id')->nullable()->after('schedule_type')
                ->constrained('sales_territories')->nullOnDelete();
            $table->string('meet_address', 500)->nullable()->after('notes');
            $table->decimal('meet_lat', 10, 7)->nullable()->after('meet_address');
            $table->decimal('meet_lng', 10, 7)->nullable()->after('meet_lat');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropForeign(['territory_id']);
            $table->dropColumn(['schedule_type', 'territory_id', 'meet_address', 'meet_lat', 'meet_lng']);
        });
    }
};
