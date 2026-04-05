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
        Schema::create('prospector_external_id_maps', function (Blueprint $table) {
            $table->id();
            $table->string('prospector_type', 50); // 'company' or 'contact'
            $table->unsignedBigInteger('prospector_id');
            $table->string('external_model'); // Fully qualified host model class
            $table->unsignedBigInteger('external_id'); // Host model's ID
            $table->string('sync_direction', 20)->default('both'); // 'import', 'export', 'both'
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['prospector_type', 'external_model', 'external_id'], 'ext_map_unique');
            $table->index(['prospector_type', 'prospector_id'], 'ext_map_prospector');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospector_external_id_maps');
    }
};
