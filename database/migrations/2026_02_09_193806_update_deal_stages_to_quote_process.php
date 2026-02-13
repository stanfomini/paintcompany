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
        // First, migrate data in removed stages to 'signed'
        DB::table('deals')
            ->whereIn('stage', ['scheduled', 'in_progress', 'completed', 'paid', 'signed_accepted'])
            ->update(['stage' => 'lead']); // Temporarily set to 'lead' (valid in both old and new enums)

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('deals', function (Blueprint $table) {
                $table->string('stage')->default('lead')->change();
            });
        } else {
            DB::statement("ALTER TABLE deals MODIFY COLUMN stage ENUM('lead', 'contacted', 'quote_scheduled', 'quoted', 'signed', 'lost', 'cancelled') NOT NULL DEFAULT 'lead'");
        }

        // Now update the temporarily set 'lead' deals that were migrated to 'signed'
        // We need to identify them - use a marker approach
        // Since we already moved them to 'lead', we'll update based on having actual dates
        // (deals that were in progress/completed/paid/scheduled likely have actual_start_date or actual_end_date)
        DB::table('deals')
            ->where('stage', 'lead')
            ->where(function ($query) {
                $query->whereNotNull('actual_start_date')
                    ->orWhereNotNull('actual_end_date');
            })
            ->update(['stage' => 'signed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Move 'contacted' and 'quote_scheduled' back to 'lead'
        DB::table('deals')
            ->whereIn('stage', ['contacted', 'quote_scheduled'])
            ->update(['stage' => 'lead']);

        // Move 'signed' back to 'signed_accepted'
        // Move 'lost' back to 'cancelled'
        // Temporarily set to valid values before enum change
        DB::table('deals')
            ->where('stage', 'signed')
            ->update(['stage' => 'lead']);

        DB::table('deals')
            ->where('stage', 'lost')
            ->update(['stage' => 'lead']);

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('deals', function (Blueprint $table) {
                $table->string('stage')->default('lead')->change();
            });
        } else {
            DB::statement("ALTER TABLE deals MODIFY COLUMN stage ENUM('lead', 'quoted', 'scheduled', 'in_progress', 'completed', 'paid', 'signed_accepted', 'cancelled') NOT NULL DEFAULT 'lead'");
        }

        // Restore: 'lead' deals that had actual dates → signed_accepted
        DB::table('deals')
            ->where('stage', 'lead')
            ->where(function ($query) {
                $query->whereNotNull('actual_start_date')
                    ->orWhereNotNull('actual_end_date');
            })
            ->update(['stage' => 'signed_accepted']);
    }
};
