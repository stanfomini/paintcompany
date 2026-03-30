<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\AgentStateService;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->service = new AgentStateService;
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
});

it('sets agent status in database', function () {
    $agent = Agent::factory()->forCompany($this->company)->offline()->create();

    $this->service->setStatus($agent, 'available');

    $agent->refresh();
    expect($agent->status)->toBe('available');
    expect($agent->status_changed_at)->not->toBeNull();
});

it('rejects invalid status values', function () {
    $agent = Agent::factory()->forCompany($this->company)->create();

    $this->service->setStatus($agent, 'invalid_status');
})->throws(InvalidArgumentException::class, 'Invalid agent status');

it('transitions through valid statuses', function () {
    $agent = Agent::factory()->forCompany($this->company)->offline()->create();

    $validStatuses = ['available', 'on_call', 'wrap_up', 'break', 'ringing', 'offline'];

    foreach ($validStatuses as $status) {
        $this->service->setStatus($agent, $status);
        $agent->refresh();
        expect($agent->status)->toBe($status);
    }
});

it('returns available agents for a company', function () {
    Agent::factory()->forCompany($this->company)->available()->create();
    Agent::factory()->forCompany($this->company)->available()->create();
    Agent::factory()->forCompany($this->company)->offline()->create();

    $available = $this->service->getAvailableAgents($this->company->id);

    expect($available)->toHaveCount(2);
});

it('checks if agent is available', function () {
    $available = Agent::factory()->forCompany($this->company)->available()->create();
    $offline = Agent::factory()->forCompany($this->company)->offline()->create();

    // Mock Redis to avoid dependency
    Redis::shouldReceive('get')->andReturn(null);
    Redis::shouldReceive('setex')->andReturn(true);

    expect($this->service->isAvailable($available))->toBeTrue();
    expect($this->service->isAvailable($offline))->toBeFalse();
});

it('resets daily counts for all agents', function () {
    Agent::factory()->forCompany($this->company)->create([
        'daily_call_count' => 50,
        'daily_talk_minutes' => 120,
    ]);
    Agent::factory()->forCompany($this->company)->create([
        'daily_call_count' => 30,
        'daily_talk_minutes' => 60,
    ]);

    $this->service->resetDailyCounts();

    $agents = Agent::where('company_id', $this->company->id)->get();
    foreach ($agents as $agent) {
        expect($agent->daily_call_count)->toBe(0);
        expect($agent->daily_talk_minutes)->toBe(0);
    }
});
