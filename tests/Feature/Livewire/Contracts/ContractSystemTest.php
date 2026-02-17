<?php

use App\Models\CompanySetting;
use App\Models\ContractDocument;
use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Notifications\ContractSentForSigning;
use App\Services\ContractService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

// ──────────────────────────────────────────────────
// ContractTemplate Model Tests
// ──────────────────────────────────────────────────

describe('ContractTemplate model', function () {
    it('creates a template with default values', function () {
        $template = ContractTemplate::factory()->create();

        expect($template->is_active)->toBeTrue();
        expect($template->content_type)->toBe('rich_text');
        expect($template->version)->toBe(1);
    });

    it('scopes to active templates', function () {
        ContractTemplate::factory()->create(['is_active' => true]);
        ContractTemplate::factory()->inactive()->create();

        expect(ContractTemplate::active()->count())->toBe(1);
    });

    it('scopes to rich text templates', function () {
        ContractTemplate::factory()->create(['content_type' => 'rich_text']);
        ContractTemplate::factory()->pdf()->create();

        expect(ContractTemplate::richText()->count())->toBe(1);
    });

    it('scopes to pdf templates', function () {
        ContractTemplate::factory()->create(['content_type' => 'rich_text']);
        ContractTemplate::factory()->pdf()->create();

        expect(ContractTemplate::pdf()->count())->toBe(1);
    });

    it('returns available merge tags', function () {
        $tags = ContractTemplate::getAvailableMergeTags();

        expect($tags)->toContain('{employee_name}');
        expect($tags)->toContain('{company_name}');
        expect($tags)->toContain('{pay_rate}');
        expect($tags)->toContain('{hire_date}');
        expect($tags)->toContain('{date}');
    });

    it('supports role-specific templates', function () {
        $template = ContractTemplate::factory()->roleSpecific('Painter')->create();

        expect($template->role_name)->toBe('Painter');
        expect($template->type)->toBe('role_specific');
    });

    it('has many employee contracts', function () {
        $template = ContractTemplate::factory()->create();
        EmployeeContract::factory()->create(['contract_template_id' => $template->id]);
        EmployeeContract::factory()->create(['contract_template_id' => $template->id]);

        expect($template->employeeContracts)->toHaveCount(2);
    });

    it('belongs to creator', function () {
        $user = User::factory()->internal()->create();
        $template = ContractTemplate::factory()->create(['created_by' => $user->id]);

        expect($template->createdBy->id)->toBe($user->id);
    });
});

// ──────────────────────────────────────────────────
// EmployeeContract Model Tests
// ──────────────────────────────────────────────────

