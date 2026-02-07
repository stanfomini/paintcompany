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
        Schema::table('properties', function (Blueprint $table) {
            // Make customer_id nullable (agent listings have no customer initially)
            $table->unsignedBigInteger('customer_id')->nullable()->change();

            // Agent/listing fields
            $table->foreignId('agent_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
            $table->string('source')->nullable()->after('notes'); // direct, agent_referral, open_house, manual
            $table->string('listing_status')->nullable()->after('source'); // active, pending, sold, inactive
            $table->decimal('listing_price', 12, 2)->nullable()->after('listing_status');
            $table->text('description')->nullable()->after('listing_price');
            $table->timestamp('open_house_date')->nullable()->after('description');

            // Indexes
            $table->index('agent_id');
            $table->index('source');
            $table->index('listing_status');
            $table->index('open_house_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropIndex(['agent_id']);
            $table->dropIndex(['source']);
            $table->dropIndex(['listing_status']);
            $table->dropIndex(['open_house_date']);

            $table->dropColumn(['agent_id', 'source', 'listing_status', 'listing_price', 'description', 'open_house_date']);
        });
    }
};
