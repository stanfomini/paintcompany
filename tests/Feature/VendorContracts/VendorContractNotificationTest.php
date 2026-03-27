<?php

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Notifications\VendorContractExpired;
use App\Notifications\VendorContractExpiringSoon;
use App\Notifications\VendorContractFullyExecuted;
use App\Notifications\VendorContractInternalSigningRequest;
use App\Notifications\VendorContractSentToVendor;
use Illuminate\Notifications\AnonymousNotifiable;

it('sends internal signing request notification via mail and database', function () {
    $vendor = Vendor::factory()->create(['name' => 'Test Vendor Co']);
    $contract = VendorContract::factory()->pendingInternal()->create([
        'vendor_id' => $vendor->id,
        'title' => 'Subcontractor Agreement 2026',
    ]);
    $user = User::factory()->internal()->create();

    $notification = new VendorContractInternalSigningRequest($contract);

    $channels = $notification->via($user);
    expect($channels)->toContain('mail')
        ->toContain('database')
        ->toContain('broadcast');

    $mail = $notification->toMail($user);

    expect($mail->subject)->toBe('Contract Signing Required: Subcontractor Agreement 2026');
    expect((string) $mail->render())->toContain('Test Vendor Co');

    $array = $notification->toArray($user);

    expect($array)->toHaveKeys(['type', 'vendor_contract_id', 'title', 'vendor_name'])
        ->and($array['type'])->toBe('vendor_contract_internal_signing_request')
        ->and($array['vendor_contract_id'])->toBe($contract->id)
        ->and($array['title'])->toBe('Subcontractor Agreement 2026')
        ->and($array['vendor_name'])->toBe('Test Vendor Co');
});

it('sends vendor signing notification via mail', function () {
    $vendor = Vendor::factory()->create(['name' => 'Acme Painting']);
    $contract = VendorContract::factory()->pendingVendor()->create([
        'vendor_id' => $vendor->id,
        'title' => 'Service Agreement',
    ]);
    $signingUrl = 'https://example.com/vendor/contract/sign/abc123';

    $notification = new VendorContractSentToVendor($contract, $signingUrl);
    $anonymous = new AnonymousNotifiable;

    expect($notification->via($anonymous))->toBe(['mail']);

    $mail = $notification->toMail($anonymous);

    expect($mail->subject)->toBe('Contract for Review and Signature: Service Agreement')
        ->and($mail->actionUrl)->toBe($signingUrl);
});

it('sends fully executed notification', function () {
    $vendor = Vendor::factory()->create(['name' => 'Full Circle LLC']);
    $contract = VendorContract::factory()->fullyExecuted()->create([
        'vendor_id' => $vendor->id,
        'title' => 'Master Agreement',
    ]);

    $notification = new VendorContractFullyExecuted($contract);
    $user = User::factory()->internal()->create();

    $channels = $notification->via($user);
    expect($channels)->toContain('mail')
        ->toContain('database')
        ->toContain('broadcast');

    $anonymous = new AnonymousNotifiable;
    expect($notification->via($anonymous))->toBe(['mail']);

    $mail = $notification->toMail($user);

    expect($mail->subject)->toBe('Contract Fully Executed: Master Agreement');
    expect((string) $mail->render())
        ->toContain('Full Circle LLC')
        ->toContain('fully signed');

    $array = $notification->toArray($user);

    expect($array)->toHaveKeys(['type', 'vendor_contract_id', 'title'])
        ->and($array['type'])->toBe('vendor_contract_fully_executed')
        ->and($array['vendor_contract_id'])->toBe($contract->id)
        ->and($array['title'])->toBe('Master Agreement');
});

it('sends expiring soon notification', function () {
    $vendor = Vendor::factory()->create(['name' => 'Expiring Vendor']);
    $contract = VendorContract::factory()->withExpiration(7)->create([
        'vendor_id' => $vendor->id,
        'title' => 'Annual Contract',
    ]);

    $notification = new VendorContractExpiringSoon($contract);
    $user = User::factory()->internal()->create();

    $channels = $notification->via($user);
    expect($channels)->toContain('mail')
        ->toContain('database')
        ->toContain('broadcast');

    $anonymous = new AnonymousNotifiable;
    expect($notification->via($anonymous))->toBe(['mail']);

    $mail = $notification->toMail($user);

    expect($mail->subject)->toBe('Contract Expiring Soon: Annual Contract');
    expect((string) $mail->render())
        ->toContain('Expiring Vendor')
        ->toContain($contract->expires_at->format('M j, Y'));

    $array = $notification->toArray($user);

    expect($array)->toHaveKeys(['type', 'vendor_contract_id', 'title', 'expires_at'])
        ->and($array['type'])->toBe('vendor_contract_expiring_soon')
        ->and($array['vendor_contract_id'])->toBe($contract->id)
        ->and($array['title'])->toBe('Annual Contract')
        ->and($array['expires_at'])->toBe($contract->expires_at->toISOString());
});

it('sends expired notification', function () {
    $vendor = Vendor::factory()->create(['name' => 'Expired Vendor']);
    $contract = VendorContract::factory()->expired()->create([
        'vendor_id' => $vendor->id,
        'title' => 'Old Agreement',
        'status' => VendorContract::STATUS_EXPIRED,
    ]);

    $notification = new VendorContractExpired($contract);
    $user = User::factory()->internal()->create();

    $channels = $notification->via($user);
    expect($channels)->toContain('mail')
        ->toContain('database')
        ->toContain('broadcast');

    $anonymous = new AnonymousNotifiable;
    expect($notification->via($anonymous))->toBe(['mail']);

    $mail = $notification->toMail($user);

    expect($mail->subject)->toBe('Contract Expired: Old Agreement');
    expect((string) $mail->render())
        ->toContain('Expired Vendor')
        ->toContain('expired');

    $array = $notification->toArray($user);

    expect($array)->toHaveKeys(['type', 'vendor_contract_id', 'title'])
        ->and($array['type'])->toBe('vendor_contract_expired')
        ->and($array['vendor_contract_id'])->toBe($contract->id)
        ->and($array['title'])->toBe('Old Agreement');
});
