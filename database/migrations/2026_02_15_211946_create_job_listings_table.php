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
        Schema::create('job_listings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('description');
            $table->longText('requirements')->nullable();
            $table->string('location')->nullable();
            $table->decimal('pay_range_min', 10, 2)->nullable();
            $table->decimal('pay_range_max', 10, 2)->nullable();
            $table->string('employment_type')->default('full_time');
            $table->string('status')->default('draft');
            $table->string('qr_code_token')->unique();
            $table->integer('positions_available')->default(1);
            $table->integer('positions_filled')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_listings');
    }
};