describe('EmployeeContract model', function () {
    it('auto-generates token on creation', function () {
        $contract = EmployeeContract::factory()->create();

        expect($contract->token)->not->toBeNull();
        expect(strlen($contract->token))->toBe(36); // UUID length
    });

    it('identifies signed contracts', function () {
        $contract = EmployeeContract::factory()->signed()->create();

        expect($contract->isSigned())->toBeTrue();
    });

    it('identifies unsigned contracts', function () {
        $contract = EmployeeContract::factory()->create(['status' => 'draft']);

        expect($contract->isSigned())->toBeFalse();
    });

    it('detects expired contracts', function () {
        $contract = EmployeeContract::factory()->expired()->create();

        expect($contract->isExpired())->toBeTrue();
    });

    it('detects non-expired contracts', function () {
        $contract = EmployeeContract::factory()->create([
            'expires_at' => now()->addMonth(),
        ]);

        expect($contract->isExpired())->toBeFalse();
    });

    it('returns false for expired when no expiry date set', function () {
        $contract = EmployeeContract::factory()->create(['expires_at' => null]);

        expect($contract->isExpired())->toBeFalse();
    });

    it('marks contract as viewed only once', function () {
        $contract = EmployeeContract::factory()->sent()->create();

        $contract->markViewed();
        $firstViewedAt = $contract->viewed_at;

        expect($contract->status)->toBe('viewed');
        expect($firstViewedAt)->not->toBeNull();

        // Second call should not update
        $contract->markViewed();
        $contract->refresh();

        expect($contract->viewed_at->toDateTimeString())->toBe($firstViewedAt->toDateTimeString());
    });

    it('marks contract as signed with signature data', function () {
        $contract = EmployeeContract::factory()->sent()->create();

        $contract->markSigned([
            'signature_data' => 'data:image/png;base64,abc123',
            'signature_type' => 'drawn',
            'signer_name' => 'John Painter',
            'ip' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ]);

        $contract->refresh();

        expect($contract->status)->toBe('signed');
        expect($contract->signed_at)->not->toBeNull();
        expect($contract->signer_name)->toBe('John Painter');
        expect($contract->signer_ip)->toBe('192.168.1.1');
    });

    it('scopes pending contracts', function () {
        EmployeeContract::factory()->create(['status' => 'draft']);
        EmployeeContract::factory()->create(['status' => 'sent']);
        EmployeeContract::factory()->create(['status' => 'viewed']);
        EmployeeContract::factory()->signed()->create();

        expect(EmployeeContract::pending()->count())->toBe(3);
    });

    it('scopes signed contracts', function () {
        EmployeeContract::factory()->create(['status' => 'draft']);
        EmployeeContract::factory()->signed()->create();

        expect(EmployeeContract::signed()->count())->toBe(1);
    });

    it('belongs to employee profile', function () {
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->create([
            'employee_profile_id' => $employee->id,
        ]);

        expect($contract->employeeProfile->id)->toBe($employee->id);
    });

    it('belongs to template', function () {
        $template = ContractTemplate::factory()->create();
        $contract = EmployeeContract::factory()->create([
            'contract_template_id' => $template->id,
        ]);

        expect($contract->template->id)->toBe($template->id);
    });
});

// ──────────────────────────────────────────────────
// ContractService Tests
// ──────────────────────────────────────────────────

