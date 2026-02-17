<?php

use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// PDF Template Builder Tests
// ──────────────────────────────────────────────────

describe('PDF template builder', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
        Storage::fake('local');

        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('contracts/templates/test.pdf', $fixtureContent);
    });

    it('renders for a PDF template', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->assertSuccessful()
            ->assertSee('Place Form Fields')
            ->assertSee('+ Text')
            ->assertSee('+ Signature')
            ->assertSee('+ Date')
            ->assertSee('+ Checkbox');
    });

    it('can add a text field', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => ['pages' => ['1' => ['width' => 612, 'height' => 792]], 'fields' => []],
        ]);

        Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->call('addField', 'text')
            ->assertCount('pdfFields.fields', 1);
    });

    it('can add a signature field', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => ['pages' => ['1' => ['width' => 612, 'height' => 792]], 'fields' => []],
        ]);

        $component = Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->call('addField', 'signature');

        $fields = $component->get('pdfFields.fields');
        expect($fields[0]['type'])->toBe('signature');
        expect($fields[0]['height_pct'])->toBe(8.0); // Signature is taller
    });

    it('can remove a field', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    ['id' => 'f1', 'type' => 'text', 'label' => 'Name', 'page' => 1, 'x_pct' => 10, 'y_pct' => 10, 'width_pct' => 30, 'height_pct' => 3],
                ],
            ],
        ]);

        Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->call('removeField', 'f1')
            ->assertCount('pdfFields.fields', 0);
    });

    it('can update field position', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    ['id' => 'f1', 'type' => 'text', 'label' => 'Name', 'page' => 1, 'x_pct' => 10.0, 'y_pct' => 10.0, 'width_pct' => 30, 'height_pct' => 3],
                ],
            ],
        ]);

        $component = Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->call('updateFieldPosition', 'f1', 45.5, 60.2);

        $fields = $component->get('pdfFields.fields');
        expect($fields[0]['x_pct'])->toBe(45.5);
        expect($fields[0]['y_pct'])->toBe(60.2);
    });

    it('can update field property', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    ['id' => 'f1', 'type' => 'text', 'label' => 'Name', 'merge_tag' => null, 'page' => 1, 'x_pct' => 10, 'y_pct' => 10, 'width_pct' => 30, 'height_pct' => 3, 'font_size' => 12],
                ],
            ],
        ]);

        $component = Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->call('updateFieldProperty', 'f1', 'label', 'Employee Full Name')
            ->call('updateFieldProperty', 'f1', 'merge_tag', 'employee_name')
            ->call('updateFieldProperty', 'f1', 'font_size', 14);

        $fields = $component->get('pdfFields.fields');
        expect($fields[0]['label'])->toBe('Employee Full Name');
        expect($fields[0]['merge_tag'])->toBe('employee_name');
        expect($fields[0]['font_size'])->toBe(14);
    });

    it('rejects disallowed property names', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    ['id' => 'f1', 'type' => 'text', 'label' => 'Name', 'page' => 1, 'x_pct' => 10, 'y_pct' => 10, 'width_pct' => 30, 'height_pct' => 3],
                ],
            ],
        ]);

        $component = Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->call('updateFieldProperty', 'f1', 'type', 'signature'); // 'type' is not allowed

        $fields = $component->get('pdfFields.fields');
        expect($fields[0]['type'])->toBe('text'); // unchanged
    });

    it('can save fields to database', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => ['pages' => [], 'fields' => []],
        ]);

        Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->call('addField', 'text')
            ->call('addField', 'signature')
            ->call('saveFields')
            ->assertHasNoErrors();

        $template->refresh();
        expect(count($template->fields['fields']))->toBe(2);
    });

    it('denies access without settings.contracts permission', function () {
        $this->actingAs(User::factory()->internal()->create());

        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->assertForbidden();
    });
});

// ──────────────────────────────────────────────────
// PDF Signing Page Tests
// ──────────────────────────────────────────────────

