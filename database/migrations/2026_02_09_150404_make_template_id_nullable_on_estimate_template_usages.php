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
        Schema::table('estimate_template_usages', function (Blueprint $table) {
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign(['template_id']);
            }

            $table->unsignedBigInteger('template_id')->nullable()->change();

            if (DB::getDriverName() === 'mysql') {
                $table->foreign('template_id')
                    ->references('id')
                    ->on('estimate_templates')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_template_usages', function (Blueprint $table) {
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign(['template_id']);
            }

            $table->unsignedBigInteger('template_id')->nullable(false)->change();

            if (DB::getDriverName() === 'mysql') {
                $table->foreign('template_id')
                    ->references('id')
                    ->on('estimate_templates')
                    ->cascadeOnDelete();
            }
        });
    }
};
