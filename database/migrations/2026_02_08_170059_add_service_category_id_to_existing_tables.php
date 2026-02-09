<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_profiles', function (Blueprint $table) {
            $table->foreignId('service_category_id')->nullable()->after('user_id')
                ->constrained('service_categories')->nullOnDelete();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('service_category_id')->nullable()->after('invited_user_type')
                ->constrained('service_categories')->nullOnDelete();
        });

        // job_crew: add column, drop FK that references the unique index, drop unique, add new unique, re-add FK
        Schema::table('job_crew', function (Blueprint $table) {
            $table->foreignId('service_category_id')->nullable()->after('crew_user_id')
                ->constrained('service_categories')->nullOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            // SQLite doesn't support dropping foreign keys by name — recreate handled by schema rebuild
            Schema::table('job_crew', function (Blueprint $table) {
                $table->dropUnique('job_crew_job_id_crew_user_id_unique');
            });

            Schema::table('job_crew', function (Blueprint $table) {
                $table->unique(['job_id', 'crew_user_id', 'service_category_id'], 'job_crew_job_crew_service_unique');
            });
        } else {
            Schema::table('job_crew', function (Blueprint $table) {
                $table->dropForeign('job_crew_job_id_foreign');
                $table->dropUnique('job_crew_job_id_crew_user_id_unique');
            });

            Schema::table('job_crew', function (Blueprint $table) {
                $table->unique(['job_id', 'crew_user_id', 'service_category_id'], 'job_crew_job_crew_service_unique');
                $table->foreign('job_id')->references('id')->on('painting_jobs')->cascadeOnDelete();
            });
        }

        Schema::table('estimate_options', function (Blueprint $table) {
            $table->foreignId('service_category_id')->nullable()->after('estimate_id')
                ->constrained('service_categories')->nullOnDelete();
        });

        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->text('description')->nullable()->after('surface_type');
        });

        Schema::table('job_prep_tasks', function (Blueprint $table) {
            $table->foreignId('service_category_id')->nullable()->after('job_prep_plan_id')
                ->constrained('service_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_prep_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_category_id');
        });

        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('estimate_options', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_category_id');
        });

        Schema::table('job_crew', function (Blueprint $table) {
            $table->dropForeign(['job_id']);
            $table->dropUnique('job_crew_job_crew_service_unique');
        });

        Schema::table('job_crew', function (Blueprint $table) {
            $table->unique(['job_id', 'crew_user_id'], 'job_crew_job_id_crew_user_id_unique');
            $table->foreign('job_id')->references('id')->on('painting_jobs')->cascadeOnDelete();
            $table->dropConstrainedForeignId('service_category_id');
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_category_id');
        });

        Schema::table('crew_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_category_id');
        });
    }
};
