<?php

use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\ContractService;
use App\Services\PdfFlatteningService;
use Illuminate\Support\Facades\Storage;

// ──────────────────────────────────────────────────
// PdfFlatteningService Tests
// ──────────────────────────────────────────────────

describe('PdfFlatteningService', function () {
    beforeEach(function () {
        Storage::fake('local');

        // Copy the test fixture PDF into the fake disk
        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('contracts/templates/test.pdf', $fixtureContent);
    });

    it('flattens a PDF with text fields', function () {
        $service = new PdfFlatteningService;

        $fields = [
            [
                'id' => 'field_name',
                'type' => 'text',
                'label' => 'Employee Name',
                'page' => 1,
                'x_pct' => 20.0,
                'y_pct' => 30.0,
                'width_pct' => 40.0,
                'height_pct' => 3.0,
                'font_size' => 12,
            ],
        ];

        $fieldValues = ['field_name' => 'John Doe'];
        $pages = ['1' => ['width' => 612, 'height' => 792]];

        $outputPath = $service->flatten('contracts/templates/test.pdf', $fields, $fieldValues, $pages);

        expect($outputPath)->toStartWith('signed-contracts/');
        expect($outputPath)->toEndWith('.pdf');
        Storage::disk('local')->assertExists($outputPath);

        // Verify the output is a valid PDF
        $content = Storage::disk('local')->get($outputPath);
        expect(str_starts_with($content, '%PDF'))->toBeTrue();
    });

    it('flattens a PDF with date fields', function () {
        $service = new PdfFlatteningService;

        $fields = [
            [
                'id' => 'field_date',
                'type' => 'date',
                'label' => 'Date',
                'page' => 1,
                'x_pct' => 20.0,
                'y_pct' => 40.0,
                'width_pct' => 30.0,
                'height_pct' => 3.0,
                'font_size' => 12,
            ],
        ];

        $fieldValues = ['field_date' => '02/17/2026'];
        $pages = ['1' => ['width' => 612, 'height' => 792]];

        $outputPath = $service->flatten('contracts/templates/test.pdf', $fields, $fieldValues, $pages);

        Storage::disk('local')->assertExists($outputPath);
    });

    it('flattens a PDF with checkbox fields', function () {
        $service = new PdfFlatteningService;

        $fields = [
            [
                'id' => 'field_check',
                'type' => 'checkbox',
                'label' => 'I Agree',
                'page' => 1,
                'x_pct' => 10.0,
                'y_pct' => 50.0,
                'width_pct' => 3.0,
                'height_pct' => 3.0,
                'font_size' => 12,
            ],
        ];

        $fieldValues = ['field_check' => 'true'];
        $pages = ['1' => ['width' => 612, 'height' => 792]];

        $outputPath = $service->flatten('contracts/templates/test.pdf', $fields, $fieldValues, $pages);

        Storage::disk('local')->assertExists($outputPath);
    });

    it('skips empty field values for non-checkbox fields', function () {
        $service = new PdfFlatteningService;

        $fields = [
            [
                'id' => 'field_empty',
                'type' => 'text',
                'label' => 'Empty',
                'page' => 1,
                'x_pct' => 20.0,
                'y_pct' => 30.0,
                'width_pct' => 40.0,
                'height_pct' => 3.0,
                'font_size' => 12,
            ],
        ];

        $fieldValues = []; // No value for field_empty
        $pages = ['1' => ['width' => 612, 'height' => 792]];

        $outputPath = $service->flatten('contracts/templates/test.pdf', $fields, $fieldValues, $pages);

        Storage::disk('local')->assertExists($outputPath);
    });

    it('handles multiple fields on the same page', function () {
        $service = new PdfFlatteningService;

        $fields = [
            [
                'id' => 'field_name',
                'type' => 'text',
                'label' => 'Name',
                'page' => 1,
                'x_pct' => 20.0,
                'y_pct' => 25.0,
                'width_pct' => 40.0,
                'height_pct' => 3.0,
                'font_size' => 12,
            ],
            [
                'id' => 'field_title',
                'type' => 'text',
                'label' => 'Title',
                'page' => 1,
                'x_pct' => 20.0,
                'y_pct' => 35.0,
                'width_pct' => 40.0,
                'height_pct' => 3.0,
                'font_size' => 12,
            ],
            [
                'id' => 'field_date',
                'type' => 'date',
                'label' => 'Date',
                'page' => 1,
                'x_pct' => 20.0,
                'y_pct' => 45.0,
                'width_pct' => 30.0,
                'height_pct' => 3.0,
                'font_size' => 10,
            ],
        ];

        $fieldValues = [
            'field_name' => 'Jane Smith',
            'field_title' => 'Lead Painter',
            'field_date' => '01/15/2026',
        ];
        $pages = ['1' => ['width' => 612, 'height' => 792]];

        $outputPath = $service->flatten('contracts/templates/test.pdf', $fields, $fieldValues, $pages);

        Storage::disk('local')->assertExists($outputPath);

        $content = Storage::disk('local')->get($outputPath);
        expect(str_starts_with($content, '%PDF'))->toBeTrue();
    });

    it('uses default font size of 12 when not specified', function () {
        $service = new PdfFlatteningService;

        $fields = [
            [
                'id' => 'field_no_size',
                'type' => 'text',
                'label' => 'No Size',
                'page' => 1,
                'x_pct' => 20.0,
                'y_pct' => 30.0,
                'width_pct' => 40.0,
                'height_pct' => 3.0,
                // No font_size specified
            ],
        ];

        $fieldValues = ['field_no_size' => 'Default size text'];
        $pages = ['1' => ['width' => 612, 'height' => 792]];

        // Should not throw - uses default font_size of 12
        $outputPath = $service->flatten('contracts/templates/test.pdf', $fields, $fieldValues, $pages);

        Storage::disk('local')->assertExists($outputPath);
    });

    it('handles signature fields with base64 data URI', function () {
        $service = new PdfFlatteningService;

        // Create a minimal 1x1 transparent PNG for testing
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
                'height_pct' => 10.0,
                'font_size' => 12,
            ],
        ];

        $fieldValues = ['field_sig' => 'data:image/png;base64,'.$base64];
        $pages = ['1' => ['width' => 612, 'height' => 792]];

        $outputPath = $service->flatten('contracts/templates/test.pdf', $fields, $fieldValues, $pages);

        Storage::disk('local')->assertExists($outputPath);
    });

    it('handles invalid base64 signature data gracefully', function () {
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
                'height_pct' => 10.0,
                'font_size' => 12,
            ],
        ];

        $fieldValues = ['field_sig' => 'data:image/png;base64,!!!invalid!!!'];
        $pages = ['1' => ['width' => 612, 'height' => 792]];

        // Should not throw - gracefully skips invalid signature
        $outputPath = $service->flatten('contracts/templates/test.pdf', $fields, $fieldValues, $pages);

        Storage::disk('local')->assertExists($outputPath);
    });
});

