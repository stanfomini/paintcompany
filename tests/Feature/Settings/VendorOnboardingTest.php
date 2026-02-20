<?php

use App\Models\CompanySetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Notifications\VendorOnboardingCompleted;
use App\Notifications\VendorOnboardingInvite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
    $this->owner = User::factory()->internal()->withFullAccess()->create();
});

// ─── Public Onboarding Page Tests ───

it('loads onboarding page with valid token', function () {
    $vendor = Vendor::factory()->create();
    $vendor->generatePublicToken();

    $this->get(route('vendor.onboarding', ['token' => $vendor->public_token]))
        ->assertOk()
        ->assertSeeLivewire('public.vendor-onboarding');
});

it('returns 404 for invalid token', function () {
    $this->get(route('vendor.onboarding', ['token' => 'invalid-token-12345']))
        ->assertNotFound();
});

it('returns 410 for expired token', function () {
    $vendor = Vendor::factory()->create();
    $vendor->generatePublicToken();
    $vendor->update(['public_token_expires_at' => now()->subDay()]);

    $this->get(route('vendor.onboarding', ['token' => $vendor->public_token]))
        ->assertStatus(410);
});

it('shows completion state for already-onboarded vendor', function () {
    $vendor = Vendor::factory()->create([
        'onboarding_completed_at' => now()->subDay(),
    ]);
    $vendor->generatePublicToken();

    Livewire::test('public.vendor-onboarding', ['token' => $vendor->public_token])
        ->assertSee('Thank You');
});

it('pre-fills vendor data on onboarding page', function () {
    $vendor = Vendor::factory()->create([
        'name' => 'Test Painting Co',
        'contact_name' => 'Jane Doe',
        'email' => 'jane@test.com',
    ]);
    $vendor->generatePublicToken();

    Livewire::test('public.vendor-onboarding', ['token' => $vendor->public_token])
        ->assertSet('vendorName', 'Test Painting Co')
        ->assertSet('contactName', 'Jane Doe')
        ->assertSet('vendorEmail', 'jane@test.com');
});

it('submits vendor information and marks onboarding complete', function () {
    Storage::fake();
    Notification::fake();

    $vendor = Vendor::factory()->create([
        'name' => 'Old Name',
        'email' => 'vendor@test.com',
    ]);
    $vendor->generatePublicToken();

    Livewire::test('public.vendor-onboarding', ['token' => $vendor->public_token])
        ->set('vendorName', 'Updated Vendor Name')
        ->set('contactName', 'Bob Smith')
        ->set('vendorEmail', 'bob@vendor.com')
        ->set('vendorPhone', '555-9999')
        ->set('vendorCategory', 'subcontractor')
        ->call('submit')
        ->assertSet('completed', true);

    $vendor->refresh();

    expect($vendor->name)->toBe('Updated Vendor Name');
    expect($vendor->contact_name)->toBe('Bob Smith');
    expect($vendor->onboarding_completed_at)->not->toBeNull();
});

it('uploads W-9 document during onboarding', function () {
    Storage::fake();
    Notification::fake();

    $vendor = Vendor::factory()->create(['email' => 'v@test.com']);
    $vendor->generatePublicToken();

    $file = UploadedFile::fake()->create('w9.pdf', 500, 'application/pdf');

    Livewire::test('public.vendor-onboarding', ['token' => $vendor->public_token])
        ->set('vendorName', $vendor->name)
        ->set('vendorCategory', 'supplier')
        ->set('w9File', $file)
        ->call('submit')
        ->assertHasNoErrors();

    expect(VendorDocument::where('vendor_id', $vendor->id)->where('type', 'w9')->count())->toBe(1);

    $vendor->refresh();
    expect($vendor->w9_on_file)->toBeTrue();
});

