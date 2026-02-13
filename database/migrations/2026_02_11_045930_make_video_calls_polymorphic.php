<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_calls', function (Blueprint $table) {
            $table->string('callable_type')->nullable()->after('id');
            $table->unsignedBigInteger('callable_id')->nullable()->after('callable_type');
            $table->index(['callable_type', 'callable_id']);
        });

        // Backfill existing rows: set callable to Job
        DB::table('video_calls')
            ->whereNotNull('job_id')
            ->update([
                'callable_type' => 'App\\Models\\Job',
                'callable_id' => DB::raw('job_id'),
            ]);

        // Make job_id nullable for new polymorphic usage
        Schema::table('video_calls', function (Blueprint $table) {
            $table->unsignedBigInteger('job_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('video_calls', function (Blueprint $table) {
            $table->dropIndex(['callable_type', 'callable_id']);
            $table->dropColumn(['callable_type', 'callable_id']);
        });
    }
};