describe('ContractService', function () {
    it('resolves merge tags in contract content', function () {
        CompanySetting::getInstance()->update([
            'company_name' => 'Apex Painting Co',
        ]);

        $employee = EmployeeProfile::factory()->create([
            'employee_number' => 'EMP-042',
            'job_title' => 'Lead Painter',
            'department' => 'painting',
            'pay_rate' => 28.50,
            'pay_type' => 'hourly',
        ]);

        $service = new ContractService;
        $content = 'Welcome {employee_name}! Your position is {job_title} in {department}. Pay: {pay_rate} ({pay_type}). Employee #{employee_number}.';

        $resolved = $service->resolveMergeTags($content, $employee);

        expect($resolved)->toContain($employee->user->name);
        expect($resolved)->toContain('Lead Painter');
        expect($resolved)->toContain('painting');
        expect($resolved)->toContain('$28.50');
        expect($resolved)->toContain('hourly');
        expect($resolved)->toContain('EMP-042');
    });

    it('resolves company name merge tag', function () {
        CompanySetting::getInstance()->update([
            'company_name' => 'Test Painting LLC',
        ]);

        $employee = EmployeeProfile::factory()->create();
        $service = new ContractService;

        $resolved = $service->resolveMergeTags('{company_name}', $employee);

        expect($resolved)->toBe('Test Painting LLC');
    });

    it('resolves date merge tag to today', function () {
        $employee = EmployeeProfile::factory()->create();
        $service = new ContractService;

        $resolved = $service->resolveMergeTags('{date}', $employee);

        expect($resolved)->toBe(now()->format('m/d/Y'));
    });

    it('creates contract from template', function () {
        CompanySetting::getInstance()->update(['company_name' => 'Test Co']);

        $template = ContractTemplate::factory()->create([
            'name' => 'Standard Employment',
            'content' => 'Contract for {employee_name} at {company_name}.',
        ]);
        $employee = EmployeeProfile::factory()->create();

        $service = new ContractService;
        $contract = $service->createContractFromTemplate($template, $employee);

        expect($contract)->toBeInstanceOf(EmployeeContract::class);
        expect($contract->title)->toBe('Standard Employment');
        expect($contract->status)->toBe('draft');
        expect($contract->content)->toContain($employee->user->name);
        expect($contract->content)->toContain('Test Co');
        expect($contract->contract_template_id)->toBe($template->id);
        expect($contract->employee_profile_id)->toBe($employee->id);
    });

    it('sends a contract and sets sent_at', function () {
        $contract = EmployeeContract::factory()->create(['status' => 'draft']);

        $service = new ContractService;
        $service->sendContract($contract);

        $contract->refresh();

        expect($contract->status)->toBe('sent');
        expect($contract->sent_at)->not->toBeNull();
    });

    it('signs a contract with full signature data', function () {
        $contract = EmployeeContract::factory()->sent()->create();

        $service = new ContractService;
        $service->signContract($contract, [
            'signature_data' => 'data:image/png;base64,sig123',
            'signature_type' => 'typed',
            'signer_name' => 'Bob Painter',
            'ip' => '10.0.0.1',
            'user_agent' => 'TestBrowser/1.0',
        ]);

        $contract->refresh();

        expect($contract->status)->toBe('signed');
        expect($contract->signed_at)->not->toBeNull();
        expect($contract->signature_type)->toBe('typed');
        expect($contract->signer_name)->toBe('Bob Painter');
    });

    it('countersigns a contract', function () {
        $contract = EmployeeContract::factory()->signed()->create();
        $signer = User::factory()->internal()->create();

        $service = new ContractService;
        $service->countersignContract($contract, $signer, 'countersign-data-here');

        $contract->refresh();

        expect($contract->countersigned_by)->toBe($signer->id);
        expect($contract->countersigned_at)->not->toBeNull();
        expect($contract->countersign_data)->toBe('countersign-data-here');
    });

    it('voids a contract', function () {
        $contract = EmployeeContract::factory()->sent()->create();

        $service = new ContractService;
        $service->voidContract($contract);

        $contract->refresh();

        expect($contract->status)->toBe('voided');
    });

    it('creates contract from template with custom content', function () {
        $template = ContractTemplate::factory()->create([
            'name' => 'Custom Agreement',
            'content' => 'Original template content for {employee_name}.',
        ]);
        $employee = EmployeeProfile::factory()->create();

        $service = new ContractService;
        $contract = $service->createContractFromTemplate(
            $template,
            $employee,
            'Custom override content for {employee_name}.'
        );

        expect($contract->content)->toContain($employee->user->name);
        expect($contract->content)->toContain('Custom override content');
        expect($contract->content)->not->toContain('Original template content');
    });
});

// ──────────────────────────────────────────────────
// Contracts Show Page Tests
// ──────────────────────────────────────────────────

