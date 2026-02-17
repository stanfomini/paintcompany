<?php

use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Notifications\DocumentRejected;
use App\Notifications\DocumentUploadedForReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// My Documents Page - Employee Self-Service
// ──────────────────────────────────────────────────

describe('My Documents page', function () {
    beforeEach(function () {
        $this->employee = EmployeeProfile::factory()->create();
        $this->user = $this->employee->user;
        $this->user->update(['type' => 'internal']);
        $this->actingAs($this->user);
    });

    it('renders the my documents page', function () {
        $this->get(route('my.documents'))
            ->assertSuccessful();
    });

    it('shows empty state when no documents', function () {
        Livewire::test('employees.my-documents')
            ->assertSee('No Documents')
            ->assertSee('haven\'t uploaded any documents yet');
    });

    it('shows uploaded documents', function () {
        EmployeeDocument::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'name' => 'My W-9 Form',
            'type' => 'w9',
        ]);

        Livewire::test('employees.my-documents')
            ->assertSee('My W-9 Form')
            ->assertSee('W-9');
    });

    it('can upload a document and notify admins', function () {
        Storage::fake();
        Notification::fake();

        $admin = User::factory()->internal()->withFullAccess()->create();

        $file = UploadedFile::fake()->create('my-w9.pdf', 500, 'application/pdf');

        Livewire::test('employees.my-documents')
            ->call('openUploadModal')
            ->set('docName', 'My W-9 2026')
            ->set('docType', 'w9')
            ->set('docFile', $file)
            ->set('docNotes', 'Tax year 2026')
            ->call('uploadDocument')
            ->assertSet('showUploadModal', false);

        $doc = EmployeeDocument::where('employee_profile_id', $this->employee->id)->first();

        expect($doc)->not->toBeNull();
        expect($doc->name)->toBe('My W-9 2026');
        expect($doc->type)->toBe('w9');
        expect($doc->status)->toBe('pending');
        expect($doc->uploaded_by)->toBe($this->user->id);
        Storage::assertExists($doc->file_path);

        Notification::assertSentTo($admin, DocumentUploadedForReview::class);
    });

    it('validates required fields when uploading', function () {
        Livewire::test('employees.my-documents')
            ->call('openUploadModal')
            ->call('uploadDocument')
            ->assertHasErrors(['docName', 'docFile']);
    });

    it('can filter documents by status', function () {
        EmployeeDocument::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'name' => 'Pending Doc',
            'status' => 'pending',
        ]);

        EmployeeDocument::factory()->approved()->create([
            'employee_profile_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'name' => 'Approved Doc',
        ]);

        Livewire::test('employees.my-documents')
            ->assertSee('Pending Doc')
            ->assertSee('Approved Doc')
            ->call('setFilter', 'pending')
            ->assertSee('Pending Doc')
            ->assertDontSee('Approved Doc')
            ->call('setFilter', 'approved')
            ->assertSee('Approved Doc')
            ->assertDontSee('Pending Doc');
    });

    it('can re-upload a rejected document', function () {
        Storage::fake();
        Notification::fake();

        $admin = User::factory()->internal()->withFullAccess()->create();

        Storage::put('employee-documents/old.pdf', 'old content');

        $doc = EmployeeDocument::factory()->rejected()->create([
            'employee_profile_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'name' => 'Bad W-9',
            'file_path' => 'employee-documents/old.pdf',
        ]);

        $newFile = UploadedFile::fake()->create('new-w9.pdf', 500, 'application/pdf');

        Livewire::test('employees.my-documents')
            ->call('openReuploadModal', $doc->id)
            ->set('reuploadFile', $newFile)
            ->call('reuploadDocument')
            ->assertSet('showReuploadModal', false);

        $doc->refresh();
        expect($doc->status)->toBe('pending');
        expect($doc->reviewed_by)->toBeNull();
        expect($doc->reviewed_at)->toBeNull();
        expect($doc->review_notes)->toBeNull();
        Storage::assertMissing('employee-documents/old.pdf');
        Storage::assertExists($doc->file_path);

        Notification::assertSentTo($admin, DocumentUploadedForReview::class);
    });

    it('cannot re-upload a non-rejected document', function () {
        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'status' => 'pending',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('employees.my-documents')
            ->call('openReuploadModal', $doc->id);
    });

    it('cannot access without employee profile', function () {
        $userNoProfile = User::factory()->internal()->create();
        $this->actingAs($userNoProfile);

        Livewire::test('employees.my-documents')
            ->assertForbidden();
    });

    it('shows rejection reason on rejected documents', function () {
        EmployeeDocument::factory()->rejected()->create([
            'employee_profile_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'name' => 'Rejected Doc',
            'review_notes' => 'Missing signature on page 2',
        ]);

        Livewire::test('employees.my-documents')
            ->assertSee('Rejected Doc')
            ->assertSee('Missing signature on page 2');
    });

    it('shows approval info on approved documents', function () {
        $reviewer = User::factory()->internal()->create(['name' => 'Jane Reviewer']);

        EmployeeDocument::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'name' => 'Approved License',
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        Livewire::test('employees.my-documents')
            ->assertSee('Approved License')
            ->assertSee('Jane Reviewer');
    });

    it('does not show documents from other employees', function () {
        $otherEmployee = EmployeeProfile::factory()->create();
        EmployeeDocument::factory()->create([
            'employee_profile_id' => $otherEmployee->id,
            'name' => 'Other Employee Doc',
        ]);

        Livewire::test('employees.my-documents')
            ->assertDontSee('Other Employee Doc');
    });
});

// ──────────────────────────────────────────────────
// Admin Document Review (Employee Show page)
// ──────────────────────────────────────────────────

describe('Admin document review', function () {
    beforeEach(function () {
        $this->admin = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->admin);
        $this->employee = EmployeeProfile::factory()->create();
    });

    it('can approve a pending document', function () {
        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->call('approveDocument', $doc->id);

        $doc->refresh();
        expect($doc->status)->toBe('approved');
        expect($doc->reviewed_by)->toBe($this->admin->id);
        expect($doc->reviewed_at)->not->toBeNull();
    });

    it('can reject a pending document with notes', function () {
        Notification::fake();

        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->call('openRejectModal', $doc->id)
            ->set('rejectNotes', 'Missing signature')
            ->call('rejectDocument')
            ->assertSet('showRejectModal', false);

        $doc->refresh();
        expect($doc->status)->toBe('rejected');
        expect($doc->reviewed_by)->toBe($this->admin->id);
        expect($doc->review_notes)->toBe('Missing signature');

        $employee = $this->employee->user;
        Notification::assertSentTo($employee, DocumentRejected::class);
    });

    it('requires notes when rejecting', function () {
        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->call('openRejectModal', $doc->id)
            ->call('rejectDocument')
            ->assertHasErrors(['rejectNotes']);
    });

    it('shows document status badges in the table', function () {
        EmployeeDocument::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'name' => 'Pending W-9',
            'status' => 'pending',
        ]);

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('setTab', 'documents')
            ->assertSee('Pending W-9')
            ->assertSee('Pending Review');
    });

    it('denies approve without employees.edit permission', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->givePermissionTo('employees.view');
        $this->actingAs($viewer);

        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        Livewire::test('employees.show', ['employeeProfile' => $this->employee])
            ->call('approveDocument', $doc->id)
            ->assertForbidden();
    });
});
