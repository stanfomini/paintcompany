<?php

use App\Livewire\Agent\Dashboard;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Dashboard component', function () {
    it('renders the dashboard for authenticated agents', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertStatus(200)
            ->assertSee('Welcome');
    });

    it('displays commission stats section', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertSee('Commission Overview')
            ->assertSee('Total Earned')
            ->assertSee('Pending')
            ->assertSee('This Month');
    });

    it('displays performance metrics section', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertSee('Performance Metrics')
            ->assertSee('Conversion Rate')
            ->assertSee('Avg Deal Value')
            ->assertSee('Total Referral Value');
    });

    it('displays deals by stage section', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertSee('Deals by Stage');
    });

    it('displays recent deals section', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertSee('Recent Deals');
    });

    it('shows empty state when no deals exist', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertSee('No deals yet.')
            ->assertSee('Deals from your referred customers will appear here.');
    });

    it('displays recent deals with customer name', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'title' => 'Kitchen Painting',
            'estimated_value' => 5000,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertSee('Kitchen Painting')
            ->assertSee('John Doe');
    });

    it('keeps existing stats: listings, customers, open houses', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertSee('Listings & Referrals')
            ->assertSee('Total Listings')
            ->assertSee('Active Listings')
            ->assertSee('Referred Customers')
            ->assertSee('Upcoming Open Houses');
    });
});

