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
        Schema::table('photo_annotations', function (Blueprint $table) {
            $table->string('author_type', 20)->default('internal')->after('created_by');
        });

        // Backfill existing annotations based on creator's user type
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('UPDATE photo_annotations SET author_type = (SELECT type FROM users WHERE users.id = photo_annotations.created_by) WHERE created_by IS NOT NULL');
        } else {
            DB::table('photo_annotations')
                ->join('users', 'photo_annotations.created_by', '=', 'users.id')
                ->update(['photo_annotations.author_type' => DB::raw('users.type')]);
        }

        Schema::table('photo_annotations', function (Blueprint $table) {
            $table->unique(['property_photo_id', 'author_type'], 'photo_annotations_photo_author_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photo_annotations', function (Blueprint $table) {
            $table->dropUnique('photo_annotations_photo_author_unique');
            $table->dropColumn('author_type');
        });
    }
};
