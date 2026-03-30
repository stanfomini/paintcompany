<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\PhoneNumberService;

beforeEach(function () {
    $this->service = new PhoneNumberService;
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
});

// ─── assignToCompany ───────────────────────────────────

it('assigns an unassigned number to a company', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    $result = $this->service->assignToCompany($number, $this->company, 'local_presence');

    expect($result)
        ->company_id->toBe($this->company->id)
        ->role->toBe('local_presence');
});

it('rejects assigning an inactive number', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->inactive()->create();

    expect(fn () => $this->service->assignToCompany($number, $this->company))
        ->toThrow(InvalidArgumentException::class, 'inactive number');
});

it('rejects assigning a number already assigned to another company', function () {
    $otherCompany = Company::factory()->forAccount($this->account)->create();
    $number = PhoneNumber::factory()->forAccount($this->account)->assigned($otherCompany->id)->create();

    expect(fn () => $this->service->assignToCompany($number, $this->company))
        ->toThrow(InvalidArgumentException::class, 'already assigned');
});

it('allows force reassigning a number from another company', function () {
    $otherCompany = Company::factory()->forAccount($this->account)->create();
    $number = PhoneNumber::factory()->forAccount($this->account)->assigned($otherCompany->id)->create();

    $result = $this->service->assignToCompany($number, $this->company, 'local_presence', null, force: true);

    expect($result->company_id)->toBe($this->company->id);
});

it('allows reassigning a number to the same company with a different role', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->assigned($this->company->id)->create();

    $result = $this->service->assignToCompany($number, $this->company, 'shadow');

    expect($result->role)->toBe('shadow');
});

it('requires caller_id_override for caller_id_shadow role', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    expect(fn () => $this->service->assignToCompany($number, $this->company, 'caller_id_shadow'))
        ->toThrow(InvalidArgumentException::class, 'caller_id_override is required');
});

it('assigns with caller_id_override for caller_id_shadow role', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    $result = $this->service->assignToCompany(
        $number,
        $this->company,
        'caller_id_shadow',
        '+13035551234',
    );

    expect($result)
        ->role->toBe('caller_id_shadow')
        ->caller_id_override->toBe('+13035551234');
});

it('demotes existing main number when assigning a new main', function () {
    $oldMain = PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'role' => 'main',
    ]);
    $this->company->update(['main_phone_number_id' => $oldMain->id]);

    $newMain = PhoneNumber::factory()->forAccount($this->account)->create();

    $this->service->assignToCompany($newMain, $this->company, 'main');

    $oldMain->refresh();
    $newMain->refresh();

    expect($oldMain->role)->toBe('local_presence');
    expect($newMain->role)->toBe('main');
    expect($this->company->fresh()->main_phone_number_id)->toBe($newMain->id);
});

it('rejects invalid role', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    expect(fn () => $this->service->assignToCompany($number, $this->company, 'invalid_role'))
        ->toThrow(InvalidArgumentException::class, 'Invalid role');
});

// ─── unassign ──────────────────────────────────────────

it('unassigns a number from its company', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'role' => 'local_presence',
        'caller_id_override' => '+13035551234',
        'inbound_action' => 'voicemail',
        'inbound_forward_to' => '+17205551234',
    ]);

    $result = $this->service->unassign($number);

    expect($result)
        ->company_id->toBeNull()
        ->role->toBe('unassigned')
        ->caller_id_override->toBeNull()
        ->inbound_action->toBe('forward_main')
        ->inbound_forward_to->toBeNull();
});

it('clears company main_phone_number_id when unassigning main number', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
    ]);
    $this->company->update(['main_phone_number_id' => $number->id]);

    $this->service->unassign($number);

    expect($this->company->fresh()->main_phone_number_id)->toBeNull();
});

it('returns the number unchanged when already unassigned', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    $result = $this->service->unassign($number);

    expect($result->company_id)->toBeNull();
    expect($result->role)->toBe('unassigned');
});

// ─── setAsMain ─────────────────────────────────────────

it('sets a number as the main number for a company', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'role' => 'local_presence',
    ]);

    $result = $this->service->setAsMain($number, $this->company);

    expect($result->role)->toBe('main');
    expect($this->company->fresh()->main_phone_number_id)->toBe($number->id);
});

it('assigns and promotes an unassigned number to main', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    $result = $this->service->setAsMain($number, $this->company);

    expect($result)
        ->company_id->toBe($this->company->id)
        ->role->toBe('main');
});

it('rejects setting inactive number as main', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->inactive()->create();

    expect(fn () => $this->service->setAsMain($number, $this->company))
        ->toThrow(InvalidArgumentException::class, 'inactive number');
});

it('rejects setting number assigned to another company as main', function () {
    $otherCompany = Company::factory()->forAccount($this->account)->create();
    $number = PhoneNumber::factory()->forAccount($this->account)->assigned($otherCompany->id)->create();

    expect(fn () => $this->service->setAsMain($number, $this->company))
        ->toThrow(InvalidArgumentException::class, 'assigned to another company');
});

it('demotes existing main when setting a new main', function () {
    $oldMain = PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
    ]);
    $this->company->update(['main_phone_number_id' => $oldMain->id]);

    $newMain = PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'role' => 'local_presence',
    ]);

    $this->service->setAsMain($newMain, $this->company);

    expect($oldMain->fresh()->role)->toBe('local_presence');
    expect($newMain->fresh()->role)->toBe('main');
});

