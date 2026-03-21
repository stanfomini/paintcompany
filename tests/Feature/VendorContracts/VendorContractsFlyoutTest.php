<?php

use App\Models\ContractTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo('vendors.view');
    $this->user->givePermissionTo('vendors.manage');
    $this->actingAs($this->user);
});

it('shows contracts button for each vendor', function () {
    Vendor::factory()->create(['name' => 'Test Vendor ABC']);

    Livewire::test('pages::settings.vendors')
        ->assertSee('Contracts');
});

it('opens contracts modal and lists contracts', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->create([
        'vendor_id' => $vendor->id,
        'title' => 'Subcontractor Agreement',
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.vendors')
        ->call('viewContracts', $vendor->id)
        ->assertSet('showContractsModal', true)
        ->assertSet('contractVendorId', $vendor->id)
        ->assertSee('Subcontractor Agreement');
});

it('creates a contract from template and redirects to editor', function () {
    $vendor = Vendor::factory()->create();
    $template = ContractTemplate::factory()->create([
        'type' => 'vendor',
        'content' => '<p>Contract for {vendor_name}</p>',
    ]);

    Livewire::test('pages::settings.vendors')
        ->call('viewContracts', $vendor->id)
        ->call('createContract', $template->id)
        ->assertRedirect();

    expect(VendorContract::where('vendor_id', $vendor->id)->count())->toBe(1);

    $contract = VendorContract::where('vendor_id', $vendor->id)->first();
    expect($contract->status)->toBe('draft')
        ->and($contract->template_id)->toBe($template->id)
        ->and($contract->created_by)->toBe($this->user->id);
});

it('deletes a draft contract', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->create([
        'vendor_id' => $vendor->id,
        'status' => 'draft',
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.vendors')
        ->call('viewContracts', $vendor->id)
        ->call('deleteContract', $contract->id);

    expect(VendorContract::find($contract->id))->toBeNull();
});

it('cannot delete a non-draft contract', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->pendingInternal()->create([
        'vendor_id' => $vendor->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.vendors')
        ->call('viewContracts', $vendor->id)
        ->call('deleteContract', $contract->id);

    expect(VendorContract::find($contract->id))->not->toBeNull();
});
