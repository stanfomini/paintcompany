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
        Schema::table('prospector_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('max_calls_per_day')->default(3)->after('auto_vm_drop');
            $table->unsignedInteger('max_total_attempts')->default(90)->after('max_calls_per_day');
            $table->unsignedInteger('max_days_in_campaign')->default(30)->after('max_total_attempts');
            $table->unsignedInteger('min_minutes_between')->default(240)->after('max_days_in_campaign');
            $table->foreignId('next_campaign_id')->nullable()->after('min_minutes_between')
                ->constrained('prospector_campaigns')->nullOnDelete();
            $table->time('calling_hours_start')->nullable()->after('next_campaign_id');
            $table->time('calling_hours_end')->nullable()->after('calling_hours_start');
            $table->boolean('auto_dial')->default(true)->after('calling_hours_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospector_campaigns', function (Blueprint $table) {
            $table->dropForeign(['next_campaign_id']);
            $table->dropColumn([
                'max_calls_per_day', 'max_total_attempts', 'max_days_in_campaign',
                'min_minutes_between', 'next_campaign_id',
                'calling_hours_start', 'calling_hours_end', 'auto_dial',
            ]);
        });
    }
};