describe('PDF signing page', function () {
    beforeEach(function () {
        Storage::fake('local');

        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('contracts/templates/test.pdf', $fixtureContent);
    });

    it('detects PDF contract on signing page', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
            'field_values' => ['field_name' => 'John Doe'],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->assertSuccessful()
            ->assertSee('Sign Contract');
    });

    it('pre-fills field values from contract', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
            'field_values' => ['field_name' => 'Pre-filled Name', 'field_date' => '01/15/2026'],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        $component = Livewire::test('contracts.signing', ['token' => $contract->token]);

        expect($component->get('fieldValues.field_name'))->toBe('Pre-filled Name');
        expect($component->get('fieldValues.field_date'))->toBe('01/15/2026');
    });

    it('signs a PDF contract through the signing page', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
            'field_values' => ['field_name' => 'John Doe'],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->set('signerName', 'John Doe')
            ->set('fieldValues.field_signature', 'John D.')
            ->set('agreed', true)
            ->call('sign')
            ->assertSet('signed', true);

        $contract->refresh();
        expect($contract->status)->toBe('signed');
        expect($contract->signer_name)->toBe('John Doe');
        expect($contract->pdf_path)->toStartWith('signed-contracts/');
    });

    it('falls back to non-PDF signing for rich text contracts', function () {
        $template = ContractTemplate::factory()->create([
            'content_type' => 'rich_text',
            'content' => '<p>Test content</p>',
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
            'content' => '<p>Test content</p>',
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->set('signerName', 'Jane Smith')
            ->set('signatureType', 'typed')
            ->set('typedSignature', 'Jane S.')
            ->set('agreed', true)
            ->call('sign')
            ->assertSet('signed', true);

        $contract->refresh();
        expect($contract->status)->toBe('signed');
    });

    it('shows signed confirmation for already signed PDF contract', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $contract = EmployeeContract::factory()->signed()->create([
            'contract_template_id' => $template->id,
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->assertSet('signed', true)
            ->assertSee('Contract Signed')
            ->assertSee('Download Signed Copy');
    });

    it('requires all validation fields for signing', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->call('sign')
            ->assertHasErrors(['signerName', 'agreed']);
    });
});

// ──────────────────────────────────────────────────
// Assign Modal PDF Template Tests
// ──────────────────────────────────────────────────

describe('Assign modal with PDF templates', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
        Storage::fake('local');

        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('contracts/templates/test.pdf', $fixtureContent);
    });

    it('lists both rich text and PDF templates', function () {
        ContractTemplate::factory()->create(['name' => 'Rich Text Template', 'content_type' => 'rich_text']);
        ContractTemplate::factory()->pdf()->create(['name' => 'PDF Template', 'pdf_path' => 'contracts/templates/test.pdf']);

        $employee = EmployeeProfile::factory()->create();

        Livewire::test('contracts.assign-modal', ['employeeProfile' => $employee])
            ->call('openModal')
            ->assertSee('Rich Text Template')
            ->assertSee('PDF Template');
    });

    it('shows PDF template notice when PDF template selected', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'name' => 'Employment Agreement PDF',
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $employee = EmployeeProfile::factory()->create();

        Livewire::test('contracts.assign-modal', ['employeeProfile' => $employee])
            ->call('openModal')
            ->set('selectedTemplateId', $template->id)
            ->assertSee('PDF Template')
            ->assertSee('Fields will be auto-filled');
    });

    it('can assign a PDF template contract', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $employee = EmployeeProfile::factory()->create();

        Livewire::test('contracts.assign-modal', ['employeeProfile' => $employee])
            ->call('openModal')
            ->set('selectedTemplateId', $template->id)
            ->call('assignContract')
            ->assertHasNoErrors();

        $contract = EmployeeContract::where('employee_profile_id', $employee->id)->first();
        expect($contract)->not->toBeNull();
        expect($contract->pdf_path)->toBe('contracts/templates/test.pdf');
        expect($contract->status)->toBe('draft');
    });

    it('can assign and send PDF template contract immediately', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $employee = EmployeeProfile::factory()->create();

        Livewire::test('contracts.assign-modal', ['employeeProfile' => $employee])
            ->call('openModal')
            ->set('selectedTemplateId', $template->id)
            ->set('sendImmediately', true)
            ->call('assignContract')
            ->assertHasNoErrors();

        $contract = EmployeeContract::where('employee_profile_id', $employee->id)->first();
        expect($contract->status)->toBe('sent');
        expect($contract->sent_at)->not->toBeNull();
    });
});

