<?php

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->create();
    $this->internalUser->givePermissionTo('vendors.manage');
    $this->actingAs($this->internalUser);
});

it('loads signing page for assigned signer', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->pendingInternal()->create([
        'vendor_id' => $vendor->id,
        'title' => 'Test Subcontractor Agreement',
    ]);
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $this->internalUser->id,
        'order' => 0,
    ]);

    Livewire::test('pages::settings.vendor-contract-sign-internal', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->assertSuccessful()
        ->assertSee('Test Subcontractor Agreement');
});

it('shows signing progress', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->pendingInternal()->create([
        'vendor_id' => $vendor->id,
    ]);

    $signedUser = User::factory()->internal()->create(['name' => 'Alice Signed']);
    $pendingUser = User::factory()->internal()->create(['name' => 'Bob Pending']);

    VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $signedUser->id,
        'order' => 0,
    ]);
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $pendingUser->id,
        'order' => 1,
    ]);

    Livewire::test('pages::settings.vendor-contract-sign-internal', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->assertSee('Alice Signed')
        ->assertSee('Bob Pending')
        ->assertSee('Signed');
});

it('signs a contract as internal user', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->pendingInternal()->create([
        'vendor_id' => $vendor->id,
    ]);

    $otherSigner = User::factory()->internal()->create();
    VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $otherSigner->id,
        'order' => 0,
    ]);
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $this->internalUser->id,
        'order' => 1,
    ]);

    Livewire::test('pages::settings.vendor-contract-sign-internal', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->set('signatureData', 'John Doe')
        ->set('signatureType', 'typed')
        ->call('sign');

    $signer = VendorContractSigner::where('user_id', $this->internalUser->id)
        ->where('vendor_contract_id', $contract->id)
        ->first();

    expect($signer->signed_at)->not->toBeNull();
    expect($signer->signature_data)->toBe('John Doe');
    expect($signer->signature_type)->toBe('typed');
});

it('auto-transitions to pending_vendor when last signer signs', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->pendingInternal()->create([
        'vendor_id' => $vendor->id,
    ]);

    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $this->internalUser->id,
        'order' => 0,
    ]);

    Livewire::test('pages::settings.vendor-contract-sign-internal', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->set('signatureData', 'Jane Smith')
        ->set('signatureType', 'typed')
        ->call('sign');

    $contract->refresh();
    expect($contract->status)->toBe(VendorContract::STATUS_PENDING_VENDOR);
});

it('sends contract to vendor after all internal signatures', function () {
    Notification::fake();

    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->pendingVendor()->create([
        'vendor_id' => $vendor->id,
    ]);

    VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $this->internalUser->id,
        'order' => 0,
    ]);

    Livewire::test('pages::settings.vendor-contract-sign-internal', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->call('sendToVendor');

    $contract->refresh();
    expect($contract->public_token)->not->toBeNull();
    expect($contract->public_token_expires_at)->not->toBeNull();
});

it('recalls contract to draft', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->pendingInternal()->create([
        'vendor_id' => $vendor->id,
    ]);

    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $this->internalUser->id,
        'order' => 0,
    ]);

    Livewire::test('pages::settings.vendor-contract-sign-internal', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->call('recallToDraft')
        ->assertRedirect(route('vendor-contracts.edit', [
            'vendor' => $vendor->id,
            'contract' => $contract->id,
        ]));

    $contract->refresh();
    expect($contract->status)->toBe(VendorContract::STATUS_DRAFT);
});
