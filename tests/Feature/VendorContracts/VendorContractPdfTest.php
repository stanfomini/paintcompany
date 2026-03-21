<?php

use App\Models\CompanySetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use App\Models\VendorContractVendorSignature;
use App\Services\VendorContractService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
    $this->owner = User::factory()->internal()->withFullAccess()->create();
});

// ─── PDF Generation ───

it('generates an executed PDF', function () {
    Storage::fake();

    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->fullyExecuted()->create([
        'vendor_id' => $vendor->id,
    ]);

    VendorContractSigner::factory()->signed()->create([
        'vendor_contract_id' => $contract->id,
    ]);

    VendorContractVendorSignature::create([
        'vendor_contract_id' => $contract->id,
        'signer_name' => 'Vendor Rep',
        'signer_email' => 'vendor@example.com',
        'signature_data' => 'Vendor Rep',
        'signature_type' => 'typed',
        'signed_at' => now(),
    ]);

    $service = new VendorContractService;

    $reflection = new ReflectionMethod($service, 'generateExecutedPdf');
    $reflection->invoke($service, $contract);

    $contract->refresh();

    expect($contract->executed_pdf_path)->not->toBeNull();
    Storage::assertExists($contract->executed_pdf_path);
});

// ─── Internal PDF Download ───

it('downloads PDF for authenticated internal user', function () {
    Storage::fake();

    $vendor = Vendor::factory()->create();
    $pdfPath = "vendor-contracts/{$vendor->id}/1/executed-contract.pdf";
    Storage::put($pdfPath, 'fake-pdf-content');

    $contract = VendorContract::factory()->fullyExecuted()->create([
        'vendor_id' => $vendor->id,
        'executed_pdf_path' => $pdfPath,
    ]);

    $this->actingAs($this->owner)
        ->get(route('vendor-contracts.pdf.download', [$vendor, $contract]))
        ->assertOk()
        ->assertDownload("contract-{$contract->id}.pdf");
});

it('returns 404 for non-executed contract PDF', function () {
    $vendor = Vendor::factory()->create();
    $contract = VendorContract::factory()->create([
        'vendor_id' => $vendor->id,
    ]);

    $this->actingAs($this->owner)
        ->get(route('vendor-contracts.pdf.download', [$vendor, $contract]))
        ->assertNotFound();
});

// ─── Public PDF Download ───

it('downloads PDF via public token', function () {
    Storage::fake();

    $vendor = Vendor::factory()->create();
    $pdfPath = "vendor-contracts/{$vendor->id}/1/executed-contract.pdf";
    Storage::put($pdfPath, 'fake-pdf-content');

    $contract = VendorContract::factory()->fullyExecuted()->create([
        'vendor_id' => $vendor->id,
        'executed_pdf_path' => $pdfPath,
        'public_token' => 'test-public-token-abc123',
        'public_token_expires_at' => now()->addDays(30),
    ]);

    $this->get(route('vendor.contract.pdf', ['token' => 'test-public-token-abc123']))
        ->assertOk()
        ->assertDownload("contract-{$contract->id}.pdf");
});