describe('Contracts show page', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
    });

    it('renders the contracts show page for authorized users', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create();

        $this->get(route('contracts.show', $contract))
            ->assertSuccessful()
            ->assertSee($contract->title);
    });

    it('displays contract content', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create([
            'content' => '<p>This is test contract content for display.</p>',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('This is test contract content for display.')
            ->assertSuccessful();
    });

    it('shows correct status badge for draft contract', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create(['status' => 'draft']);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('Draft')
            ->assertSuccessful();
    });

    it('shows correct status badge for signed contract', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create();

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('Signed')
            ->assertSee($contract->signer_name)
            ->assertSuccessful();
    });

    it('can void a draft contract', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create(['status' => 'draft']);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->call('voidContract');

        expect($contract->fresh()->status)->toBe('voided');
    });

    it('can send a draft contract via employer signature flow', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create(['status' => 'draft']);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->call('sendContract')
            ->assertSet('showRichTextEmployerSign', true)
            ->set('richTextSignatureType', 'typed')
            ->set('richTextTypedSignature', 'Employer Name')
            ->call('signRichTextAndSend');

        $contract->refresh();
        expect($contract->status)->toBe('sent');
        expect($contract->sent_at)->not->toBeNull();
        expect($contract->countersign_data)->toBe('Employer Name');
    });

    it('can countersign a signed contract', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create();

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->set('countersignData', 'Manager Name')
            ->call('countersign');

        $contract->refresh();
        expect($contract->countersigned_by)->toBe($this->user->id);
        expect($contract->countersigned_at)->not->toBeNull();
        expect($contract->countersign_data)->toBe('Manager Name');
    });

    it('denies access to users without employees.view permission', function () {
        $this->actingAs(User::factory()->internal()->create());

        $contract = EmployeeContract::factory()->withTemplate()->create();

        $this->get(route('contracts.show', $contract))
            ->assertForbidden();
    });

    it('shows employee name on the contract page', function () {
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->withTemplate()->create([
            'employee_profile_id' => $employee->id,
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee($employee->user->name)
            ->assertSuccessful();
    });
});

// ──────────────────────────────────────────────────
// Sidebar & Route Accessibility Tests
// ──────────────────────────────────────────────────

describe('Sidebar and route accessibility', function () {
    it('shows contracts link in sidebar for users with permission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('Contracts');
    });

    it('onboarding routes are accessible for authorized users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $this->get(route('onboarding.index'))
            ->assertSuccessful();
    });

    it('onboarding routes deny access without permission', function () {
        $user = User::factory()->internal()->create();
        $this->actingAs($user);

        $this->get(route('onboarding.index'))
            ->assertForbidden();
    });
});

// ──────────────────────────────────────────────────
// Contract PDF Download Tests
// ──────────────────────────────────────────────────

describe('Contract PDF download', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
    });

    it('can download contract as PDF', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create();

        $this->get(route('contracts.pdf', $contract))
            ->assertSuccessful()
            ->assertHeader('content-type', 'application/pdf');
    });

    it('requires employees.view to download PDF', function () {
        $user = User::factory()->internal()->create();
        $this->actingAs($user);

        $contract = EmployeeContract::factory()->withTemplate()->create();

        $this->get(route('contracts.pdf', $contract))
            ->assertForbidden();
    });

    it('can download signed contract via token as contract owner', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create();
        $this->actingAs($contract->employeeProfile->user);

        $this->get(route('contracts.sign.pdf', $contract->token))
            ->assertSuccessful()
            ->assertHeader('content-type', 'application/pdf');
    });

    it('cannot download unsigned contract via token', function () {
        $contract = EmployeeContract::factory()->withTemplate()->sent()->create();
        $this->actingAs($contract->employeeProfile->user);

        $this->get(route('contracts.sign.pdf', $contract->token))
            ->assertForbidden();
    });

    it('redirects unauthenticated users from signing PDF download', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create();

        auth()->logout();

        $this->get(route('contracts.sign.pdf', $contract->token))
            ->assertRedirect();
    });
});

// ──────────────────────────────────────────────────
// Resend & Signing Link Tests
// ──────────────────────────────────────────────────

describe('Resend and signing link', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
    });

    it('can resend contract notification', function () {
        Notification::fake();

        $contract = EmployeeContract::factory()->withTemplate()->sent()->create();

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->call('resendContract')
            ->assertHasNoErrors();

        Notification::assertSentTo(
            $contract->employeeProfile->user,
            ContractSentForSigning::class,
        );
    });

    it('shows resend button for sent contracts', function () {
        $contract = EmployeeContract::factory()->withTemplate()->sent()->create();

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('Resend Email')
            ->assertSee('Copy Signing Link');
    });

    it('does not show resend button for draft contracts', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create(['status' => 'draft']);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertDontSee('Resend Email')
            ->assertDontSee('Copy Signing Link');
    });
});

