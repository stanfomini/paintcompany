<?php

use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

describe('Crew Contract Templates', function () {

    it('can load crew contract templates from company settings', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'crew_colorado_compliance_contract' => 'Colorado compliance content',
            'crew_contractor_agreement' => 'Contractor agreement content',
            'crew_background_check_consent' => 'Background check content',
        ]);

        Livewire::test('pages::settings.contracts')
            ->assertSuccessful()
            ->assertSet('crew_colorado_compliance_contract', 'Colorado compliance content')
            ->assertSet('crew_contractor_agreement', 'Contractor agreement content')
            ->assertSet('crew_background_check_consent', 'Background check content');
    });

    it('can save Colorado compliance contract', function () {
        Livewire::test('pages::settings.contracts')
            ->set('crew_colorado_compliance_contract', 'Updated Colorado compliance contract text')
            ->call('saveCrewColorado')
            ->assertHasNoErrors();

        expect(CompanySetting::getInstance()->fresh()->crew_colorado_compliance_contract)
            ->toBe('Updated Colorado compliance contract text');
    });

    it('can save contractor agreement', function () {
        Livewire::test('pages::settings.contracts')
            ->set('crew_contractor_agreement', 'Updated contractor agreement text')
            ->call('saveCrewAgreement')
            ->assertHasNoErrors();

        expect(CompanySetting::getInstance()->fresh()->crew_contractor_agreement)
            ->toBe('Updated contractor agreement text');
    });

    it('can save background check consent', function () {
        Livewire::test('pages::settings.contracts')
            ->set('crew_background_check_consent', 'Updated background check consent text')
            ->call('saveCrewBackground')
            ->assertHasNoErrors();

        expect(CompanySetting::getInstance()->fresh()->crew_background_check_consent)
            ->toBe('Updated background check consent text');
    });

    it('saving one crew contract does not affect others', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'crew_colorado_compliance_contract' => 'Original Colorado',
            'crew_contractor_agreement' => 'Original Agreement',
            'crew_background_check_consent' => 'Original Background',
        ]);

        Livewire::test('pages::settings.contracts')
            ->set('crew_contractor_agreement', 'New Agreement text')
            ->call('saveCrewAgreement');

        $settings = CompanySetting::getInstance()->fresh();
        expect($settings->crew_colorado_compliance_contract)->toBe('Original Colorado');
        expect($settings->crew_contractor_agreement)->toBe('New Agreement text');
        expect($settings->crew_background_check_consent)->toBe('Original Background');
    });

    it('displays crew contract section headings on the page', function () {
        Livewire::test('pages::settings.contracts')
            ->assertSuccessful()
            ->assertSee('Colorado Contractor Compliance')
            ->assertSee('Contractor Agreement')
            ->assertSee('Background Check Consent');
    });

    it('displays crew contracts section heading', function () {
        Livewire::test('pages::settings.contracts')
            ->assertSuccessful()
            ->assertSee('Crew Contracts');
    });

});