// ──────────────────────────────────────────────────
// ContractService PDF Methods Tests
// ──────────────────────────────────────────────────

describe('ContractService PDF methods', function () {
    beforeEach(function () {
        Storage::fake('local');

        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('contracts/templates/test.pdf', $fixtureContent);
    });

    it('creates a PDF contract from template with resolved merge tags', function () {
        CompanySetting::getInstance()->update(['company_name' => 'Apex Painting']);

        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    [
                        'id' => 'field_name',
                        'type' => 'text',
                        'label' => 'Employee Name',
                        'merge_tag' => 'employee_name',
                        'page' => 1,
                        'x_pct' => 20.0,
                        'y_pct' => 25.0,
                        'width_pct' => 40.0,
                        'height_pct' => 3.0,
                        'required' => true,
                        'font_size' => 12,
                    ],
                    [
                        'id' => 'field_company',
                        'type' => 'text',
                        'label' => 'Company Name',
                        'merge_tag' => 'company_name',
                        'page' => 1,
                        'x_pct' => 20.0,
                        'y_pct' => 35.0,
                        'width_pct' => 40.0,
                        'height_pct' => 3.0,
                        'required' => true,
                        'font_size' => 12,
                    ],
                ],
            ],
        ]);

        $employee = EmployeeProfile::factory()->create([
            'job_title' => 'Painter',
        ]);

        $service = new ContractService;
        $contract = $service->createPdfContractFromTemplate($template, $employee);

        expect($contract)->toBeInstanceOf(EmployeeContract::class);
        expect($contract->status)->toBe('draft');
        expect($contract->pdf_path)->toBe('contracts/templates/test.pdf');
        expect($contract->contract_template_id)->toBe($template->id);
        expect($contract->employee_profile_id)->toBe($employee->id);
        expect($contract->field_values)->toBeArray();
        expect($contract->field_values['field_name'])->toBe($employee->user->name);
        expect($contract->field_values['field_company'])->toBe('Apex Painting');
    });

    it('resolves PDF merge tags for template fields', function () {
        CompanySetting::getInstance()->update(['company_name' => 'Test Co']);

        $template = ContractTemplate::factory()->pdf()->create([
            'fields' => [
                'pages' => ['1' => ['width' => 612, 'height' => 792]],
                'fields' => [
                    [
                        'id' => 'f1',
                        'type' => 'text',
                        'label' => 'Name',
                        'merge_tag' => 'employee_name',
                        'page' => 1,
                        'x_pct' => 10, 'y_pct' => 10,
                        'width_pct' => 30, 'height_pct' => 3,
                    ],
                    [
                        'id' => 'f2',
                        'type' => 'date',
                        'label' => 'Date',
                        'merge_tag' => 'date',
                        'page' => 1,
                        'x_pct' => 10, 'y_pct' => 20,
                        'width_pct' => 20, 'height_pct' => 3,
                    ],
                    [
                        'id' => 'f3',
                        'type' => 'signature',
                        'label' => 'Signature',
                        'merge_tag' => null,
                        'page' => 1,
                        'x_pct' => 10, 'y_pct' => 30,
                        'width_pct' => 40, 'height_pct' => 8,
                    ],
                ],
            ],
        ]);

        $employee = EmployeeProfile::factory()->create();

        $service = new ContractService;
        $values = $service->resolvePdfMergeTags($template, $employee);

        expect($values)->toHaveKey('f1');
        expect($values['f1'])->toBe($employee->user->name);
        expect($values)->toHaveKey('f2');
        expect($values['f2'])->toBe(now()->format('m/d/Y'));
        // f3 has no merge_tag, so it should not be in the values
        expect($values)->not->toHaveKey('f3');
    });

    it('returns empty array when template has no fields', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'fields' => null,
        ]);

        $employee = EmployeeProfile::factory()->create();

        $service = new ContractService;
        $values = $service->resolvePdfMergeTags($template, $employee);

        expect($values)->toBe([]);
    });

    it('signs a PDF contract and flattens the output', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->sent()->create([
            'employee_profile_id' => $employee->id,
            'contract_template_id' => $template->id,
            'pdf_path' => 'contracts/templates/test.pdf',
            'field_values' => [
                'field_name' => 'John Doe',
                'field_date' => '02/17/2026',
            ],
        ]);

        $service = new ContractService;
        $service->signPdfContract($contract, [
            'signature_data' => 'data:image/png;base64,'.base64_encode('fake-sig'),
            'signature_type' => 'drawn',
            'signer_name' => 'John Doe',
            'ip' => '127.0.0.1',
            'user_agent' => 'TestAgent/1.0',
        ], [
            'field_signature' => 'data:image/png;base64,'.base64_encode('fake-sig'),
        ]);

        $contract->refresh();

        expect($contract->status)->toBe('signed');
        expect($contract->signed_at)->not->toBeNull();
        expect($contract->signer_name)->toBe('John Doe');
        expect($contract->pdf_path)->toStartWith('signed-contracts/');
        Storage::disk('local')->assertExists($contract->pdf_path);
    });

    it('merges existing and new field values when signing', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
            'field_values' => ['field_name' => 'Pre-filled Name'],
        ]);

        $service = new ContractService;
        $service->signPdfContract($contract, [
            'signature_data' => 'sig-data',
            'signature_type' => 'typed',
            'signer_name' => 'Signer',
            'ip' => '10.0.0.1',
            'user_agent' => 'Test/1.0',
        ], [
            'field_date' => '01/01/2026',
        ]);

        $contract->refresh();

        // Both existing and new values should be present
        expect($contract->field_values)->toHaveKey('field_name');
        expect($contract->field_values['field_name'])->toBe('Pre-filled Name');
        expect($contract->field_values)->toHaveKey('field_date');
        expect($contract->field_values['field_date'])->toBe('01/01/2026');
    });
});

