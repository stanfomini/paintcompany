<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Make users.type nullable to support setter/untyped users
     * who interact via support chat but are not internal/agent/customer/crew.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN type ENUM('internal', 'agent', 'customer', 'crew') NULL DEFAULT 'internal'");
        } else {
            // SQLite: recreate column as nullable string
            Schema::table('users', function ($table) {
                $table->string('type')->nullable()->default('internal')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Set any NULL types to 'internal' before making NOT NULL
            DB::table('users')->whereNull('type')->update(['type' => 'internal']);
            DB::statement("ALTER TABLE users MODIFY COLUMN type ENUM('internal', 'agent', 'customer', 'crew') NOT NULL DEFAULT 'internal'");
        } else {
            // SQLite: best-effort, set nulls to internal
            DB::table('users')->whereNull('type')->update(['type' => 'internal']);
        }
    }
};
