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
        Schema::create('keyword_page_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_area_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('role', ['primary', 'secondary', 'supporting'])->default('secondary');
            $table->text('custom_sentence')->nullable();
            $table->decimal('position', 5, 2)->nullable();
            $table->unsignedInteger('impressions')->nullable();
            $table->unsignedInteger('clicks')->nullable();
            $table->decimal('ctr', 5, 4)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'service_area_id', 'role'], 'kpa_target_role_idx');
            $table->index(['keyword_id', 'service_id', 'service_area_id'], 'kpa_dedup_idx');
        });

        // CHECK constraint: at least one of service_id / service_area_id must be set.
        // SQLite cannot ALTER TABLE ADD CONSTRAINT, so this is MySQL/MariaDB only.
        // The AssignKeywordAction + KeywordPageAssignment::saving event are the
        // authoritative application-layer guard for both drivers.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE keyword_page_assignments ADD CONSTRAINT kpa_target_not_null CHECK (service_id IS NOT NULL OR service_area_id IS NOT NULL)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keyword_page_assignments');
    }
};
