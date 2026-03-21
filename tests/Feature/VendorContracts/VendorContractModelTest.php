<?php

use App\Models\ContractTemplate;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Models\VendorContractVendorSignature;
use App\Models\VendorDocument;

it('creates a vendor contract with factory', function () {
    $contract = VendorContract::factory()->create();

    expect($contract)->toBeInstanceOf(VendorContract::class)
        ->and($contract->title)->not->toBeEmpty()
        ->and($contract->content)->not->toBeEmpty()
        ->and($contract->status)->toBe('draft')
        ->and($contract->is_valid)->toBeTrue();
});

it('belongs to a vendor', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->create(['vendor_id' => $vendor->id]);

    expect($contract->vendor)->toBeInstanceOf(Vendor::class)
        ->and($contract->vendor->id)->toBe($vendor->id);
});

it('belongs to a template', function () {
    $template = ContractTemplate::factory()->create(['type' => 'vendor']);
    $contract = VendorContract::factory()->create(['template_id' => $template->id]);

    expect($contract->template)->toBeInstanceOf(ContractTemplate::class)
        ->and($contract->template->id)->toBe($template->id);
});

it('has many signers', function () {
    $contract = VendorContract::factory()->create();
    VendorContractSigner::factory()->count(2)->create(['vendor_contract_id' => $contract->id]);

    expect($contract->signers)->toHaveCount(2)
        ->each->toBeInstanceOf(VendorContractSigner::class);
});

it('has one vendor signature', function () {
    $contract = VendorContract::factory()->create();

    VendorContractVendorSignature::create([
        'vendor_contract_id' => $contract->id,
        'signer_name' => 'Test Vendor',
        'signer_email' => 'vendor@example.com',
        'signature_data' => 'data:image/png;base64,abc',
        'signature_type' => 'drawn',
        'signed_at' => now(),
    ]);

    expect($contract->fresh()->vendorSignature)->toBeInstanceOf(VendorContractVendorSignature::class)
        ->and($contract->fresh()->vendorSignature->signer_name)->toBe('Test Vendor');
});

it('generates a public token', function () {
    $contract = VendorContract::factory()->create();
    $token = $contract->generatePublicToken();

    expect($token)->toHaveLength(64)
        ->and($contract->fresh()->public_token)->toBe($token)
        ->and($contract->fresh()->public_token_expires_at)->not->toBeNull();
});

it('validates public token correctly', function () {
    $contract = VendorContract::factory()->create([
        'public_token' => 'test-token',
        'public_token_expires_at' => now()->addDay(),
    ]);

    expect($contract->isPublicTokenValid())->toBeTrue();

    $expiredContract = VendorContract::factory()->create([
        'public_token' => 'expired-token',
        'public_token_expires_at' => now()->subDay(),
    ]);

    expect($expiredContract->isPublicTokenValid())->toBeFalse();
});

it('invalidates public token', function () {
    $contract = VendorContract::factory()->create([
        'public_token' => 'test-token',
        'public_token_expires_at' => now()->addDay(),
    ]);

    $contract->invalidatePublicToken();
    $contract->refresh();

    expect($contract->public_token)->toBeNull()
        ->and($contract->public_token_expires_at)->toBeNull();
});

it('scopes by public token', function () {
    $contract = VendorContract::factory()->create([
        'public_token' => 'unique-token-123',
    ]);

    VendorContract::factory()->create(['public_token' => 'other-token']);

    $found = VendorContract::byPublicToken('unique-token-123')->first();

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($contract->id);
});

it('is soft deletable', function () {
    $contract = VendorContract::factory()->create();
    $contractId = $contract->id;

    $contract->delete();

    expect(VendorContract::find($contractId))->toBeNull()
        ->and(VendorContract::withTrashed()->find($contractId))->not->toBeNull();
});

it('tracks renewal chain', function () {
    $original = VendorContract::factory()->create();
    $renewed = VendorContract::factory()->create(['renewed_from_id' => $original->id]);

    expect($renewed->renewedFrom->id)->toBe($original->id)
        ->and($original->fresh()->renewal->id)->toBe($renewed->id);
});

it('checks if all internal signers have signed', function () {
    $contract = VendorContract::factory()->create();

    // No signers = false
    expect($contract->allInternalSignersSigned())->toBeFalse();

    // Add unsigned signer
    VendorContractSigner::factory()->create([
        'vendor_contract_id' => $contract->id,
        'signed_at' => null,
    ]);

    expect($contract->fresh()->allInternalSignersSigned())->toBeFalse();

    // Sign all signers
    $contract->signers()->update([
        'signed_at' => now(),
        'signature_data' => 'data:image/png;base64,abc',
        'signature_type' => 'drawn',
    ]);

    expect($contract->fresh()->allInternalSignersSigned())->toBeTrue();
});

it('vendor has contracts relationship', function () {
    $vendor = Vendor::factory()->create();
    VendorContract::factory()->count(3)->create(['vendor_id' => $vendor->id]);

    expect($vendor->contracts)->toHaveCount(3)
        ->each->toBeInstanceOf(VendorContract::class);
});

it('vendor document has vendorContract relationship', function () {
    $contract = VendorContract::factory()->create();
    $document = VendorDocument::factory()->create([
        'vendor_id' => $contract->vendor_id,
        'vendor_contract_id' => $contract->id,
    ]);

    expect($document->vendorContract)->toBeInstanceOf(VendorContract::class)
        ->and($document->vendorContract->id)->toBe($contract->id);
});

it('contract template scopes to vendor type', function () {
    ContractTemplate::factory()->create(['type' => 'vendor']);
    ContractTemplate::factory()->create(['type' => 'vendor']);
    ContractTemplate::factory()->create(['type' => 'standard']);

    $vendorTemplates = ContractTemplate::vendor()->get();

    expect($vendorTemplates)->toHaveCount(2)
        ->each(fn ($template) => $template->type->toBe('vendor'));
});
