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
        Schema::table('color_visualizations', function (Blueprint $table) {
            $table->unsignedBigInteger('sms_conversation_id')->nullable()->after('estimate_id');

            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('sms_conversation_id')
                    ->references('id')
                    ->on('sms_conversations')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('color_visualizations', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['sms_conversation_id']);
            }

            $table->dropColumn('sms_conversation_id');
        });
    }
};
