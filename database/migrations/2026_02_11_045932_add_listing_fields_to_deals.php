<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('listing_url', 500)->nullable()->after('sort_order');
            $table->json('listing_data')->nullable()->after('listing_url');
            $table->timestamp('listing_scraped_at')->nullable()->after('listing_data');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['listing_url', 'listing_data', 'listing_scraped_at']);
        });
    }
};
