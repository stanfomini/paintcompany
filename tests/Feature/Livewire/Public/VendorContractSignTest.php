<?php

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->vendor = Vendor::factory()->create([
        'name' => 'Acme Painting LLC',
        'email' => 'vendor@acme.com',
    ]);
    $this->internalUser = User::factory()->internal()->create();
});

it('loads the public signing page with valid token', function () {
    $this->withoutVite();

    $contract = VendorContract::factory()->pendingVendor()->create([
        'vendor_id' => $this->vendor->id,
        'title' => 'Subcontractor Agreement',
    ]);

    $token = $contract->generatePublicToken();

    $this->get(route('vendor.contract.sign', ['token' => $token]))
        ->assertOk()
        ->assertSee('Subcontractor Agreement');
});

it('returns 404 for invalid token', function () {
    $this->withoutVite();

    $this->get(route('vendor.contract.sign', ['token' => 'bogus-token-does-not-exist']))
        ->assertNotFound();
});

it('returns 410 for expired token', function () {
    $this->withoutVite();

    $contract = VendorContract::factory()->pendingVendor()->create([
        'vendor_id' => $this->vendor->id,
    ]);

    $contract->generatePublicToken();

    $contract->update(['public_token_expires_at' => now()->subDay()]);

    $this->get(route('vendor.contract.sign', ['token' => $contract->public_token]))
        ->assertGone();
});

it('shows contract content and internal signer names', function () {
    $this->withoutVite();

    $signer = User::factory()->internal()->create([
        'name' => 'Alice Johnson',
    ]);

    $contract = VendorContract::factory()->pendingVendor()->create([
        'vendor_id' => $this->vendor->id,
        'content' => '<p>This is the contract body content for testing.</p>',
    ]);

    VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $signer->id,
    ]);

    $token = $contract->generatePublicToken();

    $this->get(route('vendor.contract.sign', ['token' => $token]))
        ->assertOk()
        ->assertSee('This is the contract body content for testing.', escape: false)
        ->assertSee('Alice Johnson');
});

it('signs contract as vendor', function () {
    Notification::fake();
    Storage::fake('local');

    $contract = VendorContract::factory()->pendingVendor()->create([
        'vendor_id' => $this->vendor->id,
    ]);

    VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $this->internalUser->id,
    ]);

    $token = $contract->generatePublicToken();

    Livewire::test('public.vendor-contract-sign', ['token' => $token])
        ->set('signerName', 'Jane Vendor')
        ->set('signerEmail', 'jane@vendor.com')
        ->set('signatureData', 'Jane Vendor')
        ->set('signatureType', 'typed')
        ->set('acceptedTerms', true)
        ->call('submitSignature')
        ->assertSet('isSigned', true)
        ->assertHasNoErrors();

    $contract->refresh();

    expect($contract->status)->toBe(VendorContract::STATUS_FULLY_EXECUTED);
    expect($contract->vendorSignature)->not->toBeNull();
    expect($contract->vendorSignature->signer_name)->toBe('Jane Vendor');
    expect($contract->vendorSignature->signer_email)->toBe('jane@vendor.com');
});

it('validates required fields before signing', function () {
    $contract = VendorContract::factory()->pendingVendor()->create([
        'vendor_id' => $this->vendor->id,
    ]);

    $token = $contract->generatePublicToken();

    Livewire::test('public.vendor-contract-sign', ['token' => $token])
        ->set('signerName', '')
        ->set('signerEmail', '')
        ->set('signatureData', '')
        ->set('acceptedTerms', false)
        ->call('submitSignature')
        ->assertHasErrors(['signerName', 'signerEmail', 'signatureData', 'acceptedTerms']);
});