describe('Agent Dashboard commission calculations', function () {
    it('calculates total earned commission from completed and paid deals', function () {
        $agent = User::factory()->agent()->create();
        $agent->agentProfile->update(['commission_rate' => 10]);
        $customer = Customer::factory()->referredByAgent($agent)->create();

        // Completed deal worth $10,000 (commission: $1,000)
        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->completed()->create([
            'actual_value' => 10000,
        ]);

        // Paid deal worth $5,000 (commission: $500)
        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->paid()->create([
            'actual_value' => 5000,
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $commissionStats = $component->viewData('commissionStats');

        expect($commissionStats['total_earned'])->toBe(1500.0);
    });

    it('calculates pending commission from active deals', function () {
        $agent = User::factory()->agent()->create();
        $agent->agentProfile->update(['commission_rate' => 10]);
        $customer = Customer::factory()->referredByAgent($agent)->create();

        // Lead deal worth $10,000 (pending commission: $1,000)
        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->lead()->create([
            'estimated_value' => 10000,
        ]);

        // Quoted deal worth $8,000 (pending commission: $800)
        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->quoted()->create([
            'estimated_value' => 8000,
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $commissionStats = $component->viewData('commissionStats');

        expect($commissionStats['pending'])->toBe(1800.0);
    });

    it('calculates this month commission correctly', function () {
        $agent = User::factory()->agent()->create();
        $agent->agentProfile->update(['commission_rate' => 10]);
        $customer = Customer::factory()->referredByAgent($agent)->create();

        // Completed deal this month worth $10,000 (commission: $1,000)
        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'stage' => 'completed',
            'actual_value' => 10000,
            'updated_at' => now(),
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $commissionStats = $component->viewData('commissionStats');

        expect($commissionStats['this_month'])->toBe(1000.0);
    });

    it('excludes cancelled deals from pending commission', function () {
        $agent = User::factory()->agent()->create();
        $agent->agentProfile->update(['commission_rate' => 10]);
        $customer = Customer::factory()->referredByAgent($agent)->create();

        // Cancelled deal should not count
        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->cancelled()->create([
            'estimated_value' => 10000,
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $commissionStats = $component->viewData('commissionStats');

        expect($commissionStats['pending'])->toBe(0.0);
    });

    it('uses default commission rate of 10% when agent profile has no rate', function () {
        $agent = User::factory()->agent()->create();
        $agent->agentProfile->update(['commission_rate' => null]);
        $customer = Customer::factory()->referredByAgent($agent)->create();

        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->completed()->create([
            'actual_value' => 10000,
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        expect($component->viewData('commissionRate'))->toBe(10);
        expect($component->viewData('commissionStats')['total_earned'])->toBe(1000.0);
    });

    it('uses agent custom commission rate', function () {
        $agent = User::factory()->agent()->create();
        $agent->agentProfile->update(['commission_rate' => 15]);
        $customer = Customer::factory()->referredByAgent($agent)->create();

        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->completed()->create([
            'actual_value' => 10000,
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        expect((float) $component->viewData('commissionRate'))->toBe(15.0);
        expect($component->viewData('commissionStats')['total_earned'])->toBe(1500.0);
    });
});

describe('Agent Dashboard performance metrics', function () {
    it('calculates conversion rate correctly', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        // 2 won deals out of 5 total = 40% conversion rate
        Deal::factory()->count(2)->referredByAgent($agent)->forCustomer($customer)->completed()->create();
        Deal::factory()->count(3)->referredByAgent($agent)->forCustomer($customer)->lead()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $performanceMetrics = $component->viewData('performanceMetrics');

        expect($performanceMetrics['conversion_rate'])->toBe(40.0);
    });

    it('handles zero deals gracefully for conversion rate', function () {
        $agent = User::factory()->agent()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $performanceMetrics = $component->viewData('performanceMetrics');

        expect($performanceMetrics['conversion_rate'])->toBe(0.0);
    });

    it('calculates average deal value correctly', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'estimated_value' => 5000,
        ]);

        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'estimated_value' => 15000,
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $performanceMetrics = $component->viewData('performanceMetrics');

        // Average of 5000 and 15000 = 10000
        expect($performanceMetrics['average_deal_value'])->toBe(10000.0);
    });

    it('calculates total referral value correctly', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'estimated_value' => 5000,
        ]);

        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'estimated_value' => 15000,
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $performanceMetrics = $component->viewData('performanceMetrics');

        expect($performanceMetrics['total_referral_value'])->toBe(20000.0);
    });
});

describe('Agent Dashboard deals by stage', function () {
    it('returns correct counts for each stage', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        Deal::factory()->count(3)->referredByAgent($agent)->forCustomer($customer)->lead()->create();
        Deal::factory()->count(2)->referredByAgent($agent)->forCustomer($customer)->quoted()->create();
        Deal::factory()->count(1)->referredByAgent($agent)->forCustomer($customer)->completed()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $dealsByStage = $component->viewData('dealsByStage');

        expect($dealsByStage['lead'])->toBe(3);
        expect($dealsByStage['quoted'])->toBe(2);
        expect($dealsByStage['completed'])->toBe(1);
        expect($dealsByStage['paid'])->toBe(0);
    });

    it('does not count deals from other agents', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();

        $customer = Customer::factory()->referredByAgent($agent)->create();
        $otherCustomer = Customer::factory()->referredByAgent($otherAgent)->create();

        Deal::factory()->count(3)->referredByAgent($agent)->forCustomer($customer)->lead()->create();
        Deal::factory()->count(5)->referredByAgent($otherAgent)->forCustomer($otherCustomer)->lead()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $dealsByStage = $component->viewData('dealsByStage');

        expect($dealsByStage['lead'])->toBe(3);
    });
});

describe('Agent Dashboard monthly deal counts', function () {
    it('returns counts for last 6 months', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create();

        // Create a deal this month
        Deal::factory()->referredByAgent($agent)->forCustomer($customer)->create([
            'created_at' => now(),
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        $monthlyDealCounts = $component->viewData('monthlyDealCounts');

        expect(count($monthlyDealCounts))->toBe(6);

        // The current month should have at least 1 deal
        $currentMonthKey = now()->format('M Y');
        expect($monthlyDealCounts[$currentMonthKey])->toBeGreaterThanOrEqual(1);
    });
});

describe('Agent Dashboard stage badge colors', function () {
    it('returns correct badge color for each stage', function () {
        $agent = User::factory()->agent()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class);

        expect($component->instance()->getStageBadgeColor('lead'))->toBe('blue');
        expect($component->instance()->getStageBadgeColor('quoted'))->toBe('purple');
        expect($component->instance()->getStageBadgeColor('scheduled'))->toBe('yellow');
        expect($component->instance()->getStageBadgeColor('in_progress'))->toBe('orange');
        expect($component->instance()->getStageBadgeColor('completed'))->toBe('green');
        expect($component->instance()->getStageBadgeColor('paid'))->toBe('emerald');
        expect($component->instance()->getStageBadgeColor('cancelled'))->toBe('red');
        expect($component->instance()->getStageBadgeColor('unknown'))->toBe('zinc');
    });
});

describe('Agent Dashboard empty states', function () {
    it('handles agent with no deals gracefully', function () {
        $agent = User::factory()->agent()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertStatus(200);

        $commissionStats = $component->viewData('commissionStats');
        $performanceMetrics = $component->viewData('performanceMetrics');

        expect($commissionStats['total_earned'])->toBe(0.0);
        expect($commissionStats['pending'])->toBe(0.0);
        expect($commissionStats['this_month'])->toBe(0.0);
        expect($performanceMetrics['conversion_rate'])->toBe(0.0);
        expect($performanceMetrics['average_deal_value'])->toBe(0.0);
        expect($performanceMetrics['total_referral_value'])->toBe(0.0);
    });

    it('handles agent with no listings gracefully', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertStatus(200)
            ->assertSee('No upcoming open houses scheduled.');
    });

    it('handles agent with no customers gracefully', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Dashboard::class)
            ->assertStatus(200)
            ->assertSee('No customers referred yet.');
    });
});

describe('Agent Dashboard page access', function () {
    it('redirects guests to agent login page', function () {
        $this->get(route('agent.dashboard'))
            ->assertRedirect(route('agent.login'));
    });

    it('renders dashboard page for authenticated agent', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'agent')
            ->get(route('agent.dashboard'))
            ->assertSuccessful()
            ->assertSeeLivewire(Dashboard::class);
    });

    it('forbids internal users from accessing agent dashboard', function () {
        $internalUser = User::factory()->internal()->create();

        $this->actingAs($internalUser, 'agent')
            ->get(route('agent.dashboard'))
            ->assertForbidden();
    });

    it('forbids customer users from accessing agent dashboard', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'agent')
            ->get(route('agent.dashboard'))
            ->assertForbidden();
    });
});