// ──────────────────────────────────────────────────
// Show Page PDF Updates
// ──────────────────────────────────────────────────

describe('Show page PDF updates', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
        Storage::fake('local');

        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('signed-contracts/test-signed.pdf', $fixtureContent);
    });

    it('shows Download Signed PDF button for signed PDF contracts', function () {
        $contract = EmployeeContract::factory()->withTemplate()->signed()->create([
            'pdf_path' => 'signed-contracts/test-signed.pdf',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('Download Signed PDF')
            ->assertSee('Signed Document');
    });

    it('shows Uploaded Document label for unsigned PDF contracts', function () {
        $contract = EmployeeContract::factory()->withTemplate()->create([
            'pdf_path' => 'signed-contracts/test-signed.pdf',
            'status' => 'draft',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('Uploaded Document')
            ->assertDontSee('Download Signed PDF');
    });
});

// ──────────────────────────────────────────────────
// Employer Signing on Show Page
// ──────────────────────────────────────────────────

describe('Employer signing on show page', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
        Storage::fake('local');

        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('contracts/templates/test.pdf', $fixtureContent);
    });

    it('shows Sign & Send button when employer fields exist and are unfilled', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    [
                        'id' => 'field_emp_sig', 'type' => 'signature', 'label' => 'Employee Signature',
                        'signer_role' => 'employee', 'page' => 1, 'x_pct' => 20.0, 'y_pct' => 50.0,
                        'width_pct' => 40.0, 'height_pct' => 8.0, 'required' => true, 'font_size' => 12,
                    ],
                    [
                        'id' => 'field_mgr_sig', 'type' => 'signature', 'label' => 'Manager Signature',
                        'signer_role' => 'employer', 'page' => 1, 'x_pct' => 20.0, 'y_pct' => 70.0,
                        'width_pct' => 40.0, 'height_pct' => 8.0, 'required' => true, 'font_size' => 12,
                    ],
                ],
            ],
        ]);

        $contract = EmployeeContract::factory()->create([
            'contract_template_id' => $template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => [],
            'status' => 'draft',
        ]);

        $html = Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->assertSee('Sign & Send Contract')
            ->html();

        // "Send Contract" button should NOT appear standalone (only as "Sign & Send Contract")
        expect($html)->not->toContain('>Send Contract</');
        expect($html)->toContain('>Sign &amp; Send Contract</');
    });

    it('opens employer signing modal and loads field values', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    [
                        'id' => 'field_mgr_sig', 'type' => 'signature', 'label' => 'Manager Signature',
                        'signer_role' => 'employer', 'page' => 1, 'x_pct' => 20.0, 'y_pct' => 70.0,
                        'width_pct' => 40.0, 'height_pct' => 8.0, 'required' => true, 'font_size' => 12,
                    ],
                ],
            ],
        ]);

        $contract = EmployeeContract::factory()->create([
            'contract_template_id' => $template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => ['existing_field' => 'existing_value'],
            'status' => 'draft',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->call('openEmployerSigning')
            ->assertSet('showEmployerSigning', true)
            ->assertSet('employerFieldValues.existing_field', 'existing_value');
    });

    it('validates required employer signature fields before sending', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    [
                        'id' => 'field_mgr_sig', 'type' => 'signature', 'label' => 'Manager Signature',
                        'signer_role' => 'employer', 'page' => 1, 'x_pct' => 20.0, 'y_pct' => 70.0,
                        'width_pct' => 40.0, 'height_pct' => 8.0, 'required' => true, 'font_size' => 12,
                    ],
                ],
            ],
        ]);

        $contract = EmployeeContract::factory()->create([
            'contract_template_id' => $template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => [],
            'status' => 'draft',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->call('openEmployerSigning')
            ->call('signAsEmployerAndSend')
            ->assertHasErrors('employerFieldValues');

        expect($contract->fresh()->status)->toBe('draft');
    });

    it('successfully signs employer fields and sends contract', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    [
                        'id' => 'field_mgr_sig', 'type' => 'signature', 'label' => 'Manager Signature',
                        'signer_role' => 'employer', 'page' => 1, 'x_pct' => 20.0, 'y_pct' => 70.0,
                        'width_pct' => 40.0, 'height_pct' => 8.0, 'required' => true, 'font_size' => 12,
                    ],
                ],
            ],
        ]);

        $contract = EmployeeContract::factory()->create([
            'contract_template_id' => $template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => [],
            'status' => 'draft',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->call('openEmployerSigning')
            ->set('employerFieldValues.field_mgr_sig', 'Boss Name')
            ->call('signAsEmployerAndSend')
            ->assertHasNoErrors()
            ->assertSet('showEmployerSigning', false);

        $contract->refresh();
        expect($contract->status)->toBe('sent');
        expect($contract->countersigned_by)->toBe($this->user->id);
        expect($contract->countersign_data)->toBe('Boss Name');
        expect($contract->sent_at)->not->toBeNull();
    });

    it('saves employer field values to contract field_values', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    [
                        'id' => 'field_mgr_sig', 'type' => 'signature', 'label' => 'Manager Signature',
                        'signer_role' => 'employer', 'page' => 1, 'x_pct' => 20.0, 'y_pct' => 70.0,
                        'width_pct' => 40.0, 'height_pct' => 8.0, 'required' => true, 'font_size' => 12,
                    ],
                    [
                        'id' => 'field_mgr_date', 'type' => 'date', 'label' => 'Manager Date',
                        'signer_role' => 'employer', 'page' => 1, 'x_pct' => 20.0, 'y_pct' => 80.0,
                        'width_pct' => 30.0, 'height_pct' => 3.0, 'required' => false, 'font_size' => 12,
                    ],
                ],
            ],
        ]);

        $contract = EmployeeContract::factory()->create([
            'contract_template_id' => $template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => ['pre_existing' => 'value'],
            'status' => 'draft',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->call('openEmployerSigning')
            ->set('employerFieldValues.field_mgr_sig', 'The Boss')
            ->set('employerFieldValues.field_mgr_date', '2026-02-17')
            ->call('signAsEmployerAndSend')
            ->assertHasNoErrors();

        $contract->refresh();
        expect($contract->field_values)->toHaveKey('field_mgr_sig');
        expect($contract->field_values['field_mgr_sig'])->toBe('The Boss');
        expect($contract->field_values)->toHaveKey('field_mgr_date');
        expect($contract->field_values['field_mgr_date'])->toBe('2026-02-17');
        expect($contract->field_values)->toHaveKey('pre_existing');
    });

    it('shows employer signature modal for rich text contracts before sending', function () {
        $template = ContractTemplate::factory()->create([
            'content_type' => 'rich_text',
            'content' => '<p>Employment Agreement</p>',
        ]);

        $contract = EmployeeContract::factory()->create([
            'contract_template_id' => $template->id,
            'content' => '<p>Employment Agreement</p>',
            'status' => 'draft',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->call('sendContract')
            ->assertSet('showRichTextEmployerSign', true);

        // Contract should still be draft
        expect($contract->fresh()->status)->toBe('draft');
    });

    it('saves rich text employer signature and sends contract', function () {
        $template = ContractTemplate::factory()->create([
            'content_type' => 'rich_text',
            'content' => '<p>Employment Agreement</p>',
        ]);

        $contract = EmployeeContract::factory()->create([
            'contract_template_id' => $template->id,
            'content' => '<p>Employment Agreement</p>',
            'status' => 'draft',
        ]);

        Livewire::test('contracts.show', ['employeeContract' => $contract])
            ->set('showRichTextEmployerSign', true)
            ->set('richTextSignatureType', 'typed')
            ->set('richTextTypedSignature', 'Company Rep')
            ->call('signRichTextAndSend')
            ->assertHasNoErrors()
            ->assertSet('showRichTextEmployerSign', false);

        $contract->refresh();
        expect($contract->status)->toBe('sent');
        expect($contract->countersigned_by)->toBe($this->user->id);
        expect($contract->countersign_data)->toBe('Company Rep');
    });
});
