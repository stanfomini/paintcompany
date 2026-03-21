<?php

use App\Models\CompanySetting;
use App\Models\User;
use App\Models\VendorDocument;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
    $this->owner = User::factory()->internal()->withFullAccess()->create();
    Storage::fake();
});

// ─── Download ───

it('downloads a vendor document', function () {
    Storage::put('vendor-documents/test-w9.pdf', 'fake-pdf-content');

    $doc = VendorDocument::factory()->w9()->approved()->create([
        'file_path' => 'vendor-documents/test-w9.pdf',
        'original_filename' => 'W9-Form-2026.pdf',
    ]);

    $this->actingAs($this->owner)
        ->get(route('vendors.documents.download', $doc))
        ->assertOk()
        ->assertDownload('W9-Form-2026.pdf');
});

it('returns 403 for download when user lacks vendors.manage permission', function () {
    $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
    $registrar->forgetCachedPermissions();

    if (\Spatie\Permission\Models\Permission::count() === 0) {
        (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
    }

    $user = User::factory()->internal()->create();
    $user->givePermissionTo('vendors.view');

    $doc = VendorDocument::factory()->w9()->create([
        'file_path' => 'vendor-documents/test-w9.pdf',
    ]);

    $this->actingAs($user)
        ->get(route('vendors.documents.download', $doc))
        ->assertForbidden();
});

it('returns 404 when download file does not exist on disk', function () {
    $doc = VendorDocument::factory()->w9()->create([
        'file_path' => 'vendor-documents/missing-file.pdf',
    ]);

    $this->actingAs($this->owner)
        ->get(route('vendors.documents.download', $doc))
        ->assertNotFound();
});

// ─── View ───

it('views a vendor document inline', function () {
    Storage::put('vendor-documents/test-w9.pdf', 'fake-pdf-content');

    $doc = VendorDocument::factory()->w9()->approved()->create([
        'file_path' => 'vendor-documents/test-w9.pdf',
    ]);

    $this->actingAs($this->owner)
        ->get(route('vendors.documents.view', $doc))
        ->assertOk()
        ->assertHeader('content-disposition', 'inline');
});

it('returns 403 for view when user lacks vendors.manage permission', function () {
    $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
    $registrar->forgetCachedPermissions();

    if (\Spatie\Permission\Models\Permission::count() === 0) {
        (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
    }

    $user = User::factory()->internal()->create();
    $user->givePermissionTo('vendors.view');

    $doc = VendorDocument::factory()->w9()->create([
        'file_path' => 'vendor-documents/test-w9.pdf',
    ]);

    $this->actingAs($user)
        ->get(route('vendors.documents.view', $doc))
        ->assertForbidden();
});
