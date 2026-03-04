<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('sms_opted_out_at')->nullable()->after('notification_preferences');
        });

        Schema::create('sms_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->string('phone_number', 20);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['phone_number', 'expires_at']);
            $table->index(['user_id', 'thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_sessions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_opted_out_at');
        });
    }
};