// ──────────────────────────────────────────────────
// PDF Serving Routes Tests
// ──────────────────────────────────────────────────

describe('PDF serving routes', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->user);
        Storage::fake('local');

        $fixtureContent = file_get_contents(base_path('tests/fixtures/test-contract.pdf'));
        Storage::disk('local')->put('contracts/templates/test.pdf', $fixtureContent);
    });

    it('serves template PDF preview for authorized users', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $this->get(route('contracts.templates.pdf-preview', $template))
            ->assertSuccessful()
            ->assertHeader('content-type', 'application/pdf');
    });

    it('returns 404 for template with no PDF path', function () {
        $template = ContractTemplate::factory()->create([
            'content_type' => 'rich_text',
            'pdf_path' => null,
        ]);

        $this->get(route('contracts.templates.pdf-preview', $template))
            ->assertNotFound();
    });

    it('denies template PDF preview without settings.contracts permission', function () {
        $this->actingAs(User::factory()->internal()->create());

        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $this->get(route('contracts.templates.pdf-preview', $template))
            ->assertForbidden();
    });

    it('serves template PDF for public signing via token', function () {
        $template = ContractTemplate::factory()->pdf()->create([
            'pdf_path' => 'contracts/templates/test.pdf',
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
        ]);

        $this->actingAs($contract->employeeProfile->user);

        $this->get(route('contracts.sign.template-pdf', $contract->token))
            ->assertSuccessful()
            ->assertHeader('content-type', 'application/pdf');
    });

    it('returns 404 for public template PDF when template is not PDF type', function () {
        $template = ContractTemplate::factory()->create([
            'content_type' => 'rich_text',
        ]);

        $contract = EmployeeContract::factory()->sent()->create([
            'contract_template_id' => $template->id,
        ]);

        $this->actingAs($contract->employeeProfile->user);

        $this->get(route('contracts.sign.template-pdf', $contract->token))
            ->assertNotFound();
    });
});
