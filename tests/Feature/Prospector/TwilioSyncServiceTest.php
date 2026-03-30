<?php

use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\TwilioSyncService;

beforeEach(function () {
    $this->service = new TwilioSyncService;
    $this->account = TwilioAccount::factory()->create();
});

// ─── syncNumbers ───────────────────────────────────────

it('creates new phone numbers from twilio api response', function () {
    $mockNumbers = [
        makeTwilioNumber('PN001', '+17205551234', '(720) 555-1234'),
        makeTwilioNumber('PN002', '+13035559876', '(303) 555-9876'),
    ];

    $client = buildMockClient($mockNumbers);

    $result = $this->service->syncNumbers($this->account, $client);

    expect($result)->toBe(['created' => 2, 'updated' => 0, 'removed' => 0]);
    expect(PhoneNumber::where('twilio_account_id', $this->account->id)->count())->toBe(2);

    $number = PhoneNumber::where('twilio_sid', 'PN001')->first();
    expect($number)
        ->phone_number->toBe('+17205551234')
        ->friendly_name->toBe('(720) 555-1234')
        ->area_code->toBe('720')
        ->is_active->toBeTrue();
    expect($number->capabilities)->toBe([
        'voice' => true,
        'sms' => true,
        'mms' => true,
        'fax' => false,
    ]);
});

it('updates existing phone numbers on re-sync', function () {
    PhoneNumber::factory()->forAccount($this->account)->create([
        'twilio_sid' => 'PN001',
        'phone_number' => '+17205551234',
        'friendly_name' => 'Old Name',
        'area_code' => '720',
    ]);

    $client = buildMockClient([
        makeTwilioNumber('PN001', '+17205551234', 'New Name'),
    ]);

    $result = $this->service->syncNumbers($this->account, $client);

    expect($result)->toBe(['created' => 0, 'updated' => 1, 'removed' => 0]);

    $number = PhoneNumber::where('twilio_sid', 'PN001')->first();
    expect($number->friendly_name)->toBe('New Name');
});

it('marks removed numbers as inactive', function () {
    PhoneNumber::factory()->forAccount($this->account)->create([
        'twilio_sid' => 'PN_OLD',
        'phone_number' => '+17205550000',
        'area_code' => '720',
        'is_active' => true,
    ]);

    $client = buildMockClient([]);

    $result = $this->service->syncNumbers($this->account, $client);

    expect($result['removed'])->toBe(1);

    $number = PhoneNumber::where('twilio_sid', 'PN_OLD')->first();
    expect($number->is_active)->toBeFalse();
});

it('does not double-count already inactive numbers as removed', function () {
    PhoneNumber::factory()->forAccount($this->account)->create([
        'twilio_sid' => 'PN_GONE',
        'phone_number' => '+17205550001',
        'area_code' => '720',
        'is_active' => false,
    ]);

    $client = buildMockClient([]);

    $result = $this->service->syncNumbers($this->account, $client);

    expect($result['removed'])->toBe(0);
});

it('updates last_synced_at on the account', function () {
    expect($this->account->last_synced_at)->toBeNull();

    $client = buildMockClient([]);

    $this->service->syncNumbers($this->account, $client);

    $this->account->refresh();
    expect($this->account->last_synced_at)->not->toBeNull();
});

it('handles mixed creates updates and removals', function () {
    PhoneNumber::factory()->forAccount($this->account)->create([
        'twilio_sid' => 'PN_KEEP',
        'phone_number' => '+17205551111',
        'area_code' => '720',
    ]);
    PhoneNumber::factory()->forAccount($this->account)->create([
        'twilio_sid' => 'PN_REMOVE',
        'phone_number' => '+17205552222',
        'area_code' => '720',
    ]);

    $client = buildMockClient([
        makeTwilioNumber('PN_KEEP', '+17205551111', 'Updated Keep'),
        makeTwilioNumber('PN_NEW', '+13035553333', 'Brand New'),
    ]);

    $result = $this->service->syncNumbers($this->account, $client);

    expect($result)->toBe(['created' => 1, 'updated' => 1, 'removed' => 1]);
});

it('reactivates previously inactive numbers that reappear in twilio', function () {
    PhoneNumber::factory()->forAccount($this->account)->create([
        'twilio_sid' => 'PN_BACK',
        'phone_number' => '+17205559999',
        'area_code' => '720',
        'is_active' => false,
    ]);

    $client = buildMockClient([
        makeTwilioNumber('PN_BACK', '+17205559999', 'Reactivated'),
    ]);

    $result = $this->service->syncNumbers($this->account, $client);

    expect($result['updated'])->toBe(1);

    $number = PhoneNumber::where('twilio_sid', 'PN_BACK')->first();
    expect($number->is_active)->toBeTrue();
});

