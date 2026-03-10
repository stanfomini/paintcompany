<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmailCrmLink;
use App\Models\EmailMessage;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Property;
use App\Models\User;
use App\Models\VideoCall;
use App\Models\VideoRecording;
use App\Services\CustomerDeletionService;
use Livewire\Livewire;

describe('Customer Deletion - Permissions', function () {
    it('allows users with people.delete permission to delete', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();

        Livewire::actingAs($user)
            ->test('customers.index')
            ->call('confirmDelete', $customer->id)
            ->assertSet('showDeleteConfirm', true)
            ->call('deleteCustomer')
            ->assertHasNoErrors();

        expect(Customer::withTrashed()->find($customer->id))->toBeNull();
    });

    it('forbids users without people.delete permission from deleting via index', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');
        $customer = Customer::factory()->minimal()->create();

        Livewire::actingAs($user)
            ->test('customers.index')
            ->call('confirmDelete', $customer->id)
            ->assertForbidden();
    });

    it('forbids users without people.delete permission from deleting via show', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');
        $customer = Customer::factory()->minimal()->create();

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('confirmDelete')
            ->assertForbidden();
    });

    it('shows delete menu item only to users with people.delete permission on index', function () {
        $owner = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($owner)->create();

        Livewire::actingAs($owner)
            ->test('customers.index')
            ->assertSeeHtml('wire:click="confirmDelete('.$customer->id.')"');
    });

    it('hides delete menu item from users without people.delete permission on index', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');
        Customer::factory()->minimal()->create();

        Livewire::actingAs($user)
            ->test('customers.index')
            ->assertDontSeeHtml('confirmDelete');
    });
});

describe('Customer Deletion - Happy Path', function () {
    it('deletes a customer and all related data from the index page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();
        $property = Property::factory()->create(['customer_id' => $customer->id]);
        $deal = Deal::factory()->forCustomer($customer)->create(['created_by' => $user->id]);
        $estimate = Estimate::factory()->forDeal($deal)->create(['created_by' => $user->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('customers.index')
            ->call('confirmDelete', $customer->id)
            ->assertSet('showDeleteConfirm', true)
            ->call('deleteCustomer')
            ->assertSet('showDeleteConfirm', false);

        expect(Customer::withTrashed()->find($customer->id))->toBeNull();
        expect(Property::find($property->id))->toBeNull();
        expect(Deal::withTrashed()->find($deal->id))->toBeNull();
        expect(Estimate::find($estimate->id))->toBeNull();
        expect(Job::withTrashed()->find($job->id))->toBeNull();
    });

    it('deletes a customer from the show page and redirects', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('confirmDelete')
            ->assertSet('showDeleteConfirm', true)
            ->call('deleteCustomer')
            ->assertRedirect(route('customers.index'));

        expect(Customer::withTrashed()->find($customer->id))->toBeNull();
    });
});

