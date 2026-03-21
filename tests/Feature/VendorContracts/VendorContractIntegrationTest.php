<?php

use App\Models\ContractTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorDocument;
use App\Services\VendorContractService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

it('completes full contract lifecycle: create → sign internal → sign vendor → PDF', function () {
    Notification::fake();
    Storage::fake('local');

    $service = app(VendorContractService::class);
    $vendor = Vendor::factory()->create(['email' => 'vendor@test.com']);
    $creator = User::factory()->internal()->create();
    $signer = User::factory()->internal()->create();
    $template = ContractTemplate::create([
        'name' => 'Subcontractor Agreement',
        'type' => 'vendor',
        'content' => '<p>Agreement with {vendor_name}</p>',
        'is_active' => true,
    ]);

    // 1. Create from template
    $contract = $service->createFromTemplate($vendor, $template, [$signer->id], $creator);
    expect($contract->status)->toBe('draft')
        ->and($contract->content)->toContain($vendor->name);

    // 2. Edit content
    $service->updateContent($contract, '<p>Updated agreement with '.$vendor->name.'</p>');

    // 3. Send for internal signing
    $service->sendForInternalSigning($contract);
    expect($contract->fresh()->status)->toBe('pending_internal');

    // 4. Internal signer signs
    $service->signAsInternal($contract, $signer, 'Internal Sig', 'typed');
    expect($contract->fresh()->status)->toBe('pending_vendor');

    // 5. Send to vendor
    $service->sendToVendor($contract);
    expect($contract->fresh()->public_token)->not->toBeNull();

    // 6. Vendor signs
    $service->signAsVendor(
        $contract->fresh(), 'Vendor Rep', 'vendor@test.com',
        'Vendor Signature', 'typed', request()
    );

    $contract->refresh();
    expect($contract->status)->toBe('fully_executed')
        ->and($contract->executed_pdf_path)->not->toBeNull()
        ->and($contract->public_token)->not->toBeNull()
        ->and($contract->vendorSignature)->not->toBeNull();

    // 7. Verify VendorDocument was created
    $doc = VendorDocument::where('vendor_contract_id', $contract->id)->first();
    expect($doc)->not->toBeNull()
        ->and($doc->type)->toBe('contract')
        ->and($doc->status)->toBe('approved');
});

it('completes renewal lifecycle', function () {
    Notification::fake();
    Storage::fake('local');

    $service = app(VendorContractService::class);
    $original = VendorContract::factory()->expired()->create(['content' => '<p>Original</p>']);

    $renewal = $service->renew($original);

    expect($renewal->status)->toBe('draft')
        ->and($renewal->renewed_from_id)->toBe($original->id)
        ->and($renewal->content)->toBe('<p>Original</p>');

    // Verify original points to renewal
    expect($original->fresh()->renewal->id)->toBe($renewal->id);
});

it('prevents double renewal', function () {
    $original = VendorContract::factory()->expired()->create();
    $service = app(VendorContractService::class);

    $service->renew($original);

    expect(fn () => $service->renew($original))
        ->toThrow(\InvalidArgumentException::class);
});
