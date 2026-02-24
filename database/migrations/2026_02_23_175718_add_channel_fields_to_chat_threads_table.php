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
        Schema::table('chat_threads', function (Blueprint $table) {
            if (DB::getDriverName() === 'mysql') {
                $table->string('channel')->default('internal')->after('type');
                $table->unsignedBigInteger('customer_id')->nullable()->after('deal_id');
            } else {
                $table->string('channel')->default('internal');
                $table->unsignedBigInteger('customer_id')->nullable();
            }

            $table->string('external_id')->nullable();
            $table->string('external_contact_id')->nullable();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');

            $table->index('channel');
            $table->index('customer_id');
            $table->index('external_contact_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_threads', function (Blueprint $table) {
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign(['customer_id']);
            }

            $table->dropIndex(['channel']);
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['external_contact_id']);

            $table->dropColumn(['channel', 'customer_id', 'external_id', 'external_contact_id']);
        });
    }
};
