<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For MySQL, modify the enum column to include 'crew'
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN type ENUM('internal', 'agent', 'customer', 'crew') DEFAULT 'internal'");
        }

        // For SQLite (used in tests), the enum is handled via CHECK constraint
        // which is recreated when migrations run from scratch
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Remove any crew users first
            DB::table('users')->where('type', 'crew')->delete();
            DB::statement("ALTER TABLE users MODIFY COLUMN type ENUM('internal', 'agent', 'customer') DEFAULT 'internal'");
        }
    }
};
