<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use App\Services\JobService;
use Livewire\Livewire;

describe('Job Assignment', function () {
    describe('migration', function () {
        it('has sales_person_id column on painting_jobs', function () {
            $user = User::factory()->internal()->create();
            $job = Job::factory()->create(['sales_person_id' => $user->id]);

            expect($job->fresh()->sales_person_id)->toBe($user->id);
        });

        it('has assigned_to column on painting_jobs', function () {
            $user = User::factory()->internal()->create();
            $job = Job::factory()->create(['assigned_to' => $user->id]);

            expect($job->fresh()->assigned_to)->toBe($user->id);
        });
    });

    describe('relationships', function () {
        it('has salesPerson relationship', function () {
            $salesPerson = User::factory()->internal()->create();
            $job = Job::factory()->create(['sales_person_id' => $salesPerson->id]);

            expect($job->salesPerson->id)->toBe($salesPerson->id);
        });

        it('has productionManager relationship', function () {
            $pm = User::factory()->internal()->create();
            $job = Job::factory()->create(['assigned_to' => $pm->id]);

            expect($job->productionManager->id)->toBe($pm->id);
        });

        it('handles null assignments gracefully', function () {
            $job = Job::factory()->create([
                'sales_person_id' => null,
                'assigned_to' => null,
            ]);

            expect($job->salesPerson)->toBeNull();
            expect($job->productionManager)->toBeNull();
        });

        it('nullifies sales_person_id when user is deleted', function () {
            $salesPerson = User::factory()->internal()->create();
            $job = Job::factory()->create(['sales_person_id' => $salesPerson->id]);

            $salesPerson->forceDelete();

            expect($job->fresh()->sales_person_id)->toBeNull();
        });

        it('nullifies assigned_to when user is deleted', function () {
            $pm = User::factory()->internal()->create();
            $job = Job::factory()->create(['assigned_to' => $pm->id]);

            $pm->forceDelete();

            expect($job->fresh()->assigned_to)->toBeNull();
        });
    });

    describe('auto-assign from deal', function () {
        it('sets sales_person_id from deal assigned_to on job creation', function () {
            $salesPerson = User::factory()->internal()->create();
            $deal = Deal::factory()->create(['assigned_to' => $salesPerson->id]);
            $estimate = Estimate::factory()->create([
                'deal_id' => $deal->id,
                'status' => 'accepted',
            ]);

            $job = app(JobService::class)->createFromEstimate($estimate);

            expect($job->sales_person_id)->toBe($salesPerson->id);
        });

        it('handles null deal assigned_to gracefully', function () {
            $deal = Deal::factory()->create(['assigned_to' => null]);
            $estimate = Estimate::factory()->create([
                'deal_id' => $deal->id,
                'status' => 'accepted',
            ]);

            $job = app(JobService::class)->createFromEstimate($estimate);

            expect($job->sales_person_id)->toBeNull();
        });
    });

    describe('production manager assignment UI', function () {
        it('can assign production manager via show page', function () {
            $admin = User::factory()->internal()->create();
            $admin->givePermissionTo(['jobs.view', 'jobs.edit']);

            $pm = User::factory()->internal()->create(['name' => 'Jane Manager']);
            $job = Job::factory()->create();

            Livewire::actingAs($admin)
                ->test('jobs.show', ['job' => $job])
                ->assertStatus(200)
                ->call('assignProductionManager', $pm->id);

            expect($job->fresh()->assigned_to)->toBe($pm->id);
        });

        it('can unassign production manager', function () {
            $admin = User::factory()->internal()->create();
            $admin->givePermissionTo(['jobs.view', 'jobs.edit']);

            $pm = User::factory()->internal()->create();
            $job = Job::factory()->create(['assigned_to' => $pm->id]);

            Livewire::actingAs($admin)
                ->test('jobs.show', ['job' => $job])
                ->call('assignProductionManager', null);

            expect($job->fresh()->assigned_to)->toBeNull();
        });

        it('forbids production manager assignment without jobs.edit permission', function () {
            $viewer = User::factory()->internal()->create();
            $viewer->givePermissionTo('jobs.view');

            $pm = User::factory()->internal()->create();
            $job = Job::factory()->create();

            Livewire::actingAs($viewer)
                ->test('jobs.show', ['job' => $job])
                ->call('assignProductionManager', $pm->id)
                ->assertForbidden();
        });

        it('displays sales person name on show page', function () {
            $admin = User::factory()->internal()->create();
            $admin->givePermissionTo('jobs.view');

            $salesPerson = User::factory()->internal()->create(['name' => 'Alice Sales']);
            $job = Job::factory()->create(['sales_person_id' => $salesPerson->id]);

            Livewire::actingAs($admin)
                ->test('jobs.show', ['job' => $job])
                ->assertSee('Alice Sales');
        });
    });

    describe('kanban display', function () {
        it('shows sales person and production manager on kanban cards', function () {
            $admin = User::factory()->internal()->create();
            $admin->givePermissionTo('jobs.view');

            $salesPerson = User::factory()->internal()->create(['name' => 'Bob Sales']);
            $pm = User::factory()->internal()->create(['name' => 'Carol Manager']);
            Job::factory()->create([
                'sales_person_id' => $salesPerson->id,
                'assigned_to' => $pm->id,
            ]);

            Livewire::actingAs($admin)
                ->test('jobs.kanban')
                ->assertSee('Bob')
                ->assertSee('Carol');
        });
    });
});