// ─── configureInbound ──────────────────────────────────

it('configures inbound action on a number', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    $result = $this->service->configureInbound($number, 'voicemail');

    expect($result->inbound_action)->toBe('voicemail');
});

it('configures forward_main with a forwarding number', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->assigned($this->company->id)->create();

    $result = $this->service->configureInbound($number, 'forward_main', '+13035551234');

    expect($result)
        ->inbound_action->toBe('forward_main')
        ->inbound_forward_to->toBe('+13035551234');
});

it('rejects invalid inbound action', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    expect(fn () => $this->service->configureInbound($number, 'invalid_action'))
        ->toThrow(InvalidArgumentException::class, 'Invalid inbound action');
});

it('rejects invalid forward_to phone format', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    expect(fn () => $this->service->configureInbound($number, 'voicemail', 'not-a-phone'))
        ->toThrow(InvalidArgumentException::class, 'E.164 format');
});

it('accepts all valid inbound actions', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->assigned($this->company->id)->create();

    foreach (['forward_main', 'voicemail', 'ivr', 'queue', 'ignore'] as $action) {
        $result = $this->service->configureInbound($number, $action);
        expect($result->inbound_action)->toBe($action);
    }
});

// ─── getUnassignedNumbers ──────────────────────────────

it('returns unassigned active numbers for an account', function () {
    PhoneNumber::factory()->forAccount($this->account)->count(3)->create();
    PhoneNumber::factory()->forAccount($this->account)->assigned($this->company->id)->create();
    PhoneNumber::factory()->forAccount($this->account)->inactive()->create();

    $unassigned = $this->service->getUnassignedNumbers($this->account->id);

    expect($unassigned)->toHaveCount(3);
});

it('filters unassigned numbers by area code', function () {
    PhoneNumber::factory()->forAccount($this->account)->withAreaCode('720')->count(2)->create();
    PhoneNumber::factory()->forAccount($this->account)->withAreaCode('303')->create();

    $filtered = $this->service->getUnassignedNumbers($this->account->id, '720');

    expect($filtered)->toHaveCount(2);
    expect($filtered->every(fn ($n) => $n->area_code === '720'))->toBeTrue();
});

// ─── bulkAssign ────────────────────────────────────────

it('bulk assigns multiple numbers to a company', function () {
    $numbers = PhoneNumber::factory()->forAccount($this->account)->count(5)->create();
    $ids = $numbers->pluck('id')->toArray();

    $assigned = $this->service->bulkAssign($ids, $this->company);

    expect($assigned)->toBe(5);

    foreach ($numbers as $number) {
        $number->refresh();
        expect($number->company_id)->toBe($this->company->id);
        expect($number->role)->toBe('local_presence');
    }
});

it('skips inactive numbers in bulk assign', function () {
    $active = PhoneNumber::factory()->forAccount($this->account)->create();
    $inactive = PhoneNumber::factory()->forAccount($this->account)->inactive()->create();

    $assigned = $this->service->bulkAssign(
        [$active->id, $inactive->id],
        $this->company,
    );

    expect($assigned)->toBe(1);
    expect($active->fresh()->company_id)->toBe($this->company->id);
    expect($inactive->fresh()->company_id)->toBeNull();
});

it('skips numbers assigned to other companies in bulk assign', function () {
    $otherCompany = Company::factory()->forAccount($this->account)->create();
    $free = PhoneNumber::factory()->forAccount($this->account)->create();
    $taken = PhoneNumber::factory()->forAccount($this->account)->assigned($otherCompany->id)->create();

    $assigned = $this->service->bulkAssign(
        [$free->id, $taken->id],
        $this->company,
    );

    expect($assigned)->toBe(1);
});

it('rejects bulk assign with main role', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    expect(fn () => $this->service->bulkAssign([$number->id], $this->company, 'main'))
        ->toThrow(InvalidArgumentException::class, 'Cannot bulk assign');
});

it('rejects bulk assign with caller_id_shadow role', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    expect(fn () => $this->service->bulkAssign([$number->id], $this->company, 'caller_id_shadow'))
        ->toThrow(InvalidArgumentException::class, 'Cannot bulk assign');
});

// ─── getCompanyNumberSummary ───────────────────────────

it('returns correct number summary for a company', function () {
    PhoneNumber::factory()->forAccount($this->account)->main()->create(['company_id' => $this->company->id]);
    PhoneNumber::factory()->forAccount($this->account)->localPresence()->count(3)->create(['company_id' => $this->company->id]);
    PhoneNumber::factory()->forAccount($this->account)->shadow()->count(2)->create(['company_id' => $this->company->id]);

    $summary = $this->service->getCompanyNumberSummary($this->company);

    expect($summary)->toBe([
        'total' => 6,
        'main' => 1,
        'local_presence' => 3,
        'shadow' => 2,
        'caller_id_shadow' => 0,
        'unassigned' => 0,
    ]);
});

it('returns zero counts for company with no numbers', function () {
    $summary = $this->service->getCompanyNumberSummary($this->company);

    expect($summary['total'])->toBe(0);
});
