<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('meeting_type')->nullable()->after('type');
            $table->string('meeting_status')->nullable()->after('meeting_type');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete()->after('meeting_status');
            $table->foreignId('video_call_id')->nullable()->constrained('video_calls')->nullOnDelete()->after('proposed_by');
            $table->foreignId('estimate_id')->nullable()->constrained('estimates')->nullOnDelete()->after('video_call_id');
            $table->timestamp('customer_confirmed_at')->nullable()->after('completed_at');
            $table->timestamp('agent_confirmed_at')->nullable()->after('customer_confirmed_at');
            $table->timestamp('team_confirmed_at')->nullable()->after('agent_confirmed_at');
            $table->timestamp('reminder_sent_at')->nullable()->after('team_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['proposed_by']);
            $table->dropForeign(['video_call_id']);
            $table->dropForeign(['estimate_id']);
            $table->dropColumn([
                'meeting_type',
                'meeting_status',
                'proposed_by',
                'video_call_id',
                'estimate_id',
                'customer_confirmed_at',
                'agent_confirmed_at',
                'team_confirmed_at',
                'reminder_sent_at',
            ]);
        });
    }
};
