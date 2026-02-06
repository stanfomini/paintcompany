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
        // For MySQL: modify the enum to include 'crew'
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invitations MODIFY COLUMN invited_user_type ENUM('internal', 'agent', 'customer', 'crew') NOT NULL");
        }

        // For SQLite (used in tests): change to string column
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('invitations', function (Blueprint $table) {
                $table->string('invited_user_type')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invitations MODIFY COLUMN invited_user_type ENUM('internal', 'agent', 'customer') NOT NULL");
        }
    }
};
