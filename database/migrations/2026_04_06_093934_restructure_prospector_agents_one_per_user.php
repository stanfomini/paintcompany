<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Find users with multiple agent records and merge them.
        // For each duplicate set, keep the agent with the highest role,
        // sync all company_ids into the pivot table, and delete duplicates.
        $rolePriority = ['admin' => 3, 'supervisor' => 2, 'agent' => 1];

        $duplicateUserIds = DB::table('prospector_agents')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($duplicateUserIds as $userId) {
            $agents = DB::table('prospector_agents')
                ->where('user_id', $userId)
                ->get();

            // Pick the keeper: highest role, then most recently updated
            $keeper = $agents->sortByDesc(function ($a) use ($rolePriority) {
                $score = ($rolePriority[$a->role ?? 'agent'] ?? 1) * 10000;
                $score += strtotime($a->updated_at ?? $a->created_at ?? 'now');

                return $score;
            })->first();

            $companyIds = $agents->pluck('company_id')->filter()->unique()->all();

            // Insert pivot rows for all companies (skip if already there)
            foreach ($companyIds as $companyId) {
                DB::table('prospector_agent_company')->updateOrInsert(
                    ['agent_id' => $keeper->id, 'company_id' => $companyId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }

            // Delete the other agent records
            $duplicateIds = $agents->pluck('id')->reject(fn ($id) => $id === $keeper->id)->all();
            if (! empty($duplicateIds)) {
                DB::table('prospector_agents')->whereIn('id', $duplicateIds)->delete();
            }
        }

        // Step 2: Sync each remaining agent's primary company into the pivot
        // (so the pivot is the source of truth going forward)
        DB::table('prospector_agents')->whereNotNull('company_id')->orderBy('id')->each(function ($agent) {
            DB::table('prospector_agent_company')->updateOrInsert(
                ['agent_id' => $agent->id, 'company_id' => $agent->company_id],
                ['created_at' => now(), 'updated_at' => now()],
            );
        });

        // Step 3: Add new unique on user_id BEFORE dropping the old composite,
        // so foreign keys can switch to using the new index.
        Schema::table('prospector_agents', function (Blueprint $table) {
            $table->unique('user_id', 'prospector_agents_user_id_unique');
        });

        // Now safe to drop the old composite unique
        Schema::table('prospector_agents', function (Blueprint $table) {
            try {
                $table->dropUnique('prospector_agents_user_id_company_id_unique');
            } catch (\Throwable) {
                // Index might not exist
            }
        });
    }

    public function down(): void
    {
        Schema::table('prospector_agents', function (Blueprint $table) {
            try {
                $table->dropUnique('prospector_agents_user_id_unique');
            } catch (\Throwable) {
            }
        });

        Schema::table('prospector_agents', function (Blueprint $table) {
            $table->unique(['user_id', 'company_id'], 'prospector_agents_user_id_company_id_unique');
        });
    }
};
