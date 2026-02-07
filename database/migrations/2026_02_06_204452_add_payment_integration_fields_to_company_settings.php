<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('stripe_connect_enabled')->default(false);
            $table->boolean('stripe_ach_enabled')->default(false);
            $table->boolean('increase_enabled')->default(false);
            $table->string('increase_environment', 10)->default('sandbox');
            $table->text('increase_access_token')->nullable();
            $table->string('increase_oauth_state', 255)->nullable();
            $table->boolean('payment_split_enabled')->default(false);
            $table->json('payment_split_config')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_connect_enabled',
                'stripe_ach_enabled',
                'increase_enabled',
                'increase_environment',
                'increase_access_token',
                'increase_oauth_state',
                'payment_split_enabled',
                'payment_split_config',
            ]);
        });
    }
};
