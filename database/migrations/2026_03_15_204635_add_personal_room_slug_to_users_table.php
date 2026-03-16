<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('personal_room_slug')->nullable()->unique()->after('extension');
        });

        // Backfill existing internal users with a personal room slug
        $internalUsers = DB::table('users')->where('type', 'internal')->get();
        foreach ($internalUsers as $user) {
            $baseSlug = Str::slug($user->name);
            $slug = $baseSlug.'-'.Str::lower(Str::random(4));

            // Ensure uniqueness
            while (DB::table('users')->where('personal_room_slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.Str::lower(Str::random(4));
            }

            DB::table('users')->where('id', $user->id)->update(['personal_room_slug' => $slug]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('personal_room_slug');
        });
    }
};
