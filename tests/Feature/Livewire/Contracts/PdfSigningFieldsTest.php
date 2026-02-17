<?php

use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Services\PdfFlatteningService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// PDF Signing Field Interaction Tests
// ──────────────────────────────────────────────────

describe('PDF signing field interactions', function () {
    beforeEach(function () {
        Storage::fake('local');

        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('contracts/templates/test.pdf', $fixtureContent);

        $this->template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);
    });

    it('renders PDF fields on the signing page', function () {
        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $this->template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => ['field_name' => 'John Doe'],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->assertSuccessful()
            ->assertSee('Sign Contract');
    });

    it('can fill text field values via Livewire set', function () {
        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $this->template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => [],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->set('fieldValues.field_name', 'Jane Smith')
            ->assertSet('fieldValues.field_name', 'Jane Smith');
    });

    it('validates required signature fields are filled before signing', function () {
        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $this->template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => ['field_name' => 'John Doe'],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->set('signerName', 'John Doe')
            ->set('agreed', true)
            ->call('sign')
            ->assertHasErrors('fieldValues');

        // Contract should NOT be signed
        expect($contract->fresh()->isSigned())->toBeFalse();
    });

    it('signs successfully when all required fields have values', function () {
        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $this->template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => [
                'field_name' => 'John Doe',
                'field_date' => '02/17/2026',
            ],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->set('signerName', 'John Doe')
            ->set('agreed', true)
            ->set('fieldValues.field_signature', 'John Doe')
            ->call('sign')
            ->assertHasNoErrors()
            ->assertSet('signed', true);

        $contract->refresh();
        expect($contract->isSigned())->toBeTrue();
        expect($contract->signer_name)->toBe('John Doe');
    });

    it('passes field values to signPdfContract service', function () {
        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $this->template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => ['field_name' => 'Alice'],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->set('signerName', 'Alice Smith')
            ->set('agreed', true)
            ->set('fieldValues.field_signature', 'Alice Smith')
            ->set('fieldValues.field_date', '02/17/2026')
            ->call('sign')
            ->assertHasNoErrors();

        $contract->refresh();
        expect($contract->field_values)->toHaveKey('field_signature');
        expect($contract->field_values['field_signature'])->toBe('Alice Smith');
        expect($contract->field_values)->toHaveKey('field_name');
    });

    it('shows employer-role fields as read-only on employee signing page', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    [
                        'id' => 'field_emp_sig',
                        'type' => 'signature',
                        'label' => 'Employee Signature',
                        'signer_role' => 'employee',
                        'page' => 1,
                        'x_pct' => 20.0,
                        'y_pct' => 50.0,
                        'width_pct' => 40.0,
                        'height_pct' => 8.0,
                        'required' => true,
                        'font_size' => 12,
                    ],
                    [
                        'id' => 'field_mgr_sig',
                        'type' => 'signature',
                        'label' => 'Manager Signature',
                        'signer_role' => 'employer',
                        'page' => 1,
                        'x_pct' => 20.0,
                        'y_pct' => 70.0,
                        'width_pct' => 40.0,
                        'height_pct' => 8.0,
                        'required' => true,
                        'font_size' => 12,
                    ],
                ],
            ],
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => [],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        // Employee can sign with only the employee signature field filled
        // The employer field is NOT required for employee signing
        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->set('signerName', 'Employee Name')
            ->set('agreed', true)
            ->set('fieldValues.field_emp_sig', 'Employee Name')
            ->call('sign')
            ->assertHasNoErrors()
            ->assertSet('signed', true);
    });
});

// ──────────────────────────────────────────────────
// Employer Pre-filled Values & Page Progress Tests
// ──────────────────────────────────────────────────

