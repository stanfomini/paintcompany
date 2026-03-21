<?php

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo('vendors.view');
    $this->user->givePermissionTo('vendors.manage');
    $this->actingAs($this->user);
});

it('loads contract editor page for draft contract', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->create([
        'vendor_id' => $vendor->id,
        'title' => 'Subcontractor Agreement',
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.vendor-contract-editor', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->assertOk()
        ->assertSet('title', 'Subcontractor Agreement')
        ->assertSee('Edit Contract')
        ->assertSee('Save Draft');
});

it('saves contract content', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->create([
        'vendor_id' => $vendor->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.vendor-contract-editor', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->set('content', '<p>Updated contract content</p>')
        ->call('saveContent')
        ->assertDispatched('notify');

    expect($contract->fresh()->content)->toBe('<p>Updated contract content</p>');
});

it('saves title and expiration', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->create([
        'vendor_id' => $vendor->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.vendor-contract-editor', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->set('title', 'Updated Title')
        ->set('expiresAt', '2027-01-15')
        ->call('saveDetails')
        ->assertDispatched('notify');

    $contract->refresh();
    expect($contract->title)->toBe('Updated Title')
        ->and($contract->expires_at->format('Y-m-d'))->toBe('2027-01-15');
});

it('updates signer assignments', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->create([
        'vendor_id' => $vendor->id,
        'created_by' => $this->user->id,
    ]);
    $signer1 = User::factory()->internal()->create();
    $signer2 = User::factory()->internal()->create();

    Livewire::test('pages::settings.vendor-contract-editor', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->set('selectedSignerIds', [$signer1->id, $signer2->id])
        ->call('updateSigners')
        ->assertDispatched('notify');

    expect($contract->signers()->count())->toBe(2);
});

it('sends contract for internal signing', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->create([
        'vendor_id' => $vendor->id,
        'created_by' => $this->user->id,
    ]);
    $signer = User::factory()->internal()->create();
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $signer->id,
    ]);

    Livewire::test('pages::settings.vendor-contract-editor', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->call('sendForSigning')
        ->assertRedirect();

    expect($contract->fresh()->status)->toBe(VendorContract::STATUS_PENDING_INTERNAL);
});

it('rejects editing non-draft contract', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->pendingInternal()->create([
        'vendor_id' => $vendor->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.vendor-contract-editor', [
        'vendor' => $vendor->id,
        'contract' => $contract->id,
    ])
        ->set('content', '<p>Should not save</p>')
        ->call('saveContent')
        ->assertDispatched('notify', function ($name, $params) {
            return $params['type'] === 'error';
        });

    expect($contract->fresh()->content)->not->toBe('<p>Should not save</p>');
});
