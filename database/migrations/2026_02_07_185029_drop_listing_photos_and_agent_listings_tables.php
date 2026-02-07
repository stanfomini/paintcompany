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
        Schema::dropIfExists('listing_photos');
        Schema::dropIfExists('agent_listings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('agent_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users');
            $table->string('address');
            $table->string('city');
            $table->string('state', 2);
            $table->string('zip', 10);
            $table->string('status')->default('active');
            $table->decimal('listing_price', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('open_house_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('agent_id');
            $table->index('status');
            $table->index('open_house_date');
        });

        Schema::create('listing_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('agent_listings')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->string('filename');
            $table->string('caption')->nullable();
            $table->string('category')->default('general');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['listing_id', 'category']);
        });
    }
};