it('extracts area code correctly from E164 numbers', function () {
    $client = buildMockClient([
        makeTwilioNumber('PN_719', '+17195551234', 'Colorado Springs'),
        makeTwilioNumber('PN_303', '+13035551234', 'Denver'),
    ]);

    $this->service->syncNumbers($this->account, $client);

    expect(PhoneNumber::where('twilio_sid', 'PN_719')->first()->area_code)->toBe('719');
    expect(PhoneNumber::where('twilio_sid', 'PN_303')->first()->area_code)->toBe('303');
});

// ─── syncSingleNumber ──────────────────────────────────

it('syncs a single number by sid', function () {
    $mockNumber = makeTwilioNumber('PN_SINGLE', '+17205554444', 'Single Sync');

    $client = buildMockClientForSingle('PN_SINGLE', $mockNumber);

    $result = $this->service->syncSingleNumber($this->account, 'PN_SINGLE', $client);

    expect($result)
        ->twilio_sid->toBe('PN_SINGLE')
        ->phone_number->toBe('+17205554444')
        ->friendly_name->toBe('Single Sync')
        ->area_code->toBe('720');
});

it('marks a single number inactive when twilio returns not found', function () {
    $existing = PhoneNumber::factory()->forAccount($this->account)->create([
        'twilio_sid' => 'PN_DELETED',
        'phone_number' => '+17205550404',
        'area_code' => '720',
        'is_active' => true,
    ]);

    $client = buildMockClientForSingleNotFound('PN_DELETED');

    $result = $this->service->syncSingleNumber($this->account, 'PN_DELETED', $client);

    expect($result->is_active)->toBeFalse();
    expect($result->id)->toBe($existing->id);
});

it('throws exception for unknown single number not found locally', function () {
    $client = buildMockClientForSingleNotFound('PN_UNKNOWN');

    expect(fn () => $this->service->syncSingleNumber($this->account, 'PN_UNKNOWN', $client))
        ->toThrow(\Exception::class);
});

// ─── Helper functions ──────────────────────────────────

function makeTwilioNumber(string $sid, string $phoneNumber, string $friendlyName): object
{
    return new class($sid, $phoneNumber, $friendlyName)
    {
        public string $sid;

        public string $phoneNumber;

        public string $friendlyName;

        /** @var array<string, bool> */
        public array $capabilities;

        public function __construct(string $sid, string $phoneNumber, string $friendlyName)
        {
            $this->sid = $sid;
            $this->phoneNumber = $phoneNumber;
            $this->friendlyName = $friendlyName;
            $this->capabilities = [
                'voice' => true,
                'sms' => true,
                'mms' => true,
                'fax' => false,
            ];
        }
    };
}

/**
 * Build a mock Twilio client for bulk sync (incomingPhoneNumbers->read).
 *
 * @param  array<object>  $numbers
 */
function buildMockClient(array $numbers): object
{
    return new class($numbers)
    {
        public object $incomingPhoneNumbers;

        public function __construct(array $numbers)
        {
            $this->incomingPhoneNumbers = new class($numbers)
            {
                public function __construct(private array $numbers) {}

                /** @return array<object> */
                public function read(array $params, int $limit): array
                {
                    return $this->numbers;
                }
            };
        }
    };
}

/**
 * Build a mock Twilio client for single number fetch.
 */
function buildMockClientForSingle(string $sid, object $number): object
{
    return new class($sid, $number)
    {
        public function __construct(private string $sid, private object $number) {}

        public function incomingPhoneNumbers(string $sid): object
        {
            return new class($this->number)
            {
                public function __construct(private object $number) {}

                public function fetch(): object
                {
                    return $this->number;
                }
            };
        }
    };
}

/**
 * Build a mock Twilio client that throws a not-found error for a SID.
 */
function buildMockClientForSingleNotFound(string $sid): object
{
    return new class($sid)
    {
        public function __construct(private string $sid) {}

        public function incomingPhoneNumbers(string $sid): object
        {
            return new class($this->sid)
            {
                public function __construct(private string $sid) {}

                public function fetch(): never
                {
                    throw new \Exception(
                        "The requested resource /IncomingPhoneNumbers/{$this->sid} was not found [20404]"
                    );
                }
            };
        }
    };
}
