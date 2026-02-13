<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('agent_intent')->nullable()->after('listing_scraped_at');
            $table->decimal('listing_value', 12, 2)->nullable()->after('agent_intent');
            $table->unsignedTinyInteger('listing_bedrooms')->nullable()->after('listing_value');
            $table->unsignedInteger('listing_sqft')->nullable()->after('listing_bedrooms');
            $table->string('roi_scope')->nullable()->after('listing_sqft');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['agent_intent', 'listing_value', 'listing_bedrooms', 'listing_sqft', 'roi_scope']);
        });
    }
};
