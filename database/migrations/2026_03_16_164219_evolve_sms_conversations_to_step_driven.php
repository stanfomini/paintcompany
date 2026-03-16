<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->foreignId('current_step_id')->nullable()->after('current_state')
                ->constrained('sms_bot_steps')->nullOnDelete();
            $table->string('status')->default('in_progress')->after('current_step_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('sms_conversations', function (Blueprint $table) {
                $table->foreignId('project_preview_id')->nullable()->change();
            });

            Schema::table('sms_conversations', function (Blueprint $table) {
                $table->dropColumn('current_state');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('sms_conversations', function (Blueprint $table) {
                $table->string('current_state')->after('phone');
            });

            Schema::table('sms_conversations', function (Blueprint $table) {
                $table->foreignId('project_preview_id')->nullable(false)->change();
            });
        }

        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\SmsBotStep::class, 'current_step_id');
            $table->dropColumn(['current_step_id', 'status']);
        });
    }
};
