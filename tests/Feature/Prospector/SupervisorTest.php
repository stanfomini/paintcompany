<?php

use App\Events\Prospector\SupervisorJoined;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\Prospector\TwilioAccount;
use App\Models\User;
use App\Services\Prospector\ConferenceService;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->user = User::factory()->internal()->create();
    $this->agent = Agent::factory()->forCompany($this->company)->create([
        'user_id' => $this->user->id,
        'status' => 'on_call',
    ]);
});

// ──────────────────────────────────────
// ConferenceService: supervisorListen
// ──────────────────────────────────────

it('has supervisorListen method that joins muted', function () {
    $service = new ConferenceService;

    $reflection = new ReflectionMethod($service, 'supervisorListen');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('conferenceName');
    expect($params[0]->getType()->getName())->toBe('string');
    expect($params[1]->getName())->toBe('supervisor');
    expect($params[1]->getType()->getName())->toBe('App\Models\Prospector\Agent');
    expect($reflection->getReturnType()->getName())->toBe('string');

    // Verify implementation uses muted=true, beep=false
    $source = file_get_contents($reflection->getFileName());
    $lines = array_slice(explode("\n", $source), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);
    $body = implode("\n", $lines);

    expect($body)->toContain("'muted' => true");
    expect($body)->toContain("'beep' => false");
    expect($body)->toContain("'coaching' => false");
});

// ──────────────────────────────────────
// ConferenceService: supervisorWhisper
// ──────────────────────────────────────

it('has supervisorWhisper method that uses coaching mode', function () {
    $service = new ConferenceService;

    $reflection = new ReflectionMethod($service, 'supervisorWhisper');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('conferenceName');
    expect($params[1]->getName())->toBe('supervisor');
    expect($params[1]->getType()->getName())->toBe('App\Models\Prospector\Agent');
    expect($params[2]->getName())->toBe('agentCallSid');
    expect($params[2]->getType()->getName())->toBe('string');
    expect($reflection->getReturnType()->getName())->toBe('string');

    // Verify implementation uses coaching=true and callSidToCoach
    $source = file_get_contents($reflection->getFileName());
    $lines = array_slice(explode("\n", $source), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);
    $body = implode("\n", $lines);

    expect($body)->toContain("'coaching' => true");
    expect($body)->toContain("'callSidToCoach'");
    expect($body)->toContain('agentCallSid');
});

// ──────────────────────────────────────
// ConferenceService: supervisorBarge
// ──────────────────────────────────────

it('has supervisorBarge method that joins unmuted', function () {
    $service = new ConferenceService;

    $reflection = new ReflectionMethod($service, 'supervisorBarge');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('conferenceName');
    expect($params[1]->getName())->toBe('supervisor');
    expect($params[1]->getType()->getName())->toBe('App\Models\Prospector\Agent');
    expect($reflection->getReturnType()->getName())->toBe('string');

    // Verify implementation uses muted=false, beep=false
    $source = file_get_contents($reflection->getFileName());
    $lines = array_slice(explode("\n", $source), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);
    $body = implode("\n", $lines);

    expect($body)->toContain("'muted' => false");
    expect($body)->toContain("'beep' => false");
});

// ──────────────────────────────────────
// SupervisorJoined Event
// ──────────────────────────────────────

it('broadcasts SupervisorJoined event on correct channel', function () {
    $supervisorUser = User::factory()->internal()->create(['name' => 'Manager Kim']);
    $supervisor = Agent::factory()->forCompany($this->company)->create([
        'user_id' => $supervisorUser->id,
    ]);

    $event = new SupervisorJoined($supervisor, $this->agent->id, 'listen');

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-prospector.agent.'.$this->agent->id);

    expect($event->broadcastAs())->toBe('supervisor.joined');
});

it('includes supervisor_name and mode in SupervisorJoined payload', function () {
    $supervisorUser = User::factory()->internal()->create(['name' => 'Manager Kim']);
    $supervisor = Agent::factory()->forCompany($this->company)->create([
        'user_id' => $supervisorUser->id,
    ]);

    $event = new SupervisorJoined($supervisor, $this->agent->id, 'whisper');
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('supervisor_name');
    expect($payload['supervisor_name'])->toBe('Manager Kim');
    expect($payload)->toHaveKey('mode');
    expect($payload['mode'])->toBe('whisper');
});

it('SupervisorJoined event supports all three modes', function () {
    $supervisorUser = User::factory()->internal()->create();
    $supervisor = Agent::factory()->forCompany($this->company)->create([
        'user_id' => $supervisorUser->id,
    ]);

    foreach (['listen', 'whisper', 'barge'] as $mode) {
        $event = new SupervisorJoined($supervisor, $this->agent->id, $mode);
        $payload = $event->broadcastWith();

        expect($payload['mode'])->toBe($mode);
    }
});
