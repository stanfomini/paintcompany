<?php

use App\Models\ContractTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Models\VendorDocument;
use App\Notifications\VendorContractInternalSigningRequest;
use App\Notifications\VendorContractSentToVendor;
use App\Services\VendorContractService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->service = new VendorContractService;
});

// ─── Creation & Editing ───

it('creates a contract from a template', function () {
    $vendor = Vendor::factory()->create();
    $template = ContractTemplate::factory()->create([
        'type' => 'vendor',
        'content' => '<p>Agreement for {vendor_name}</p>',
    ]);
    $signer = User::factory()->internal()->create();
    $creator = User::factory()->internal()->create();

    $contract = $this->service->createFromTemplate($vendor, $template, [$signer->id], $creator);

    expect($contract)->toBeInstanceOf(VendorContract::class)
        ->and($contract->status)->toBe('draft')
        ->and($contract->is_valid)->toBeTrue()
        ->and($contract->vendor_id)->toBe($vendor->id)
        ->and($contract->template_id)->toBe($template->id)
        ->and($contract->title)->toBe($template->name)
        ->and($contract->created_by)->toBe($creator->id)
        ->and($contract->signers)->toHaveCount(1)
        ->and($contract->signers->first()->user_id)->toBe($signer->id);
});

it('populates merge tags in contract content', function () {
    $vendor = Vendor::factory()->withAddress()->create([
        'name' => 'Acme Painting LLC',
        'contact_name' => 'Jane Smith',
        'email' => 'jane@acme.com',
        'phone' => '303-555-1234',
    ]);

    $template = ContractTemplate::factory()->create([
        'type' => 'vendor',
        'content' => 'Company: {vendor_name}, Contact: {vendor_contact}, Email: {vendor_email}, Phone: {vendor_phone}, Date: {contract_date}',
    ]);

    $creator = User::factory()->internal()->create();

    $contract = $this->service->createFromTemplate($vendor, $template, [], $creator);

    expect($contract->content)
        ->toContain('Company: Acme Painting LLC')
        ->toContain('Contact: Jane Smith')
        ->toContain('Email: jane@acme.com')
        ->toContain('Phone: 303-555-1234')
        ->toContain('Date: '.now()->format('m/d/Y'));
});

it('updates content on a draft contract', function () {
    $contract = VendorContract::factory()->create();

    $this->service->updateContent($contract, '<p>Updated content</p>');

    expect($contract->fresh()->content)->toBe('<p>Updated content</p>');
});

it('rejects content update on non-draft contract', function () {
    $contract = VendorContract::factory()->pendingInternal()->create();

    $this->service->updateContent($contract, '<p>Should fail</p>');
})->throws(InvalidArgumentException::class, 'Content can only be updated while the contract is in draft status.');

it('updates signers on a draft contract', function () {
    $contract = VendorContract::factory()->create();
    $userA = User::factory()->internal()->create();
    $userB = User::factory()->internal()->create();

    // Set initial signers
    $this->service->updateSigners($contract, [$userA->id]);
    expect($contract->fresh()->signers)->toHaveCount(1);

    // Update to different signers
    $this->service->updateSigners($contract, [$userA->id, $userB->id]);
    $contract->refresh();

    expect($contract->signers)->toHaveCount(2);
});

// ─── Internal Signing ───

it('sends a contract for internal signing', function () {
    Notification::fake();

    $contract = VendorContract::factory()->create();
    $signer = User::factory()->internal()->create();
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $signer->id,
    ]);

    $this->service->sendForInternalSigning($contract);

    expect($contract->fresh()->status)->toBe('pending_internal');

    Notification::assertSentTo($signer, VendorContractInternalSigningRequest::class);
});

it('rejects sending for signing without signers', function () {
    $contract = VendorContract::factory()->create();

    $this->service->sendForInternalSigning($contract);
})->throws(InvalidArgumentException::class, 'Contract must have at least one signer');

it('recalls a pending_internal contract to draft', function () {
    $contract = VendorContract::factory()->pendingInternal()->create();
    $signer = VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
    ]);

    $this->service->recallToDraft($contract);

    $contract->refresh();
    $signer->refresh();

    expect($contract->status)->toBe('draft')
        ->and($signer->signed_at)->toBeNull()
        ->and($signer->signature_data)->toBeNull();
});

it('records an internal signature', function () {
    $contract = VendorContract::factory()->pendingInternal()->create();
    $user = User::factory()->internal()->create();
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $user->id,
    ]);

    $this->service->signAsInternal($contract, $user, 'data:image/png;base64,abc', 'drawn');

    $signer = $contract->signers()->where('user_id', $user->id)->first();

    expect($signer->signed_at)->not->toBeNull()
        ->and($signer->signature_data)->toBe('data:image/png;base64,abc')
        ->and($signer->signature_type)->toBe('drawn');
});

