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
        Schema::table('estimates', function (Blueprint $table) {
            $table->decimal('areas_subtotal', 10, 2)->default(0)->after('total');
            $table->decimal('items_subtotal', 10, 2)->default(0)->after('areas_subtotal');
            $table->decimal('options_subtotal', 10, 2)->default(0)->after('items_subtotal');
            $table->string('public_token', 64)->nullable()->unique()->after('status');
            $table->dateTime('public_token_expires_at')->nullable()->after('public_token');

            $table->index('public_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropIndex(['public_token']);
            $table->dropColumn([
                'areas_subtotal',
                'items_subtotal',
                'options_subtotal',
                'public_token',
                'public_token_expires_at',
            ]);
        });
    }
};