// ──────────────────────────────────────────────────
// Signature Display Tests
// ──────────────────────────────────────────────────

describe('Signature display', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
    });

    it('displays typed signature on show page', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create([
            'signature_type' => 'typed',
            'signature_data' => 'John Doe Signature',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('John Doe Signature');
    });

    it('displays drawn signature image on show page', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create([
            'signature_type' => 'drawn',
            'signature_data' => 'data:image/png;base64,abc123test',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('data:image/png;base64,abc123test', escape: false);
    });

    it('displays countersignature on show page', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create([
            'countersigned_by' => $this->user->id,
            'countersigned_at' => now(),
            'countersign_data' => 'Manager Signature Here',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('Manager Signature Here');
    });
});

// ──────────────────────────────────────────────────
// Signing Confirmation Download Tests
// ──────────────────────────────────────────────────

describe('Signing page access control', function () {
    it('requires authentication to view signing page', function () {
        $contract = EmployeeContract::factory()->withTemplate()->sent()->create();

        $this->get(route('contracts.sign', $contract->token))
            ->assertRedirect();
    });

    it('allows the contract employee to view signing page', function () {
        $contract = EmployeeContract::factory()->withTemplate()->sent()->create();
        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->assertSuccessful();
    });

    it('allows admins with employees.view to view signing page', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();
        $contract = EmployeeContract::factory()->withTemplate()->sent()->create();

        $this->actingAs($admin);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->assertSuccessful();
    });

    it('denies another employee from viewing signing page', function () {
        $contract = EmployeeContract::factory()->withTemplate()->sent()->create();
        $otherUser = User::factory()->internal()->create();

        $this->actingAs($otherUser);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->assertForbidden();
    });

    it('shows download button on signing confirmation for contract owner', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create();
        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->assertSee('Download Signed Copy');
    });
});

// ──────────────────────────────────────────────────
// My Contracts Download Tests
// ──────────────────────────────────────────────────

describe('My contracts download', function () {
    it('shows download button for signed contracts on my contracts', function () {
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create([
            'employee_profile_id' => $employee->id,
        ]);

        $this->actingAs($employee->user);

        Livewire::test('contracts.my-contracts')
            ->assertSee('Download');
    });

    it('does not show download for unsigned contracts on my contracts', function () {
        $employee = EmployeeProfile::factory()->create();
        EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $employee->id,
        ]);

        $this->actingAs($employee->user);

        Livewire::test('contracts.my-contracts')
            ->assertDontSee('Download');
    });
});

// ──────────────────────────────────────────────────
// Contract Document Tests
// ──────────────────────────────────────────────────

describe('Contract documents', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
        Storage::fake('private');
    });

    it('can upload document to contract', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create();
        $file = UploadedFile::fake()->create('employee-id.pdf', 100, 'application/pdf');

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->set('documentUpload', $file)
            ->call('uploadDocument')
            ->assertHasNoErrors();

        expect(ContractDocument::where('employee_contract_id', $contract->id)->count())->toBe(1);

        $doc = ContractDocument::where('employee_contract_id', $contract->id)->first();
        expect($doc->name)->toBe('employee-id.pdf');
        expect($doc->uploaded_by)->toBe($this->user->id);
        Storage::disk()->assertExists($doc->file_path);
    });

    it('can delete document from contract', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create();
        $file = UploadedFile::fake()->create('cert.pdf', 50, 'application/pdf');
        $path = $file->store('contract-documents', config('filesystems.default'));

        $doc = ContractDocument::factory()->create([
            'employee_contract_id' => $contract->id,
            'file_path' => $path,
            'uploaded_by' => $this->user->id,
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->call('deleteDocument', $doc->id)
            ->assertHasNoErrors();

        expect(ContractDocument::find($doc->id))->toBeNull();
    });

    it('shows uploaded documents on show page', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create();
        ContractDocument::factory()->create([
            'employee_contract_id' => $contract->id,
            'name' => 'test-certificate.pdf',
            'uploaded_by' => $this->user->id,
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('test-certificate.pdf');
    });

    it('shows no documents message when empty', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create();

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('No documents attached.');
    });

    it('validates document file type', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create();
        $file = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->set('documentUpload', $file)
            ->call('uploadDocument')
            ->assertHasErrors(['documentUpload']);
    });
});

