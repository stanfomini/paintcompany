<?php

use App\Livewire\Customer\Projects\Index;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Projects Index component', function () {
    it('renders the projects index for authenticated customers', function () {
        $customerUser = User::factory()->customer()->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->assertStatus(200)
            ->assertSee('My Projects');
    });

    it('displays customer projects', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Kitchen Painting',
            'stage' => 'in_progress',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->assertSee('Kitchen Painting')
            ->assertSee('In Progress');
    });

    it('does not display other customers projects', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $otherCustomer = Customer::factory()->create();

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'My Project',
        ]);

        Deal::factory()->create([
            'customer_id' => $otherCustomer->id,
            'title' => 'Other Project',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->assertSee('My Project')
            ->assertDontSee('Other Project');
    });

    it('can search projects by title', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Kitchen Painting',
        ]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Bathroom Remodel',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->set('search', 'Kitchen')
            ->assertSee('Kitchen Painting')
            ->assertDontSee('Bathroom Remodel');
    });

    it('can filter projects by stage', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Active Project',
            'stage' => 'in_progress',
        ]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Completed Project',
            'stage' => 'completed',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->set('stage', 'in_progress')
            ->assertSee('Active Project')
            ->assertDontSee('Completed Project');
    });

    it('shows empty state when no projects exist', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->assertSee('No projects found')
            ->assertSee('Your projects will appear here once created');
    });

    it('shows empty state when search yields no results', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Existing Project',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->set('search', 'NonexistentProject')
            ->assertSee('No projects found')
            ->assertSee('Try adjusting your search criteria');
    });

    it('resets pagination when search changes', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->count(20)->create(['customer_id' => $customer->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when stage filter changes', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Deal::factory()->count(20)->create(['customer_id' => $customer->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->set('stage', 'in_progress')
            ->assertSet('paginators.page', 1);
    });

    it('displays all stage options', function () {
        $customerUser = User::factory()->customer()->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test(Index::class)
            ->assertSee('All Stages')
            ->assertSee('Lead')
            ->assertSee('Quoted')
            ->assertSee('Scheduled')
            ->assertSee('In Progress')
            ->assertSee('Completed');
    });
});