describe('Customer Deletion - Polymorphic Cleanup', function () {
    it('deletes polymorphic activities for customer, deals, and jobs', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->create(['created_by' => $user->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Activity::create([
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'user_id' => $user->id,
            'type' => 'note',
            'title' => 'Customer activity',
        ]);
        Activity::create([
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'user_id' => $user->id,
            'type' => 'note',
            'title' => 'Deal activity',
        ]);
        Activity::create([
            'subject_type' => Job::class,
            'subject_id' => $job->id,
            'user_id' => $user->id,
            'type' => 'note',
            'title' => 'Job activity',
        ]);

        // Unrelated activity should survive
        $otherCustomer = Customer::factory()->minimal()->create();
        $otherActivity = Activity::create([
            'subject_type' => Customer::class,
            'subject_id' => $otherCustomer->id,
            'user_id' => $user->id,
            'type' => 'note',
            'title' => 'Other activity',
        ]);

        app(CustomerDeletionService::class)->forceDelete($customer);

        expect(Activity::where('subject_type', Customer::class)->where('subject_id', $customer->id)->count())->toBe(0);
        expect(Activity::where('subject_type', Deal::class)->where('subject_id', $deal->id)->count())->toBe(0);
        expect(Activity::where('subject_type', Job::class)->where('subject_id', $job->id)->count())->toBe(0);
        expect(Activity::find($otherActivity->id))->not->toBeNull();
    });

    it('deletes polymorphic email links for customer and deals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->create(['created_by' => $user->id]);

        $emailMessage = EmailMessage::factory()->create();

        EmailCrmLink::create([
            'linkable_type' => Customer::class,
            'linkable_id' => $customer->id,
            'email_message_id' => $emailMessage->id,
            'link_type' => 'auto',
        ]);
        $emailMessage2 = EmailMessage::factory()->create();
        EmailCrmLink::create([
            'linkable_type' => Deal::class,
            'linkable_id' => $deal->id,
            'email_message_id' => $emailMessage2->id,
            'link_type' => 'auto',
        ]);

        app(CustomerDeletionService::class)->forceDelete($customer);

        expect(EmailCrmLink::where('linkable_type', Customer::class)->where('linkable_id', $customer->id)->count())->toBe(0);
        expect(EmailCrmLink::where('linkable_type', Deal::class)->where('linkable_id', $deal->id)->count())->toBe(0);
    });

    it('deletes polymorphic video calls for deals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->create(['created_by' => $user->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        VideoCall::factory()->create([
            'callable_type' => Deal::class,
            'callable_id' => $deal->id,
            'job_id' => $job->id,
            'initiated_by' => $user->id,
        ]);

        app(CustomerDeletionService::class)->forceDelete($customer);

        expect(VideoCall::where('callable_type', Deal::class)->where('callable_id', $deal->id)->count())->toBe(0);
    });

    it('deletes polymorphic video recordings for jobs', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->create(['created_by' => $user->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        VideoRecording::factory()->create([
            'recordable_type' => Job::class,
            'recordable_id' => $job->id,
            'user_id' => $user->id,
        ]);

        app(CustomerDeletionService::class)->forceDelete($customer);

        expect(VideoRecording::where('recordable_type', Job::class)->where('recordable_id', $job->id)->count())->toBe(0);
    });
});

describe('Customer Deletion - Summary', function () {
    it('returns correct counts in deletion summary', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();
        Property::factory()->create(['customer_id' => $customer->id]);
        Property::factory()->create(['customer_id' => $customer->id]);
        $deal1 = Deal::factory()->forCustomer($customer)->create(['created_by' => $user->id]);
        $deal2 = Deal::factory()->forCustomer($customer)->create(['created_by' => $user->id]);
        Estimate::factory()->forDeal($deal1)->create(['created_by' => $user->id]);
        Estimate::factory()->forDeal($deal1)->create(['created_by' => $user->id]);
        Estimate::factory()->forDeal($deal2)->create(['created_by' => $user->id]);
        Job::factory()->create(['deal_id' => $deal1->id]);

        $summary = app(CustomerDeletionService::class)->getDeletionSummary($customer);

        expect($summary)->toBe([
            'deals' => 2,
            'properties' => 2,
            'estimates' => 3,
            'jobs' => 1,
        ]);
    });
});

describe('Customer Deletion - Edge Cases', function () {
    it('deletes a customer with no related data', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();

        app(CustomerDeletionService::class)->forceDelete($customer);

        expect(Customer::withTrashed()->find($customer->id))->toBeNull();
    });

    it('also cleans up soft-deleted deals and jobs', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->create(['created_by' => $user->id]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        // Soft-delete the deal and job
        $deal->delete();
        $job->delete();

        // Verify they are soft-deleted but exist
        expect(Deal::withTrashed()->find($deal->id))->not->toBeNull();
        expect(Job::withTrashed()->find($job->id))->not->toBeNull();

        app(CustomerDeletionService::class)->forceDelete($customer);

        expect(Deal::withTrashed()->find($deal->id))->toBeNull();
        expect(Job::withTrashed()->find($job->id))->toBeNull();
    });

    it('resets state after deletion from index', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->minimal()->createdBy($user)->create();

        Livewire::actingAs($user)
            ->test('customers.index')
            ->call('confirmDelete', $customer->id)
            ->assertSet('deletingCustomerId', $customer->id)
            ->call('deleteCustomer')
            ->assertSet('deletingCustomerId', null)
            ->assertSet('showDeleteConfirm', false)
            ->assertSet('deletionSummary', []);
    });
});
