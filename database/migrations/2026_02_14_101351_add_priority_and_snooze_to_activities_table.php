<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            if (DB::getDriverName() === 'mysql') {
                $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->after('automation_key');
            } else {
                $table->string('priority')->default('normal')->after('automation_key');
            }
            $table->integer('priority_score')->default(0)->after('priority')->index();
            $table->timestamp('snoozed_until')->nullable()->after('priority_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['priority', 'priority_score', 'snoozed_until']);
        });
    }
};
