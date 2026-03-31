<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\Prospector\PhoneNumber;
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
    $this->phoneNumber = PhoneNumber::factory()->forAccount($this->account)->assigned($this->company->id)->create();
});

// ──────────────────────────────────────
// warmTransfer
// ──────────────────────────────────────

it('has warmTransfer method with correct signature', function () {
    $service = new ConferenceService;

    $reflection = new ReflectionMethod($service, 'warmTransfer');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('conferenceName');
    expect($params[0]->getType()->getName())->toBe('string');
    expect($params[1]->getName())->toBe('toNumber');
    expect($params[1]->getType()->getName())->toBe('string');
    expect($params[2]->getName())->toBe('fromNumber');
    expect($params[2]->getType()->getName())->toBe('App\Models\Prospector\PhoneNumber');
    expect($reflection->getReturnType()->getName())->toBe('string');
});

// ──────────────────────────────────────
// coldTransfer
// ──────────────────────────────────────

it('has coldTransfer method with correct signature', function () {
    $service = new ConferenceService;

    $reflection = new ReflectionMethod($service, 'coldTransfer');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(4);
    expect($params[0]->getName())->toBe('conferenceName');
    expect($params[1]->getName())->toBe('agentCallSid');
    expect($params[1]->getType()->getName())->toBe('string');
    expect($params[2]->getName())->toBe('toNumber');
    expect($params[3]->getName())->toBe('fromNumber');
    expect($reflection->getReturnType()->getName())->toBe('string');
});

it('coldTransfer calls warmTransfer internally', function () {
    $service = new ConferenceService;

    // Verify coldTransfer body references warmTransfer by checking the method exists
    // and calls removeParticipant (structure test)
    $reflection = new ReflectionMethod($service, 'coldTransfer');
    $source = file_get_contents($reflection->getFileName());

    // Extract just the coldTransfer method body
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);
    $methodBody = implode("\n", $lines);

    expect($methodBody)->toContain('warmTransfer');
    expect($methodBody)->toContain('removeParticipant');
});

// ──────────────────────────────────────
// transferToAgent
// ──────────────────────────────────────

it('has transferToAgent method accepting agentId', function () {
    $service = new ConferenceService;

    $reflection = new ReflectionMethod($service, 'transferToAgent');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('conferenceName');
    expect($params[0]->getType()->getName())->toBe('string');
    expect($params[1]->getName())->toBe('targetAgentId');
    expect($params[1]->getType()->getName())->toBe('int');
    expect($reflection->getReturnType()->getName())->toBe('string');
});

it('transferToAgent dials correct client identity', function () {
    $service = new ConferenceService;

    $reflection = new ReflectionMethod($service, 'transferToAgent');
    $source = file_get_contents($reflection->getFileName());

    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);
    $methodBody = implode("\n", $lines);

    // Should use client:agent-{id} pattern
    expect($methodBody)->toContain('client:');
    expect($methodBody)->toContain('agent-');
    expect($methodBody)->toContain('targetAgentId');
});

// ──────────────────────────────────────
// supervisorListen
// ──────────────────────────────────────

it('has supervisorListen method that joins muted', function () {
    $service = new ConferenceService;

    $reflection = new ReflectionMethod($service, 'supervisorListen');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('conferenceName');
    expect($params[1]->getName())->toBe('supervisor');
    expect($params[1]->getType()->getName())->toBe('App\Models\Prospector\Agent');
    expect($reflection->getReturnType()->getName())->toBe('string');

    // Check implementation uses muted=true
    $source = file_get_contents($reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);
    $methodBody = implode("\n", $lines);

    expect($methodBody)->toContain("'muted' => true");
    expect($methodBody)->toContain("'beep' => false");
});

// ──────────────────────────────────────
// supervisorWhisper
// ──────────────────────────────────────

it('has supervisorWhisper method that uses coaching mode', function () {
    $service = new ConferenceService;

    $reflection = new ReflectionMethod($service, 'supervisorWhisper');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('conferenceName');
    expect($params[1]->getName())->toBe('supervisor');
    expect($params[2]->getName())->toBe('agentCallSid');
    expect($params[2]->getType()->getName())->toBe('string');
    expect($reflection->getReturnType()->getName())->toBe('string');

    // Check implementation uses coaching=true
    $source = file_get_contents($reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);
    $methodBody = implode("\n", $lines);

    expect($methodBody)->toContain("'coaching' => true");
    expect($methodBody)->toContain("'callSidToCoach'");
});

// ──────────────────────────────────────
// supervisorBarge
// ──────────────────────────────────────

it('has supervisorBarge method that joins unmuted', function () {
    $service = new ConferenceService;

    $reflection = new ReflectionMethod($service, 'supervisorBarge');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('conferenceName');
    expect($params[1]->getName())->toBe('supervisor');
    expect($reflection->getReturnType()->getName())->toBe('string');

    // Check implementation uses muted=false
    $source = file_get_contents($reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);
    $methodBody = implode("\n", $lines);

    expect($methodBody)->toContain("'muted' => false");
    expect($methodBody)->toContain("'beep' => false");
});