// ──────────────────────────────────────────────────
// PDF Template Contract Tests
// ──────────────────────────────────────────────────

describe('PDF template contracts', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
        Storage::fake();
    });

    it('serves PDF via document route for contract owner', function () {
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
        $path = $file->store('contract-pdfs');

        $contract = EmployeeContract::factory()->sent()->create([
            'pdf_path' => $path,
        ]);

        $this->actingAs($contract->employeeProfile->user);

        $this->get(route('contracts.sign.document', $contract->token))
            ->assertSuccessful()
            ->assertHeader('content-type', 'application/pdf');
    });

    it('denies another user from accessing document route', function () {
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
        $path = $file->store('contract-pdfs');

        $contract = EmployeeContract::factory()->sent()->create([
            'pdf_path' => $path,
        ]);

        $otherUser = User::factory()->internal()->create();
        $this->actingAs($otherUser);

        $this->get(route('contracts.sign.document', $contract->token))
            ->assertForbidden();
    });

    it('serves PDF via authenticated document route', function () {
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
        $path = $file->store('contract-pdfs');

        $contract = EmployeeContract::factory()->withTemplate()->create([
            'pdf_path' => $path,
        ]);

        $this->get(route('contracts.document', $contract))
            ->assertSuccessful()
            ->assertHeader('content-type', 'application/pdf');
    });

    it('returns 404 for document route when no pdf_path', function () {
        $contract = EmployeeContract::factory()->sent()->create([
            'pdf_path' => null,
        ]);

        $this->actingAs($contract->employeeProfile->user);

        $this->get(route('contracts.sign.document', $contract->token))
            ->assertNotFound();
    });

    it('returns 404 for authenticated document route when no pdf_path', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create([
            'pdf_path' => null,
        ]);

        $this->get(route('contracts.document', $contract))
            ->assertNotFound();
    });

    it('downloads stored PDF instead of generating DomPDF', function () {
        $file = UploadedFile::fake()->create('original.pdf', 100, 'application/pdf');
        $path = $file->store('contract-pdfs');

        $contract = EmployeeContract::factory()->withTemplate()->create([
            'pdf_path' => $path,
        ]);

        $response = $this->get(route('contracts.pdf', $contract));

        $response->assertSuccessful();
        expect($response->headers->get('content-disposition'))->toContain('attachment');
    });

    it('falls back to DomPDF for rich text contracts', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create([
            'pdf_path' => null,
        ]);

        $this->get(route('contracts.pdf', $contract))
            ->assertSuccessful()
            ->assertHeader('content-type', 'application/pdf');
    });

    it('requires employees.view for authenticated document route', function () {
        $this->actingAs(User::factory()->internal()->create());

        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
        $path = $file->store('contract-pdfs');

        $contract = EmployeeContract::factory()->withTemplate()->create([
            'pdf_path' => $path,
        ]);

        $this->get(route('contracts.document', $contract))
            ->assertForbidden();
    });

    it('shows PDF embed on show page for contracts with pdf_path', function () {
        $file = UploadedFile::fake()->create('contract.pdf', 200, 'application/pdf');
        $path = $file->store('contract-pdfs');

        $contract = EmployeeContract::factory()->withTemplate()->create([
            'pdf_path' => $path,
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSeeHtml('iframe')
            ->assertSee('Uploaded Document');
    });
});
