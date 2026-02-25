<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add type and default_notify_user_id (idempotent)
        Schema::table('email_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('email_accounts', 'type')) {
                $table->string('type')->default('personal')->after('id');
            }
            if (! Schema::hasColumn('email_accounts', 'default_notify_user_id')) {
                $table->foreignId('default_notify_user_id')->nullable()->after('is_active')
                    ->constrained('users')->nullOnDelete();
            }
        });

        // Make user_id nullable and drop unique constraint
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('email_accounts', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        } else {
            // MySQL requires FK dropped in a separate statement before its backing index
            if (Schema::hasIndex('email_accounts', 'email_accounts_user_id_unique')) {
                Schema::table('email_accounts', function (Blueprint $table) {
                    $table->dropForeign(['user_id']);
                });

                Schema::table('email_accounts', function (Blueprint $table) {
                    $table->dropUnique(['user_id']);
                });

                Schema::table('email_accounts', function (Blueprint $table) {
                    $table->unsignedBigInteger('user_id')->nullable()->change();
                    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                });
            }
        }

        // Create pivot table for shared account members
        if (! Schema::hasTable('email_account_user')) {
            Schema::create('email_account_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->boolean('can_send')->default(true);
                $table->boolean('receives_notifications')->default(true);
                $table->timestamps();

                $table->unique(['email_account_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_account_user');

        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_notify_user_id');
            $table->dropColumn('type');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('email_accounts', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->unique()->change();
            });
        }
    }
};
