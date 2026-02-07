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
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['listing_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('listing_id', 'property_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('property_id', 'listing_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('listing_id')->references('id')->on('agent_listings')->nullOnDelete();
        });
    }
};