it('uploads multiple document types during onboarding', function () {
    Storage::fake();
    Notification::fake();

    $vendor = Vendor::factory()->create(['email' => 'v@test.com']);
    $vendor->generatePublicToken();

    $w9 = UploadedFile::fake()->create('w9.pdf', 500, 'application/pdf');
    $insurance = UploadedFile::fake()->create('insurance.pdf', 500, 'application/pdf');
    $workersComp = UploadedFile::fake()->create('workers_comp.pdf', 500, 'application/pdf');

    Livewire::test('public.vendor-onboarding', ['token' => $vendor->public_token])
        ->set('vendorName', $vendor->name)
        ->set('vendorCategory', 'subcontractor')
        ->set('w9File', $w9)
        ->set('insuranceFile', $insurance)
        ->set('insuranceExpiration', '2027-06-15')
        ->set('workersCompFile', $workersComp)
        ->set('workersCompExpiration', '2027-12-31')
        ->call('submit')
        ->assertHasNoErrors();

    expect(VendorDocument::where('vendor_id', $vendor->id)->count())->toBe(3);

    $insuranceDoc = VendorDocument::where('vendor_id', $vendor->id)
        ->where('type', 'insurance')
        ->first();

    expect($insuranceDoc->expires_at->format('Y-m-d'))->toBe('2027-06-15');
});

it('uploads other files during onboarding', function () {
    Storage::fake();
    Notification::fake();

    $vendor = Vendor::factory()->create(['email' => 'v@test.com']);
    $vendor->generatePublicToken();

    $file1 = UploadedFile::fake()->create('license.pdf', 500, 'application/pdf');
    $file2 = UploadedFile::fake()->create('certification.pdf', 500, 'application/pdf');

    Livewire::test('public.vendor-onboarding', ['token' => $vendor->public_token])
        ->set('vendorName', $vendor->name)
        ->set('vendorCategory', 'supplier')
        ->set('otherFiles', [$file1, $file2])
        ->call('submit')
        ->assertHasNoErrors();

    expect(VendorDocument::where('vendor_id', $vendor->id)->where('type', 'other')->count())->toBe(2);
});

it('notifies internal managers when onboarding is completed', function () {
    Storage::fake();
    Notification::fake();

    $vendor = Vendor::factory()->create(['email' => 'v@test.com']);
    $vendor->generatePublicToken();

    Livewire::test('public.vendor-onboarding', ['token' => $vendor->public_token])
        ->set('vendorName', $vendor->name)
        ->set('vendorCategory', 'supplier')
        ->call('submit');

    Notification::assertSentTo($this->owner, VendorOnboardingCompleted::class);
});

it('validates required fields on onboarding submission', function () {
    $vendor = Vendor::factory()->create();
    $vendor->generatePublicToken();

    Livewire::test('public.vendor-onboarding', ['token' => $vendor->public_token])
        ->set('vendorName', '')
        ->set('vendorCategory', 'invalid')
        ->call('submit')
        ->assertHasErrors(['vendorName', 'vendorCategory']);
});

// ─── Send Onboarding Link Tests ───

it('sends onboarding link to vendor with email', function () {
    Notification::fake();

    $vendor = Vendor::factory()->create(['email' => 'vendor@example.com']);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('sendOnboardingLink', $vendor->id);

    $vendor->refresh();

    expect($vendor->public_token)->not->toBeNull();
    expect($vendor->public_token_expires_at)->not->toBeNull();

    Notification::assertSentOnDemand(VendorOnboardingInvite::class);
});

