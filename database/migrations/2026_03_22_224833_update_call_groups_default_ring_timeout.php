<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lower ring timeout default from 30s to 17s.
     *
     * Cell phones route to personal voicemail after ~20-25 seconds.
     * A 30-second timeout lets the cell VM pick up before Twilio
     * can redirect to the company voicemail system.
     */
    public function up(): void
    {
        // Update existing groups that still have the problematic 30s default
        DB::table('call_groups')
            ->where('ring_timeout_seconds', 30)
            ->update(['ring_timeout_seconds' => 17]);

        // Change column default for new groups
        Schema::table('call_groups', function (Blueprint $table) {
            $table->integer('ring_timeout_seconds')->default(17)->change();
        });
    }

    public function down(): void
    {
        Schema::table('call_groups', function (Blueprint $table) {
            $table->integer('ring_timeout_seconds')->default(30)->change();
        });

        DB::table('call_groups')
            ->where('ring_timeout_seconds', 17)
            ->update(['ring_timeout_seconds' => 30]);
    }
};
