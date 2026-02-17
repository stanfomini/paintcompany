<?php

use App\Models\ContractTemplate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

describe('Template editor page', function () {
    it('renders the template editor for authorized users', function () {
        $template = ContractTemplate::factory()->create();

        $this->get(route('contracts.templates.edit', $template))
            ->assertSuccessful()
            ->assertSee('Edit Template');
    });

    it('denies access to users without settings.contracts permission', function () {
        $this->actingAs(User::factory()->internal()->create());

        $template = ContractTemplate::factory()->create();

        $this->get(route('contracts.templates.edit', $template))
            ->assertForbidden();
    });

    it('renders trix-editor element for rich text templates', function () {
        $template = ContractTemplate::factory()->create();

        $this->get(route('contracts.templates.edit', $template))
            ->assertSuccessful()
            ->assertSee('trix-editor', escape: false);
    });

    it('loads Trix CSS and JS from CDN', function () {
        $template = ContractTemplate::factory()->create();

        $this->get(route('contracts.templates.edit', $template))
            ->assertSuccessful()
            ->assertSee('trix@2.1.12/dist/trix.css', escape: false)
            ->assertSee('trix@2.1.12/dist/trix.umd.min.js', escape: false);
    });
});

describe('Template editor content', function () {
    it('saves content with HTML formatting', function () {
        $template = ContractTemplate::factory()->create();
        $htmlContent = '<div>Welcome <strong>{employee_name}</strong> to <em>{company_name}</em>!</div>';

        Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->set('content', $htmlContent)
            ->call('save');

        expect($template->fresh()->content)->toBe($htmlContent);
    });

    it('preserves HTML in content when saving', function () {
        $template = ContractTemplate::factory()->create();
        $htmlContent = '<h1>Employment Agreement</h1><p>This contract is between <strong>{company_name}</strong> and <strong>{employee_name}</strong>.</p><ul><li>Position: {job_title}</li><li>Pay: {pay_rate}</li></ul>';

        Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->set('content', $htmlContent)
            ->call('save');

        $saved = $template->fresh()->content;
        expect($saved)->toContain('<h1>');
        expect($saved)->toContain('<strong>');
        expect($saved)->toContain('<ul>');
        expect($saved)->toContain('{employee_name}');
    });

    it('generates preview with merge tags replaced in HTML content', function () {
        $template = ContractTemplate::factory()->create([
            'content' => '<p>Welcome <strong>{employee_name}</strong> to {company_name}!</p>',
        ]);

        Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->set('content', '<p>Welcome <strong>{employee_name}</strong> to {company_name}!</p>')
            ->call('togglePreview')
            ->assertSee('John Smith', escape: false)
            ->assertSee('Apex Painting Co.', escape: false)
            ->assertSee('Preview');
    });

    it('does not show trix editor for PDF templates', function () {
        $template = ContractTemplate::factory()->pdf()->create();

        $this->get(route('contracts.templates.edit', $template))
            ->assertSuccessful()
            ->assertDontSee('trix-editor', escape: false)
            ->assertSee('PDF Template');
    });

    it('displays merge tags sidebar in editor mode', function () {
        $template = ContractTemplate::factory()->create();

        Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->assertSee('Merge Tags')
            ->assertSee('{employee_name}')
            ->assertSee('{company_name}')
            ->assertSee('{pay_rate}');
    });

    it('shows word count from content with HTML tags stripped', function () {
        $template = ContractTemplate::factory()->create([
            'content' => '<p>Hello world this is a test</p>',
        ]);

        Livewire::test('contracts.template-editor', ['contractTemplate' => $template])
            ->assertSee('6 words');
    });
});
