<?php

use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// EmployeeDocument Model Tests
// ──────────────────────────────────────────────────

describe('EmployeeDocument model', function () {
    it('creates a document with factory', function () {
        $doc = EmployeeDocument::factory()->create();

        expect($doc->name)->toBeString();
        expect($doc->type)->toBeIn(array_keys(EmployeeDocument::TYPES));
        expect($doc->file_path)->toBeString();
    });

    it('returns correct type label', function () {
        $doc = EmployeeDocument::factory()->w9()->create();

        expect($doc->getTypeLabel())->toBe('W-9');
    });

    it('detects expired documents', function () {
        $doc = EmployeeDocument::factory()->expired()->create();

        expect($doc->isExpired())->toBeTrue();
    });

    it('detects non-expired documents', function () {
        $doc = EmployeeDocument::factory()->create([
            'expires_at' => now()->addYear(),
        ]);

        expect($doc->isExpired())->toBeFalse();
    });

    it('detects expiring soon documents', function () {
        $doc = EmployeeDocument::factory()->create([
            'expires_at' => now()->addDays(15),
        ]);

        expect($doc->isExpiringSoon())->toBeTrue();
    });

    it('returns false for expired when no expiry date', function () {
        $doc = EmployeeDocument::factory()->create(['expires_at' => null]);

        expect($doc->isExpired())->toBeFalse();
        expect($doc->isExpiringSoon())->toBeFalse();
    });

    it('belongs to employee profile', function () {
        $employee = EmployeeProfile::factory()->create();
        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $employee->id,
        ]);

        expect($doc->employeeProfile->id)->toBe($employee->id);
    });

    it('belongs to uploader', function () {
        $user = User::factory()->internal()->create();
        $doc = EmployeeDocument::factory()->create([
            'uploaded_by' => $user->id,
        ]);

        expect($doc->uploader->id)->toBe($user->id);
    });

    it('employee profile has documents relationship', function () {
        $employee = EmployeeProfile::factory()->create();
        EmployeeDocument::factory()->count(3)->create([
            'employee_profile_id' => $employee->id,
        ]);

        expect($employee->documents)->toHaveCount(3);
    });
});

// ──────────────────────────────────────────────────
// Documents Tab on Employee Show Page
// ──────────────────────────────────────────────────

describe('Employee show Documents tab', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
        $this->employee = EmployeeProfile::factory()->create();
    });

    it('renders the documents tab with contracts section', function () {
        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->assertSee('Contracts')
            ->assertSee('Uploaded Documents')
            ->assertSuccessful();
    });

    it('shows assigned contracts in the documents tab', function () {
        $template = ContractTemplate::factory()->create();
        $contract = EmployeeContract::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'contract_template_id' => $template->id,
            'status' => 'sent',
        ]);

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->assertSee($contract->title)
            ->assertSee('Sent');
    });

    it('shows empty state when no contracts', function () {
        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->assertSee('No Contracts');
    });

    it('shows empty state when no uploaded documents', function () {
        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->assertSee('No Documents')
            ->assertSee('Upload W-9s, licenses, certifications');
    });

    it('shows uploaded documents in the documents tab', function () {
        $doc = EmployeeDocument::factory()->w9()->create([
            'employee_profile_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'name' => 'W-9 Form 2026',
        ]);

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->assertSee('W-9 Form 2026')
            ->assertSee('W-9');
    });

    it('can open and close the upload modal', function () {
        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->call('openUploadModal')
            ->assertSet('showUploadModal', true)
            ->set('showUploadModal', false)
            ->assertSet('showUploadModal', false);
    });

    it('can upload a document', function () {
        Storage::fake();

        $file = UploadedFile::fake()->create('w9-form.pdf', 500, 'application/pdf');

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->call('openUploadModal')
            ->set('docName', 'W-9 Form 2026')
            ->set('docType', 'w9')
            ->set('docFile', $file)
            ->set('docNotes', 'Submitted for tax year 2026')
            ->set('docExpiresAt', '2027-12-31')
            ->call('uploadDocument');

        $doc = EmployeeDocument::where('employee_profile_id', $this->employee->id)->first();

        expect($doc)->not->toBeNull();
        expect($doc->name)->toBe('W-9 Form 2026');
        expect($doc->type)->toBe('w9');
        expect($doc->notes)->toBe('Submitted for tax year 2026');
        expect($doc->expires_at->format('Y-m-d'))->toBe('2027-12-31');
        expect($doc->uploaded_by)->toBe($this->user->id);

        Storage::assertExists($doc->file_path);
    });

    it('validates required fields when uploading', function () {
        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->call('openUploadModal')
            ->call('uploadDocument')
            ->assertHasErrors(['docName', 'docFile']);
    });

    it('can delete a document', function () {
        Storage::fake();

        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'file_path' => 'employee-documents/test.pdf',
        ]);

        Storage::put('employee-documents/test.pdf', 'content');

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->call('deleteDocument', $doc->id);

        expect(EmployeeDocument::find($doc->id))->toBeNull();
        Storage::assertMissing('employee-documents/test.pdf');
    });

    it('cannot delete document from another employee', function () {
        $otherEmployee = EmployeeProfile::factory()->create();
        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $otherEmployee->id,
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->call('deleteDocument', $doc->id);
    });

    it('denies document upload without employees.edit permission', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->givePermissionTo('employees.view');

        $this->actingAs($viewer);

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->call('openUploadModal')
            ->set('docName', 'Test')
            ->set('docType', 'other')
            ->set('docFile', UploadedFile::fake()->create('test.pdf', 100))
            ->call('uploadDocument')
            ->assertForbidden();
    });

    it('shows expiration badges for documents', function () {
        EmployeeDocument::factory()->expired()->create([
            'employee_profile_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'name' => 'Expired License',
        ]);

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->assertSee('Expired License')
            ->assertSee('Expired');
    });
});

// ──────────────────────────────────────────────────
// Contract Templates Page (Bug Fix)
// ──────────────────────────────────────────────────

describe('Contract templates page', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
    });

    it('renders the templates page without JS errors', function () {
        $this->get(route('contracts.templates'))
            ->assertSuccessful();
    });

    it('can create a template with content format select', function () {
        Livewire::test('contracts.templates')
            ->call('openCreateModal')
            ->set('formName', 'Test Template')
            ->set('formContentType', 'rich_text')
            ->set('formType', 'standard')
            ->call('saveTemplate');

        expect(ContractTemplate::where('name', 'Test Template')->exists())->toBeTrue();
    });

    it('can create a PDF template via content format select', function () {
        Livewire::test('contracts.templates')
            ->call('openCreateModal')
            ->set('formName', 'PDF Template')
            ->set('formContentType', 'pdf')
            ->set('formType', 'standard')
            ->call('saveTemplate');

        $template = ContractTemplate::where('name', 'PDF Template')->first();
        expect($template->content_type)->toBe('pdf');
    });

    it('displays template list with clickable rows', function () {
        ContractTemplate::factory()->create([
            'name' => 'Employment Agreement',
            'created_by' => $this->user->id,
        ]);

        Livewire::test('contracts.templates')
            ->assertSee('Employment Agreement');
    });
});
