<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_prep_tasks', function (Blueprint $table) {
            $table->foreignId('added_by')->nullable()->after('sort_order')->constrained('users')->nullOnDelete();
            $table->foreignId('removal_requested_by')->nullable()->after('added_by')->constrained('users')->nullOnDelete();
            $table->timestamp('removal_requested_at')->nullable()->after('removal_requested_by');
            $table->string('removal_reason')->nullable()->after('removal_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('job_prep_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by');
            $table->dropConstrainedForeignId('removal_requested_by');
            $table->dropColumn(['removal_requested_at', 'removal_reason']);
        });
    }
};
