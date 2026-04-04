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
        Schema::table('prospector_calls', function (Blueprint $table) {
            $table->text('voicemail_transcription')->nullable()->after('recording_path');
            $table->string('recording_sid')->nullable()->after('recording_path');
        });
    }

    public function down(): void
    {
        Schema::table('prospector_calls', function (Blueprint $table) {
            $table->dropColumn(['voicemail_transcription', 'recording_sid']);
        });
    }
};