it('fails to send onboarding link when vendor has no email', function () {
    $vendor = Vendor::factory()->create(['email' => null]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('sendOnboardingLink', $vendor->id);

    $vendor->refresh();

    expect($vendor->public_token)->toBeNull();
});

it('requires vendors.manage permission to send onboarding link', function () {
    $user = User::factory()->internal()->create();
    $vendor = Vendor::factory()->create(['email' => 'v@test.com']);

    Livewire::actingAs($user)
        ->test('pages::settings.vendors')
        ->assertForbidden();
});

// ─── Document Management Tests (Vendor Settings Page) ───

it('opens documents modal for a vendor', function () {
    $vendor = Vendor::factory()->create();

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('viewDocuments', $vendor->id)
        ->assertSet('showDocumentsModal', true)
        ->assertSet('viewingVendorId', $vendor->id);
});

it('displays existing documents in documents modal', function () {
    $vendor = Vendor::factory()->create();
    VendorDocument::factory()->w9()->create(['vendor_id' => $vendor->id]);
    VendorDocument::factory()->insurance()->create(['vendor_id' => $vendor->id]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('viewDocuments', $vendor->id)
        ->assertSee('W-9 Form')
        ->assertSee('Certificate of Insurance');
});

it('approves a pending document', function () {
    $vendor = Vendor::factory()->create();
    $doc = VendorDocument::factory()->w9()->create(['vendor_id' => $vendor->id]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('viewDocuments', $vendor->id)
        ->call('approveDocument', $doc->id);

    $doc->refresh();

    expect($doc->status)->toBe(VendorDocument::STATUS_APPROVED);
    expect($doc->reviewed_by)->toBe($this->owner->id);
    expect($doc->reviewed_at)->not->toBeNull();
});

it('auto-updates vendor compliance when document is approved', function () {
    $vendor = Vendor::factory()->create(['w9_on_file' => false]);
    $doc = VendorDocument::factory()->w9()->create(['vendor_id' => $vendor->id]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('viewDocuments', $vendor->id)
        ->call('approveDocument', $doc->id);

    $vendor->refresh();

    expect($vendor->w9_on_file)->toBeTrue();
});

it('auto-updates insurance expiration when insurance document approved', function () {
    $vendor = Vendor::factory()->create(['certificate_of_insurance' => false]);
    $doc = VendorDocument::factory()->insurance()->create([
        'vendor_id' => $vendor->id,
        'expires_at' => '2027-06-15',
    ]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('viewDocuments', $vendor->id)
        ->call('approveDocument', $doc->id);

    $vendor->refresh();

    expect($vendor->certificate_of_insurance)->toBeTrue();
    expect($vendor->insurance_expiration->format('Y-m-d'))->toBe('2027-06-15');
});

it('rejects a pending document', function () {
    $vendor = Vendor::factory()->create();
    $doc = VendorDocument::factory()->create(['vendor_id' => $vendor->id]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('viewDocuments', $vendor->id)
        ->call('rejectDocument', $doc->id);

    $doc->refresh();

    expect($doc->status)->toBe(VendorDocument::STATUS_REJECTED);
});

it('deletes a document', function () {
    Storage::fake();

    $vendor = Vendor::factory()->create();
    $doc = VendorDocument::factory()->create([
        'vendor_id' => $vendor->id,
        'file_path' => 'vendor-documents/test.pdf',
    ]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('viewDocuments', $vendor->id)
        ->call('deleteDocument', $doc->id);

    expect(VendorDocument::find($doc->id))->toBeNull();
});

it('uploads internal document', function () {
    Storage::fake();

    $vendor = Vendor::factory()->create();
    $file = UploadedFile::fake()->create('w9_form.pdf', 500, 'application/pdf');

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('viewDocuments', $vendor->id)
        ->set('internalUploadName', 'W-9 Form 2026')
        ->set('internalUploadType', 'w9')
        ->set('internalUploadFile', $file)
        ->call('uploadInternalDocument')
        ->assertHasNoErrors();

    $doc = VendorDocument::where('vendor_id', $vendor->id)->first();

    expect($doc)->not->toBeNull();
    expect($doc->name)->toBe('W-9 Form 2026');
    expect($doc->type)->toBe('w9');
    expect($doc->status)->toBe(VendorDocument::STATUS_APPROVED);
    expect($doc->uploaded_by)->toBe($this->owner->id);
    expect($doc->reviewed_by)->toBe($this->owner->id);
});

it('validates internal upload fields', function () {
    $vendor = Vendor::factory()->create();

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('viewDocuments', $vendor->id)
        ->set('internalUploadName', '')
        ->call('uploadInternalDocument')
        ->assertHasErrors(['internalUploadFile', 'internalUploadName']);
});

// ─── Onboarding Status Badges ───

it('shows onboarded badge for completed vendors', function () {
    $vendor = Vendor::factory()->create([
        'onboarding_completed_at' => now()->subDay(),
    ]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->assertSee('Onboarded');
});

it('shows pending onboarding badge for active token vendors', function () {
    $vendor = Vendor::factory()->create();
    $vendor->generatePublicToken();

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->assertSee('Pending Onboarding');
});

it('shows document count in vendor table', function () {
    $vendor = Vendor::factory()->create();
    VendorDocument::factory()->count(3)->create(['vendor_id' => $vendor->id]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->assertSee('3 docs');
});

// ─── Vendor Token Methods ───

it('generates a public token with expiry', function () {
    $vendor = Vendor::factory()->create();
    $token = $vendor->generatePublicToken(14);

    expect($token)->toHaveLength(64);
    expect($vendor->public_token)->toBe($token);
    expect($vendor->public_token_expires_at->isFuture())->toBeTrue();
});

it('validates token correctly', function () {
    $vendor = Vendor::factory()->create();
    $vendor->generatePublicToken();

    expect($vendor->isPublicTokenValid())->toBeTrue();

    $vendor->update(['public_token_expires_at' => now()->subDay()]);
    expect($vendor->isPublicTokenValid())->toBeFalse();

    $vendor->update(['public_token' => null]);
    expect($vendor->isPublicTokenValid())->toBeFalse();
});

it('generates onboarding url', function () {
    $vendor = Vendor::factory()->create();
    $vendor->generatePublicToken();

    $url = $vendor->getOnboardingUrl();

    expect($url)->toContain('/vendor/onboarding/');
    expect($url)->toContain($vendor->public_token);
});

it('returns null onboarding url when no token', function () {
    $vendor = Vendor::factory()->create();

    expect($vendor->getOnboardingUrl())->toBeNull();
});

it('marks onboarding complete', function () {
    $vendor = Vendor::factory()->create();

    expect($vendor->hasCompletedOnboarding())->toBeFalse();

    $vendor->markOnboardingComplete();
    $vendor->refresh();

    expect($vendor->hasCompletedOnboarding())->toBeTrue();
    expect($vendor->onboarding_completed_at)->not->toBeNull();
});

// ─── VendorDocument Model Tests ───

it('vendor document has correct type labels', function () {
    $doc = VendorDocument::factory()->w9()->make();

    expect($doc->getTypeLabel())->toBe('W-9');
    expect($doc->isPending())->toBeTrue();
});

it('vendor document approve method works', function () {
    $vendor = Vendor::factory()->create();
    $doc = VendorDocument::factory()->create(['vendor_id' => $vendor->id]);

    $doc->approve($this->owner, 'Looks good');

    expect($doc->status)->toBe(VendorDocument::STATUS_APPROVED);
    expect($doc->reviewed_by)->toBe($this->owner->id);
    expect($doc->review_notes)->toBe('Looks good');
});

it('vendor document reject method works', function () {
    $vendor = Vendor::factory()->create();
    $doc = VendorDocument::factory()->create(['vendor_id' => $vendor->id]);

    $doc->reject($this->owner, 'Illegible');

    expect($doc->status)->toBe(VendorDocument::STATUS_REJECTED);
    expect($doc->review_notes)->toBe('Illegible');
});

it('vendor document detects expired status', function () {
    $doc = VendorDocument::factory()->insurance()->make([
        'expires_at' => now()->subMonth(),
    ]);

    expect($doc->isExpired())->toBeTrue();

    $doc2 = VendorDocument::factory()->insurance()->make([
        'expires_at' => now()->addYear(),
    ]);

    expect($doc2->isExpired())->toBeFalse();
});
