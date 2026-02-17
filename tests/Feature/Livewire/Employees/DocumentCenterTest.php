<?php

use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Notifications\ContractSentForSigning;
use App\Notifications\DocumentRejected;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

describe('Document Center', function () {
    beforeEach(function () {
        $this->admin = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($this->admin);
    });

    it('renders the document center page', function () {
        $this->get(route('document-center'))
            ->assertSuccessful();
    });

    it('shows pending reviews tab with documents', function () {
        $employee = EmployeeProfile::factory()->create();
        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $employee->id,
            'name' => 'Pending W-9',
            'status' => 'pending',
        ]);

        Livewire::test('employees.document-center')
            ->assertSee('Pending W-9')
            ->assertSee($employee->user->name);
    });

    it('shows empty state when no pending reviews', function () {
        Livewire::test('employees.document-center')
            ->assertSee('No Pending Reviews');
    });

    it('can switch to pending signatures tab', function () {
        $template = ContractTemplate::factory()->create();
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->create([
            'employee_profile_id' => $employee->id,
            'contract_template_id' => $template->id,
            'status' => 'sent',
            'title' => 'Employment Agreement',
        ]);

        Livewire::test('employees.document-center')
            ->call('setTab', 'pending-signatures')
            ->assertSee('Employment Agreement')
            ->assertSee($employee->user->name);
    });

    it('shows empty state for pending signatures tab', function () {
        Livewire::test('employees.document-center')
            ->call('setTab', 'pending-signatures')
            ->assertSee('No Pending Signatures');
    });

    it('can switch to recently signed tab', function () {
        $template = ContractTemplate::factory()->create();
        $employee = EmployeeProfile::factory()->create();
        EmployeeContract::factory()->create([
            'employee_profile_id' => $employee->id,
            'contract_template_id' => $template->id,
            'status' => 'signed',
            'signed_at' => now(),
            'title' => 'Signed Contract',
        ]);

        Livewire::test('employees.document-center')
            ->call('setTab', 'recently-signed')
            ->assertSee('Signed Contract');
    });

    it('can switch to expiring soon tab', function () {
        $employee = EmployeeProfile::factory()->create();
        EmployeeDocument::factory()->create([
            'employee_profile_id' => $employee->id,
            'name' => 'Expiring License',
            'status' => 'approved',
            'expires_at' => now()->addDays(15),
        ]);

        Livewire::test('employees.document-center')
            ->call('setTab', 'expiring')
            ->assertSee('Expiring License');
    });

    it('shows empty state for expiring tab', function () {
        Livewire::test('employees.document-center')
            ->call('setTab', 'expiring')
            ->assertSee('No Expiring Documents');
    });

    it('can approve a document from document center', function () {
        $employee = EmployeeProfile::factory()->create();
        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $employee->id,
            'status' => 'pending',
        ]);

        Livewire::test('employees.document-center')
            ->call('approveDocument', $doc->id);

        $doc->refresh();
        expect($doc->status)->toBe('approved');
        expect($doc->reviewed_by)->toBe($this->admin->id);
    });

    it('can reject a document from document center', function () {
        Notification::fake();

        $employee = EmployeeProfile::factory()->create();
        $doc = EmployeeDocument::factory()->create([
            'employee_profile_id' => $employee->id,
            'status' => 'pending',
        ]);

        Livewire::test('employees.document-center')
            ->call('openRejectModal', $doc->id)
            ->set('rejectNotes', 'Wrong format')
            ->call('rejectDocument')
            ->assertSet('showRejectModal', false);

        $doc->refresh();
        expect($doc->status)->toBe('rejected');
        expect($doc->review_notes)->toBe('Wrong format');

        Notification::assertSentTo($employee->user, DocumentRejected::class);
    });

    it('can resend contract signature reminder', function () {
        Notification::fake();

        $template = ContractTemplate::factory()->create();
        $employee = EmployeeProfile::factory()->create();
        $contract = EmployeeContract::factory()->create([
            'employee_profile_id' => $employee->id,
            'contract_template_id' => $template->id,
            'status' => 'sent',
        ]);

        Livewire::test('employees.document-center')
            ->call('setTab', 'pending-signatures')
            ->call('resendContractNotification', $contract->id);

        Notification::assertSentTo($employee->user, ContractSentForSigning::class);
    });

    it('denies access without employees.view permission', function () {
        $user = User::factory()->internal()->create();
        $this->actingAs($user);

        Livewire::test('employees.document-center')
            ->assertForbidden();
    });

    it('denies approve without employees.edit permission', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->givePermissionTo('employees.view');
        $this->actingAs($viewer);

        $doc = EmployeeDocument::factory()->create(['status' => 'pending']);

        Livewire::test('employees.document-center')
            ->call('approveDocument', $doc->id)
            ->assertForbidden();
    });

    it('persists active tab in URL', function () {
        Livewire::withQueryParams(['tab' => 'expiring'])
            ->test('employees.document-center')
            ->assertSet('activeTab', 'expiring');
    });
});
