<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite doesn't support dropping FKs; just make nullable
            Schema::table('video_recordings', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });

            Schema::table('video_channels', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        } else {
            // MySQL: drop FK, make nullable, re-add with nullOnDelete
            Schema::table('video_recordings', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('video_recordings', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });

            Schema::table('video_channels', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('video_channels', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite: just make non-nullable again (data may break if nulls exist)
            Schema::table('video_recordings', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            });

            Schema::table('video_channels', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            });
        } else {
            // MySQL: drop nullOnDelete FK, make non-nullable, re-add with cascadeOnDelete
            Schema::table('video_recordings', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('video_recordings', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });

            Schema::table('video_channels', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('video_channels', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
