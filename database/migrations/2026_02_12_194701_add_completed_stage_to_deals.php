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
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE deals MODIFY COLUMN stage ENUM('lead','not_contacted','follow_up','quote_scheduled','quoted','signed','completed','lost','cancelled') NOT NULL DEFAULT 'lead'");
        } else {
            Schema::table('deals', function (Blueprint $table) {
                $table->string('stage')->default('lead')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Move any completed deals back to signed before removing the enum value
        DB::table('deals')->where('stage', 'completed')->update(['stage' => 'signed']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE deals MODIFY COLUMN stage ENUM('lead','not_contacted','follow_up','quote_scheduled','quoted','signed','lost','cancelled') NOT NULL DEFAULT 'lead'");
        } else {
            Schema::table('deals', function (Blueprint $table) {
                $table->string('stage')->default('lead')->change();
            });
        }
    }
};