it('auto-transitions to pending_vendor when all internal signers have signed', function () {
    $contract = VendorContract::factory()->pendingInternal()->create();
    $userA = User::factory()->internal()->create();
    $userB = User::factory()->internal()->create();

    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $userA->id,
    ]);
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'user_id' => $userB->id,
    ]);

    // First signer signs
    $this->service->signAsInternal($contract, $userA, 'sig-a', 'drawn');
    expect($contract->fresh()->status)->toBe('pending_internal');

    // Second signer signs - should auto-transition
    $this->service->signAsInternal($contract, $userB, 'sig-b', 'typed');
    expect($contract->fresh()->status)->toBe('pending_vendor');
});

// ─── Vendor Signing ───

it('sends a contract to the vendor', function () {
    Notification::fake();

    $vendor = Vendor::factory()->create(['email' => 'vendor@example.com']);
    $contract = VendorContract::factory()->pendingVendor()->create([
        'vendor_id' => $vendor->id,
    ]);

    $this->service->sendToVendor($contract);

    $contract->refresh();

    expect($contract->public_token)->not->toBeNull()
        ->and($contract->public_token_expires_at)->not->toBeNull();

    Notification::assertSentOnDemand(VendorContractSentToVendor::class);
});

it('records vendor signature and transitions to fully_executed', function () {
    Notification::fake();
    Storage::fake('local');

    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->pendingVendor()->create([
        'vendor_id' => $vendor->id,
        'public_token' => 'test-token-123',
        'public_token_expires_at' => now()->addDays(30),
    ]);

    $this->service->signAsVendor(
        $contract,
        'Vendor Rep',
        'vendor@test.com',
        'data:image/png;base64,vendorsig',
        'drawn',
    );

    $contract->refresh();

    expect($contract->status)->toBe('fully_executed')
        ->and($contract->vendorSignature)->not->toBeNull()
        ->and($contract->vendorSignature->signer_name)->toBe('Vendor Rep')
        ->and($contract->vendorSignature->signer_email)->toBe('vendor@test.com')
        ->and($contract->public_token)->toBe('test-token-123')
        ->and($contract->executed_pdf_path)->not->toBeNull();

    // Verify VendorDocument was created
    $document = VendorDocument::where('vendor_contract_id', $contract->id)->first();
    expect($document)->not->toBeNull()
        ->and($document->type)->toBe('contract')
        ->and($document->status)->toBe('approved');

    // Verify PDF was stored
    Storage::disk('local')->assertExists($contract->executed_pdf_path);
});

// ─── Invalidation ───

it('invalidates a pending_internal contract', function () {
    $contract = VendorContract::factory()->pendingInternal()->create();

    $this->service->invalidate($contract);

    $contract->refresh();

    expect($contract->status)->toBe('invalidated')
        ->and($contract->is_valid)->toBeFalse();
});

it('invalidates a fully_executed contract', function () {
    $contract = VendorContract::factory()->fullyExecuted()->create([
        'public_token' => 'token-to-nullify',
        'public_token_expires_at' => now()->addDays(30),
    ]);

    $this->service->invalidate($contract);

    $contract->refresh();

    expect($contract->status)->toBe('invalidated')
        ->and($contract->is_valid)->toBeFalse()
        ->and($contract->public_token)->toBeNull();
});

it('invalidates an expired contract', function () {
    $contract = VendorContract::factory()->create([
        'status' => VendorContract::STATUS_EXPIRED,
        'is_valid' => false,
        'expires_at' => now()->subDay(),
    ]);

    $this->service->invalidate($contract);

    expect($contract->fresh()->status)->toBe('invalidated');
});

it('rejects invalidation of a draft contract', function () {
    $contract = VendorContract::factory()->create();

    $this->service->invalidate($contract);
})->throws(InvalidArgumentException::class, 'Contract can only be invalidated from');

// ─── Renewal ───

it('renews a contract by cloning into a new draft', function () {
    $original = VendorContract::factory()->fullyExecuted()->create([
        'content' => '<p>Original content</p>',
    ]);
    $signer = VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $original->id,
    ]);

    $renewed = $this->service->renew($original);

    expect($renewed->status)->toBe('draft')
        ->and($renewed->content)->toBe('<p>Original content</p>')
        ->and($renewed->vendor_id)->toBe($original->vendor_id)
        ->and($renewed->renewed_from_id)->toBe($original->id)
        ->and($renewed->is_valid)->toBeTrue()
        ->and($renewed->signers)->toHaveCount(1)
        ->and($renewed->signers->first()->user_id)->toBe($signer->user_id)
        ->and($renewed->signers->first()->signed_at)->toBeNull();
});

it('rejects renewal when an active renewal already exists', function () {
    $original = VendorContract::factory()->fullyExecuted()->create();

    // Create first renewal
    VendorContract::factory()->create([
        'renewed_from_id' => $original->id,
        'vendor_id' => $original->vendor_id,
    ]);

    // Second renewal should fail
    $this->service->renew($original);
})->throws(InvalidArgumentException::class, 'An active renewal already exists');
