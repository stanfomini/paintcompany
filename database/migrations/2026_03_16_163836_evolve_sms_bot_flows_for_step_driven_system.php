<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_bot_flows', function (Blueprint $table) {
            $table->string('trigger_keyword')->nullable()->after('trigger_type');
            $table->string('completion_action')->nullable()->after('delay_after_lead_minutes');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('sms_bot_flows', function (Blueprint $table) {
                $table->dropColumn([
                    'greeting_message',
                    'photo_prompt_message',
                    'visualization_message',
                    'portal_link_message',
                ]);
            });

            Schema::table('sms_bot_flows', function (Blueprint $table) {
                $table->unique('trigger_keyword');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('sms_bot_flows', function (Blueprint $table) {
                $table->dropUnique(['trigger_keyword']);
            });

            Schema::table('sms_bot_flows', function (Blueprint $table) {
                $table->text('greeting_message')->after('lead_source_ids');
                $table->text('photo_prompt_message')->after('greeting_message');
                $table->text('visualization_message')->after('photo_prompt_message');
                $table->text('portal_link_message')->after('visualization_message');
            });
        }

        Schema::table('sms_bot_flows', function (Blueprint $table) {
            $table->dropColumn(['trigger_keyword', 'completion_action']);
        });
    }
};
