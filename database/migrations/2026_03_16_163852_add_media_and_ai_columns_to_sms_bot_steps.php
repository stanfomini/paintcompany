<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_bot_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_bot_steps', 'media_url')) {
                $table->string('media_url')->nullable()->after('message_text');
            }

            if (! Schema::hasColumn('sms_bot_steps', 'wait_for_media')) {
                $table->boolean('wait_for_media')->default(false)->after('delay_seconds');
            }

            if (! Schema::hasColumn('sms_bot_steps', 'source_step_id')) {
                if (DB::getDriverName() !== 'sqlite') {
                    $table->unsignedBigInteger('source_step_id')->nullable()->after('wait_for_media');
                    $table->foreign('source_step_id')->references('id')->on('sms_bot_steps')->nullOnDelete();
                } else {
                    $table->foreignId('source_step_id')->nullable()->after('wait_for_media')->constrained('sms_bot_steps')->nullOnDelete();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_bot_steps', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['source_step_id']);
            }
            $table->dropColumn(['media_url', 'wait_for_media', 'source_step_id']);
        });
    }
};
