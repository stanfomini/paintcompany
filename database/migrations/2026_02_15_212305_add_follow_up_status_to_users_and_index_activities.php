<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'follow_up_status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('follow_up_status')->default('active')->after('is_active');
            });
        }

        Schema::table('activities', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('activities'))->pluck('name');
            if (! $indexes->contains('activities_automation_key_index')) {
                $table->index('automation_key');
            }
            if (! $indexes->contains('activities_followup_lookup_index')) {
                $table->index(
                    ['automation_key', 'subject_type', 'subject_id', 'is_completed'],
                    'activities_followup_lookup_index'
                );
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('follow_up_status');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('activities_followup_lookup_index');
            $table->dropIndex(['automation_key']);
        });
    }
};
