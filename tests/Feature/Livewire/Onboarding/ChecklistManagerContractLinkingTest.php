<?php

use App\Models\ContractTemplate;
use App\Models\OnboardingChecklist;
use App\Models\OnboardingChecklistItem;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo('onboarding.manage');
    $this->actingAs($this->user);
});

describe('Checklist Manager - Contract Template Linking', function () {
    it('shows contract template dropdown when item type is contract', function () {
        $template = ContractTemplate::factory()->create(['name' => 'Employment Agreement']);
        $checklist = OnboardingChecklist::factory()->create();

        Livewire::test('onboarding.checklist-manager')
            ->call('openCreateItem', $checklist->id)
            ->set('itemType', 'contract')
            ->assertSee('Contract Template')
            ->assertSee('Employment Agreement');
    });

    it('hides contract template dropdown for non-contract types', function () {
        ContractTemplate::factory()->create(['name' => 'Employment Agreement']);
        $checklist = OnboardingChecklist::factory()->create();

        Livewire::test('onboarding.checklist-manager')
            ->call('openCreateItem', $checklist->id)
            ->set('itemType', 'task')
            ->assertDontSee('Contract Template');
    });

    it('requires contract template selection when saving a contract item', function () {
        $checklist = OnboardingChecklist::factory()->create();

        Livewire::test('onboarding.checklist-manager')
            ->call('openCreateItem', $checklist->id)
            ->set('itemTitle', 'Sign NDA')
            ->set('itemType', 'contract')
            ->call('saveItem')
            ->assertHasErrors(['itemContractTemplateId' => 'required']);
    });

    it('saves contract template id in metadata when creating a contract item', function () {
        $template = ContractTemplate::factory()->create();
        $checklist = OnboardingChecklist::factory()->create();

        Livewire::test('onboarding.checklist-manager')
            ->call('openCreateItem', $checklist->id)
            ->set('itemTitle', 'Sign NDA')
            ->set('itemType', 'contract')
            ->set('itemContractTemplateId', $template->id)
            ->call('saveItem')
            ->assertHasNoErrors();

        $item = OnboardingChecklistItem::where('title', 'Sign NDA')->first();
        expect($item)->not->toBeNull();
        expect($item->type)->toBe('contract');
        expect($item->metadata['contract_template_id'])->toBe($template->id);
    });

    it('does not save contract template id for non-contract items', function () {
        $checklist = OnboardingChecklist::factory()->create();

        Livewire::test('onboarding.checklist-manager')
            ->call('openCreateItem', $checklist->id)
            ->set('itemTitle', 'Complete Safety Training')
            ->set('itemType', 'task')
            ->call('saveItem')
            ->assertHasNoErrors();

        $item = OnboardingChecklistItem::where('title', 'Complete Safety Training')->first();
        expect($item)->not->toBeNull();
        expect($item->metadata)->toBeNull();
    });

    it('loads contract template id when editing a contract item', function () {
        $template = ContractTemplate::factory()->create();
        $checklist = OnboardingChecklist::factory()->create();
        $item = OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $checklist->id,
            'metadata' => ['contract_template_id' => $template->id],
        ]);

        Livewire::test('onboarding.checklist-manager')
            ->call('openEditItem', $item->id)
            ->assertSet('itemContractTemplateId', $template->id)
            ->assertSet('itemType', 'contract');
    });

    it('shows template name badge on contract items in list', function () {
        $template = ContractTemplate::factory()->create(['name' => 'W-4 Template']);
        $checklist = OnboardingChecklist::factory()->create();
        OnboardingChecklistItem::factory()->contract()->create([
            'onboarding_checklist_id' => $checklist->id,
            'title' => 'Sign W-4',
            'metadata' => ['contract_template_id' => $template->id],
        ]);

        Livewire::test('onboarding.checklist-manager')
            ->set('expandedChecklists', [$checklist->id])
            ->assertSee('W-4 Template')
            ->assertSee('Sign W-4');
    });

    it('only shows active contract templates in dropdown', function () {
        ContractTemplate::factory()->create(['name' => 'Active Template', 'is_active' => true]);
        ContractTemplate::factory()->inactive()->create(['name' => 'Inactive Template']);
        $checklist = OnboardingChecklist::factory()->create();

        Livewire::test('onboarding.checklist-manager')
            ->call('openCreateItem', $checklist->id)
            ->set('itemType', 'contract')
            ->assertSee('Active Template')
            ->assertDontSee('Inactive Template');
    });

    it('resets contract template id when item form is reset', function () {
        $template = ContractTemplate::factory()->create();
        $checklist = OnboardingChecklist::factory()->create();

        Livewire::test('onboarding.checklist-manager')
            ->call('openCreateItem', $checklist->id)
            ->set('itemType', 'contract')
            ->set('itemContractTemplateId', $template->id)
            ->set('itemTitle', 'Sign Contract')
            ->call('saveItem')
            ->assertSet('itemContractTemplateId', null);
    });
});
