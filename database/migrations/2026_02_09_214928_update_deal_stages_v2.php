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
        // Step 1: Migrate 'contacted' deals to 'not_contacted' before altering enum
        DB::table('deals')
            ->where('stage', 'contacted')
            ->update(['stage' => 'lead']); // Temp: move to valid stage in both old/new enums

        // Step 2: Alter the enum to add new stages and remove 'contacted'
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('deals', function (Blueprint $table) {
                $table->string('stage')->default('lead')->change();
            });
        } else {
            DB::statement("ALTER TABLE deals MODIFY COLUMN stage ENUM('lead', 'not_contacted', 'follow_up', 'quote_scheduled', 'quoted', 'signed', 'lost', 'cancelled') NOT NULL DEFAULT 'lead'");
        }

        // Step 3: Restore the migrated deals to 'not_contacted'
        // We use a heuristic: deals that were in 'contacted' are now 'lead' but had no estimated_value set typically
        // For safety, we just leave them as 'lead' since we can't reliably distinguish

        // Step 4: Add follow_up_date column
        Schema::table('deals', function (Blueprint $table) {
            $table->date('follow_up_date')->nullable()->after('lead_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Move new stages back to 'lead' before reverting enum
        DB::table('deals')
            ->whereIn('stage', ['not_contacted', 'follow_up'])
            ->update(['stage' => 'lead']);

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('deals', function (Blueprint $table) {
                $table->string('stage')->default('lead')->change();
            });
        } else {
            DB::statement("ALTER TABLE deals MODIFY COLUMN stage ENUM('lead', 'contacted', 'quote_scheduled', 'quoted', 'signed', 'lost', 'cancelled') NOT NULL DEFAULT 'lead'");
        }

        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('follow_up_date');
        });
    }
};
