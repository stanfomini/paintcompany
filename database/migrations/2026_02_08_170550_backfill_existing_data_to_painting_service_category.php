<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure painting category exists
        $paintingId = DB::table('service_categories')->where('slug', 'painting')->value('id');

        if (! $paintingId) {
            $paintingId = DB::table('service_categories')->insertGetId([
                'name' => 'Painting',
                'slug' => 'painting',
                'description' => 'Interior and exterior painting services',
                'icon' => 'paint-brush',
                'color' => 'blue',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Backfill crew_profiles
        DB::table('crew_profiles')
            ->whereNull('service_category_id')
            ->update(['service_category_id' => $paintingId]);

        // Backfill job_crew pivot
        DB::table('job_crew')
            ->whereNull('service_category_id')
            ->update(['service_category_id' => $paintingId]);

        // Backfill job_prep_tasks
        DB::table('job_prep_tasks')
            ->whereNull('service_category_id')
            ->update(['service_category_id' => $paintingId]);

        // Create JobServiceRequirement for each existing job
        $jobs = DB::table('painting_jobs')->get(['id', 'required_crew_size', 'scheduled_date']);

        foreach ($jobs as $job) {
            $exists = DB::table('job_service_requirements')
                ->where('job_id', $job->id)
                ->where('service_category_id', $paintingId)
                ->exists();

            if (! $exists) {
                // Determine status based on crew pivot
                $hasAccepted = DB::table('job_crew')
                    ->where('job_id', $job->id)
                    ->where('status', 'accepted')
                    ->exists();

                $hasDispatched = DB::table('job_crew')
                    ->where('job_id', $job->id)
                    ->exists();

                $status = $hasAccepted ? 'accepted' : ($hasDispatched ? 'dispatched' : 'pending');

                DB::table('job_service_requirements')->insert([
                    'job_id' => $job->id,
                    'service_category_id' => $paintingId,
                    'required_crew_size' => $job->required_crew_size ?? 1,
                    'scheduled_date' => $job->scheduled_date,
                    'status' => $status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Migrate approval data from job_prep_plans to job_prep_approvals
        $plans = DB::table('job_prep_plans')->get([
            'id', 'crew_size', 'hours_per_day',
            'total_labor_hours', 'total_cleanup_hours', 'total_days',
            'team_approved', 'team_approved_by', 'team_approved_at',
            'crew_approved', 'crew_approved_by', 'crew_approved_at',
            'customer_approved', 'customer_approved_by', 'customer_approved_at',
        ]);

        foreach ($plans as $plan) {
            // Create JobPrepServiceSetting for painting
            $settingExists = DB::table('job_prep_service_settings')
                ->where('job_prep_plan_id', $plan->id)
                ->where('service_category_id', $paintingId)
                ->exists();

            if (! $settingExists) {
                DB::table('job_prep_service_settings')->insert([
                    'job_prep_plan_id' => $plan->id,
                    'service_category_id' => $paintingId,
                    'crew_size' => $plan->crew_size ?? 2,
                    'hours_per_day' => $plan->hours_per_day ?? 8,
                    'total_labor_hours' => $plan->total_labor_hours ?? 0,
                    'total_cleanup_hours' => $plan->total_cleanup_hours ?? 0,
                    'total_days' => $plan->total_days ?? 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Create approval rows for each party
            foreach (['team', 'crew', 'customer'] as $party) {
                $approvedField = "{$party}_approved";
                $approvedByField = "{$party}_approved_by";
                $approvedAtField = "{$party}_approved_at";

                $approvalExists = DB::table('job_prep_approvals')
                    ->where('job_prep_plan_id', $plan->id)
                    ->where('service_category_id', $paintingId)
                    ->where('party', $party)
                    ->exists();

                if (! $approvalExists) {
                    DB::table('job_prep_approvals')->insert([
                        'job_prep_plan_id' => $plan->id,
                        'service_category_id' => $paintingId,
                        'party' => $party,
                        'approved' => $plan->$approvedField ?? false,
                        'approved_by' => $plan->$approvedByField,
                        'approved_at' => $plan->$approvedAtField,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $paintingId = DB::table('service_categories')->where('slug', 'painting')->value('id');

        if ($paintingId) {
            DB::table('job_prep_approvals')->where('service_category_id', $paintingId)->delete();
            DB::table('job_prep_service_settings')->where('service_category_id', $paintingId)->delete();
            DB::table('job_service_requirements')->where('service_category_id', $paintingId)->delete();

            DB::table('crew_profiles')
                ->where('service_category_id', $paintingId)
                ->update(['service_category_id' => null]);

            DB::table('job_crew')
                ->where('service_category_id', $paintingId)
                ->update(['service_category_id' => null]);

            DB::table('job_prep_tasks')
                ->where('service_category_id', $paintingId)
                ->update(['service_category_id' => null]);
        }
    }
};