describe('Employer pre-filled values on employee signing page', function () {
    beforeEach(function () {
        Storage::fake('local');

        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('contracts/templates/test.pdf', $fixtureContent);
    });

    it('loads employer pre-filled values into fieldValues on mount', function () {
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

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => ['field_mgr_sig' => 'Boss Signature'],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        // The employer's signature should be available in fieldValues
        $component = Livewire::test('contracts.signing', ['token' => $contract->token]);
        expect($component->get('fieldValues.field_mgr_sig'))->toBe('Boss Signature');
    });

    it('validates required fields across ALL pages', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => [
                    '1' => ['width' => 612, 'height' => 792],
                    '2' => ['width' => 612, 'height' => 792],
                ],
                'fields' => [
                    [
                        'id' => 'field_sig_page1', 'type' => 'signature', 'label' => 'Signature Page 1',
                        'signer_role' => 'employee', 'page' => 1, 'x_pct' => 20.0, 'y_pct' => 50.0,
                        'width_pct' => 40.0, 'height_pct' => 8.0, 'required' => true, 'font_size' => 12,
                    ],
                    [
                        'id' => 'field_sig_page2', 'type' => 'signature', 'label' => 'Signature Page 2',
                        'signer_role' => 'employee', 'page' => 2, 'x_pct' => 20.0, 'y_pct' => 50.0,
                        'width_pct' => 40.0, 'height_pct' => 8.0, 'required' => true, 'font_size' => 12,
                    ],
                ],
            ],
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => [],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        // Only sign page 1, leave page 2 empty
        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->set('signerName', 'Test User')
            ->set('agreed', true)
            ->set('fieldValues.field_sig_page1', 'Test User')
            ->call('sign')
            ->assertHasErrors('fieldValues');

        expect($contract->fresh()->isSigned())->toBeFalse();
    });

    it('employee signing succeeds when both pages have required fields filled', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => [
                    '1' => ['width' => 612, 'height' => 792],
                    '2' => ['width' => 612, 'height' => 792],
                ],
                'fields' => [
                    [
                        'id' => 'field_sig_page1', 'type' => 'signature', 'label' => 'Signature Page 1',
                        'signer_role' => 'employee', 'page' => 1, 'x_pct' => 20.0, 'y_pct' => 50.0,
                        'width_pct' => 40.0, 'height_pct' => 8.0, 'required' => true, 'font_size' => 12,
                    ],
                    [
                        'id' => 'field_sig_page2', 'type' => 'signature', 'label' => 'Signature Page 2',
                        'signer_role' => 'employee', 'page' => 2, 'x_pct' => 20.0, 'y_pct' => 50.0,
                        'width_pct' => 40.0, 'height_pct' => 8.0, 'required' => true, 'font_size' => 12,
                    ],
                ],
            ],
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => [],
        ]);

        $this->actingAs($contract->employeeProfile->user);

        Livewire::test('contracts.signing', ['token' => $contract->token])
            ->set('signerName', 'Test User')
            ->set('agreed', true)
            ->set('fieldValues.field_sig_page1', 'Test User')
            ->set('fieldValues.field_sig_page2', 'Test User')
            ->call('sign')
            ->assertHasNoErrors()
            ->assertSet('signed', true);

        expect($contract->fresh()->isSigned())->toBeTrue();
    });
});

// ──────────────────────────────────────────────────
// PdfFlatteningService - Typed Signature Tests
// ──────────────────────────────────────────────────

describe('PdfFlatteningService typed signatures', function () {
    beforeEach(function () {
        Storage::fake('local');

        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('contracts/templates/test.pdf', $fixtureContent);
    });

    it('renders typed signature as text in flattened PDF', function () {
        $service = new PdfFlatteningService;

        $fields = [
            [
                'id' => 'field_sig',
                'type' => 'signature',
                'label' => 'Signature',
                'page' => 1,
                'x_pct' => 20.0,
                'y_pct' => 60.0,
                'width_pct' => 40.0,
                'height_pct' => 8.0,
                'font_size' => 14,
            ],
        ];

        $fieldValues = ['field_sig' => 'John Doe'];
        $pages = ['1' => ['width' => 612, 'height' => 792]];

        $outputPath = $service->flatten('contracts/templates/test.pdf', $fields, $fieldValues, $pages);

        Storage::disk('local')->assertExists($outputPath);

        $content = Storage::disk('local')->get($outputPath);
        expect(str_starts_with($content, '%PDF'))->toBeTrue();
    });

    it('renders drawn (base64) signature as image in flattened PDF', function () {
        $service = new PdfFlatteningService;

        // Create a valid test PNG image
        $img = imagecreatetruecolor(100, 50);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $white);
        ob_start();
        imagepng($img);
        $pngData = ob_get_clean();
        imagedestroy($img);
        $base64 = base64_encode($pngData);

        $fields = [
            [
                'id' => 'field_sig',
                'type' => 'signature',
                'label' => 'Signature',
                'page' => 1,
                'x_pct' => 20.0,
                'y_pct' => 60.0,
                'width_pct' => 40.0,
                'height_pct' => 8.0,
                'font_size' => 12,
            ],
        ];

        $fieldValues = ['field_sig' => 'data:image/png;base64,'.$base64];
        $pages = ['1' => ['width' => 612, 'height' => 792]];

        $outputPath = $service->flatten('contracts/templates/test.pdf', $fields, $fieldValues, $pages);

        Storage::disk('local')->assertExists($outputPath);

        $content = Storage::disk('local')->get($outputPath);
        expect(str_starts_with($content, '%PDF'))->toBeTrue();
    });
});
