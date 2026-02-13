<?php

use App\Models\CompanySetting;
use App\Models\CrewSignedContract;
use App\Models\CrewW9Form;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->crew = User::factory()->crew()->create(['needs_onboarding' => true]);

    CompanySetting::getInstance()->update([
        'crew_colorado_compliance_contract' => 'Test Colorado contract content',
        'crew_contractor_agreement' => 'Test contractor agreement content',
        'crew_background_check_consent' => 'Test background check content',
        'crew_pitch_hourly_rate' => 25.00,
        'crew_pitch_job_sqft' => 2000,
        'crew_pitch_job_price' => 4000.00,
        'crew_pitch_paint_cost' => 400.00,
        'crew_pitch_bonus_percent' => 5.00,
    ]);
});

describe('Access Control', function () {
    it('allows crew with needs_onboarding=true to access the wizard', function () {
        $this->actingAs($this->crew, 'crew')
            ->get(route('crew.onboarding'))
            ->assertSuccessful()
            ->assertSeeLivewire('crew.onboarding');
    });

    it('redirects crew with needs_onboarding=false to dashboard', function () {
        $onboardedCrew = User::factory()->crew()->create(['needs_onboarding' => false]);

        Livewire::actingAs($onboardedCrew, 'crew')
            ->test('crew.onboarding')
            ->assertRedirect(route('crew.dashboard'));
    });

    it('does not allow non-crew users to access the crew onboarding route', function () {
        $internal = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($internal, 'crew')
            ->get(route('crew.onboarding'))
            ->assertRedirect();
    });

    it('does not allow agent users to access the crew onboarding route', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'crew')
            ->get(route('crew.onboarding'))
            ->assertRedirect();
    });

    it('redirects unauthenticated users to crew login', function () {
        $this->get(route('crew.onboarding'))
            ->assertRedirect(route('crew.login'));
    });
});

describe('Step 1: Pitch', function () {
    it('renders the pitch page on step 1', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->call('goToStep', 1)
            ->assertSet('currentStep', 1)
            ->assertSee('Welcome to the Team!');
    });

    it('auto-creates a sample estimate with 4 areas and fake customer data', function () {
        $component = Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->call('goToStep', 1)
            ->assertSee('Welcome to the Team!');

        $sample = $component->get('sampleEstimate');

        expect($sample)->not->toBeNull()
            ->and($sample->estimate_number)->toBe(\Database\Seeders\SampleEstimateSeeder::ESTIMATE_NUMBER)
            ->and($sample->title)->toBe('4-Room Interior Repaint')
            ->and($sample->status)->toBe('accepted')
            ->and($sample->project_type)->toBe('interior')
            ->and($sample->areas)->toHaveCount(4);

        // Verify fake customer data
        $customer = $sample->deal->customer;
        expect($customer->first_name)->toBe('Jane')
            ->and($customer->last_name)->toBe('Smith')
            ->and($customer->status)->toBe('archived');
    });

    it('is idempotent - calling ensureExists twice creates only one sample', function () {
        $first = \Database\Seeders\SampleEstimateSeeder::ensureExists();
        $second = \Database\Seeders\SampleEstimateSeeder::ensureExists();

        expect($first->id)->toBe($second->id);

        $count = \App\Models\Estimate::where('estimate_number', \Database\Seeders\SampleEstimateSeeder::ESTIMATE_NUMBER)->count();
        expect($count)->toBe(1);
    });

    it('creates surfaces with realistic labor hours totaling around 25 hours', function () {
        $sample = \Database\Seeders\SampleEstimateSeeder::ensureExists();

        $totalHours = $sample->areas->flatMap->surfaces->sum('labor_hours');

        // Expect roughly 20-30 hours for a 4-room interior job
        expect((float) $totalHours)->toBeGreaterThan(20.0)
            ->and((float) $totalHours)->toBeLessThan(30.0);
    });

    it('pre-populates financials with painters, paint costs, and crew bonus', function () {
        $sample = \Database\Seeders\SampleEstimateSeeder::ensureExists();

        // Verify actual_painters pre-populated with 2 painters at $35/hr
        expect($sample->actual_painters)->toHaveCount(2);
        expect($sample->actual_painters[0]['name'])->toBe('Mike Rodriguez');
        expect((float) $sample->actual_painters[0]['hourly_rate'])->toBe(35.0);
        expect($sample->actual_painters[1]['name'])->toBe('Carlos Mendez');
        expect((float) $sample->actual_painters[1]['hourly_rate'])->toBe(35.0);

        // Verify actual paint costs (crew discount)
        expect($sample->actual_paint_costs)->toHaveKey('sherwin_williams_duration');
        expect((float) $sample->actual_paint_costs['sherwin_williams_duration'])->toBe(45.0);

        // Verify job exists with accepted crew
        expect($sample->job)->not->toBeNull();
        $acceptedCrew = $sample->job->acceptedCrew()->with('crewProfile')->first();
        expect($acceptedCrew)->not->toBeNull();
        expect($acceptedCrew->name)->toBe('Mike Rodriguez');
        expect((float) $acceptedCrew->crewProfile->bonus_percent)->toBe(2.00);
    });

    it('advances to step 2 when completeStep1 is called', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->call('goToStep', 1)
            ->assertSet('currentStep', 1)
            ->call('completeStep1')
            ->assertSet('currentStep', 2);
    });
});

describe('Step 2: Call', function () {
    it('shows onboarding call step', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 2)
            ->assertSee('Onboarding Call');
    });

    it('marks had_onboarding_call when confirmCall is called', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 2)
            ->call('confirmCall')
            ->assertSet('currentStep', 3)
            ->assertSet('callSelfConfirmed', true);

        $this->crew->refresh();
        expect($this->crew->crewProfile->had_onboarding_call)->toBeTrue();
    });

    it('advances to step 3 without marking call when skipCall is called', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 2)
            ->call('skipCall')
            ->assertSet('currentStep', 3);

        $this->crew->refresh();
        expect($this->crew->crewProfile->had_onboarding_call)->toBeFalse();
    });
});

describe('Step 3: Contracts', function () {
    it('shows contract signing step', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 3)
            ->assertSee('Review', escape: false)
            ->assertSee('Sign Contracts', escape: false)
            ->assertSee('0 of 3 signed');
    });

    it('signs a contract when signature data is provided', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 3)
            ->set('contractSignatureData', 'John Doe')
            ->call('signContract', CrewSignedContract::TYPE_COLORADO_COMPLIANCE)
            ->assertHasNoErrors();

        expect(CrewSignedContract::where('user_id', $this->crew->id)->count())->toBe(1);

        $contract = CrewSignedContract::where('user_id', $this->crew->id)->first();
        expect($contract->contract_type)->toBe('colorado_compliance')
            ->and($contract->signer_name)->toBe($this->crew->name)
            ->and($contract->signer_email)->toBe($this->crew->email)
            ->and($contract->contract_content)->toBe('Test Colorado contract content');
    });

    it('requires signature data to sign a contract', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 3)
            ->set('contractSignatureData', '')
            ->call('signContract', CrewSignedContract::TYPE_COLORADO_COMPLIANCE)
            ->assertHasErrors(['contractSignatureData' => 'required']);

        expect(CrewSignedContract::where('user_id', $this->crew->id)->count())->toBe(0);
    });

    it('clears signature data after signing a contract', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 3)
            ->set('contractSignatureData', 'John Doe')
            ->call('signContract', CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT)
            ->assertSet('contractSignatureData', '');
    });

    it('auto-advances to step 4 after signing all 3 contracts', function () {
        // Sign contracts 1 and 2 directly
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_COLORADO_COMPLIANCE, 'content', 'sig', 'typed');
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT, 'content', 'sig', 'typed');

        // Sign the 3rd via the component
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 3)
            ->set('contractSignatureData', 'Jane Doe')
            ->call('signContract', CrewSignedContract::TYPE_BACKGROUND_CHECK)
            ->assertSet('currentStep', 4);
    });

    it('does not advance to step 4 with fewer than 3 contracts signed', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 3)
            ->set('contractSignatureData', 'John Doe')
            ->call('signContract', CrewSignedContract::TYPE_COLORADO_COMPLIANCE)
            ->assertSet('currentStep', 3);
    });

    it('ignores invalid contract types', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 3)
            ->set('contractSignatureData', 'John Doe')
            ->call('signContract', 'invalid_type');

        expect(CrewSignedContract::where('user_id', $this->crew->id)->count())->toBe(0);
    });
});

describe('Step 4: Documents', function () {
    it('shows compliance documents step', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->assertSee('Compliance Documents');
    });

    it('uploads insurance file', function () {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('insurance.pdf', 100, 'application/pdf');

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('insuranceFile', $file)
            ->set('insuranceCompany', 'Test Insurance Co')
            ->set('insurancePolicyNumber', 'POL-123')
            ->set('insuranceStartDate', '2026-01-01')
            ->set('insuranceExpirationDate', '2027-01-01')
            ->call('uploadInsurance')
            ->assertHasNoErrors();

        $this->crew->refresh();
        expect($this->crew->crewProfile->insurance_photo)->not->toBeNull();
    });

    it('validates insurance file type', function () {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('insurance.txt', 100, 'text/plain');

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('insuranceFile', $file)
            ->call('uploadInsurance')
            ->assertHasErrors(['insuranceFile']);
    });

    it('uploads workers comp file', function () {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('workers-comp.pdf', 100, 'application/pdf');

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('workersCompFile', $file)
            ->set('workersCompCarrier', 'Test WC Co')
            ->set('workersCompPolicyNumber', 'WC-456')
            ->set('workersCompStartDate', '2026-01-01')
            ->set('workersCompExpirationDate', '2027-01-01')
            ->call('uploadWorkersComp')
            ->assertHasNoErrors();

        $this->crew->refresh();
        expect($this->crew->crewProfile->workers_comp_document)->not->toBeNull();
    });

    it('uploads drivers license front', function () {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('license-front.jpg', 400, 300);

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('driversLicenseFront', $file)
            ->call('uploadDriversLicense')
            ->assertHasNoErrors();

        $this->crew->refresh();
        expect($this->crew->crewProfile->drivers_license_front)->not->toBeNull();
    });

    it('saves W9 form with valid data', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('w9Name', 'John Doe')
            ->set('w9BusinessName', 'Doe Painting LLC')
            ->set('w9TaxClassification', 'individual')
            ->set('w9Address', '123 Main St')
            ->set('w9CityStateZip', 'Denver, CO 80202')
            ->set('w9TinType', 'ssn')
            ->set('w9Tin', '123-45-6789')
            ->set('w9SignatureData', 'John Doe')
            ->set('w9Certified', true)
            ->call('saveW9')
            ->assertHasNoErrors();

        $w9 = CrewW9Form::where('user_id', $this->crew->id)->first();
        expect($w9)->not->toBeNull()
            ->and($w9->name)->toBe('John Doe')
            ->and($w9->business_name)->toBe('Doe Painting LLC')
            ->and($w9->tax_classification)->toBe('individual')
            ->and($w9->address)->toBe('123 Main St')
            ->and($w9->city_state_zip)->toBe('Denver, CO 80202')
            ->and($w9->tin_type)->toBe('ssn');
    });

    it('validates required W9 fields', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('w9Name', '')
            ->set('w9Address', '')
            ->set('w9CityStateZip', '')
            ->set('w9Tin', '')
            ->set('w9SignatureData', '')
            ->set('w9Certified', false)
            ->call('saveW9')
            ->assertHasErrors([
                'w9Name',
                'w9Address',
                'w9CityStateZip',
                'w9Tin',
                'w9SignatureData',
                'w9Certified',
            ]);
    });

    it('validates W9 tax classification must be a valid value', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('w9Name', 'John Doe')
            ->set('w9TaxClassification', 'invalid')
            ->set('w9Address', '123 Main St')
            ->set('w9CityStateZip', 'Denver, CO 80202')
            ->set('w9TinType', 'ssn')
            ->set('w9Tin', '123-45-6789')
            ->set('w9SignatureData', 'John Doe')
            ->set('w9Certified', true)
            ->call('saveW9')
            ->assertHasErrors(['w9TaxClassification']);
    });

    it('validates W9 TIN minimum length', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('w9Name', 'John Doe')
            ->set('w9TaxClassification', 'individual')
            ->set('w9Address', '123 Main St')
            ->set('w9CityStateZip', 'Denver, CO 80202')
            ->set('w9TinType', 'ssn')
            ->set('w9Tin', '123')
            ->set('w9SignatureData', 'John Doe')
            ->set('w9Certified', true)
            ->call('saveW9')
            ->assertHasErrors(['w9Tin']);
    });

    it('blocks step 5 when documents are missing', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->call('advanceToComplete')
            ->assertSet('currentStep', 4)
            ->assertHasErrors(['documents']);
    });

    it('advances to step 5 when all documents are uploaded', function () {
        // Set up all required documents
        $this->crew->crewProfile->update([
            'insurance_photo' => 'crew-documents/insurance/test.pdf',
            'workers_comp_document' => 'crew-documents/workers-comp/test.pdf',
            'drivers_license_front' => 'crew-documents/license/front.jpg',
        ]);
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_COLORADO_COMPLIANCE, 'c', 's', 'typed');
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT, 'c', 's', 'typed');
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_BACKGROUND_CHECK, 'c', 's', 'typed');
        CrewW9Form::factory()->create(['user_id' => $this->crew->id]);

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->call('advanceToComplete')
            ->assertSet('currentStep', 5)
            ->assertHasNoErrors();
    });

    it('blocks completeOnboarding when documents are missing', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 5)
            ->call('completeOnboarding')
            ->assertSet('currentStep', 4)
            ->assertHasErrors(['documents']);

        $this->crew->refresh();
        expect($this->crew->needs_onboarding)->toBeTrue();
    });

    it('routes goToStep(5) through validation', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->call('goToStep', 5)
            ->assertSet('currentStep', 4)
            ->assertHasErrors(['documents']);
    });

    it('sets w9_received_date when saveW9 is called', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('w9Name', 'John Doe')
            ->set('w9TaxClassification', 'individual')
            ->set('w9Address', '123 Main St')
            ->set('w9CityStateZip', 'Denver, CO 80202')
            ->set('w9TinType', 'ssn')
            ->set('w9Tin', '123-45-6789')
            ->set('w9SignatureData', 'John Doe')
            ->set('w9Certified', true)
            ->call('saveW9')
            ->assertHasNoErrors();

        $this->crew->refresh();
        expect($this->crew->crewProfile->w9_received_date)->not->toBeNull();
    });

    it('requires insurance metadata when uploading insurance', function () {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('insurance.pdf', 100, 'application/pdf');

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('insuranceFile', $file)
            ->set('insuranceCompany', '')
            ->set('insurancePolicyNumber', '')
            ->call('uploadInsurance')
            ->assertHasErrors(['insuranceCompany', 'insurancePolicyNumber', 'insuranceStartDate', 'insuranceExpirationDate']);
    });

    it('saves insurance metadata alongside file upload', function () {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('insurance.pdf', 100, 'application/pdf');

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('insuranceFile', $file)
            ->set('insuranceCompany', 'State Farm')
            ->set('insurancePolicyNumber', 'POL-123')
            ->set('insuranceStartDate', '2026-01-01')
            ->set('insuranceExpirationDate', '2027-01-01')
            ->call('uploadInsurance')
            ->assertHasNoErrors();

        $this->crew->refresh();
        expect($this->crew->crewProfile->insurance_company)->toBe('State Farm')
            ->and($this->crew->crewProfile->policy_number)->toBe('POL-123')
            ->and($this->crew->crewProfile->insurance_start_date->format('Y-m-d'))->toBe('2026-01-01')
            ->and($this->crew->crewProfile->insurance_expiration_date->format('Y-m-d'))->toBe('2027-01-01');
    });

    it('requires workers comp metadata when uploading workers comp', function () {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('wc.pdf', 100, 'application/pdf');

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('workersCompFile', $file)
            ->set('workersCompCarrier', '')
            ->set('workersCompPolicyNumber', '')
            ->call('uploadWorkersComp')
            ->assertHasErrors(['workersCompCarrier', 'workersCompPolicyNumber', 'workersCompStartDate', 'workersCompExpirationDate']);
    });

    it('saves workers comp metadata alongside file upload', function () {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('wc.pdf', 100, 'application/pdf');

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 4)
            ->set('workersCompFile', $file)
            ->set('workersCompCarrier', 'Pinnacol Assurance')
            ->set('workersCompPolicyNumber', 'WC-789')
            ->set('workersCompStartDate', '2026-01-01')
            ->set('workersCompExpirationDate', '2027-01-01')
            ->call('uploadWorkersComp')
            ->assertHasNoErrors();

        $this->crew->refresh();
        expect($this->crew->crewProfile->workers_comp_carrier)->toBe('Pinnacol Assurance')
            ->and($this->crew->crewProfile->workers_comp_policy_number)->toBe('WC-789')
            ->and($this->crew->crewProfile->workers_comp_start_date->format('Y-m-d'))->toBe('2026-01-01')
            ->and($this->crew->crewProfile->workers_comp_expiration_date->format('Y-m-d'))->toBe('2027-01-01');
    });
});

describe('Step 5: Complete', function () {
    beforeEach(function () {
        // Give crew all required documents so they can reach step 5
        CrewSignedContract::factory()->for($this->crew, 'user')->coloradoCompliance()->create();
        CrewSignedContract::factory()->for($this->crew, 'user')->contractorAgreement()->create();
        CrewSignedContract::factory()->for($this->crew, 'user')->backgroundCheck()->create();
        CrewW9Form::factory()->for($this->crew, 'user')->create();
        $this->crew->crewProfile->update([
            'insurance_photo' => 'crew-documents/insurance/test.pdf',
            'workers_comp_document' => 'crew-documents/workers-comp/test.pdf',
            'drivers_license_front' => 'crew-documents/license/front.jpg',
        ]);
    });

    it('shows completion step when all docs are complete', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->call('advanceToComplete')
            ->assertSet('currentStep', 5)
            ->assertSee('All Set');
    });

    it('completes onboarding and redirects to dashboard', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 5)
            ->call('completeOnboarding')
            ->assertRedirect(route('crew.dashboard'));

        $this->crew->refresh();
        expect($this->crew->needs_onboarding)->toBeFalse()
            ->and($this->crew->crewProfile->onboarding_completed_at)->not->toBeNull();
    });

    it('flashes a success message on completion', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->set('currentStep', 5)
            ->call('completeOnboarding')
            ->assertRedirect(route('crew.dashboard'));

        expect(session('success'))->toBe('Welcome aboard! Your onboarding is complete.');
    });
});

describe('Step Navigation', function () {
    it('navigates to a valid step via goToStep', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->call('goToStep', 3)
            ->assertSet('currentStep', 3)
            ->call('goToStep', 1)
            ->assertSet('currentStep', 1)
            ->call('goToStep', 4)
            ->assertSet('currentStep', 4);
    });

    it('ignores invalid step numbers in goToStep', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->call('goToStep', 3)
            ->assertSet('currentStep', 3)
            ->call('goToStep', 0)
            ->assertSet('currentStep', 3)
            ->call('goToStep', 6)
            ->assertSet('currentStep', 3);
    });
});

describe('Mount Auto-Detection', function () {
    it('auto-detects step 2 when call is not completed', function () {
        // Default crew has had_onboarding_call = false, so starts at step 2
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->assertSet('currentStep', 2);
    });

    it('auto-detects step 3 when call is done but contracts are not signed', function () {
        $this->crew->crewProfile->update(['had_onboarding_call' => true]);

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->assertSet('currentStep', 3);
    });

    it('auto-detects step 4 when contracts are signed but documents missing', function () {
        $this->crew->crewProfile->update(['had_onboarding_call' => true]);
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_COLORADO_COMPLIANCE, 'c', 's', 'typed');
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT, 'c', 's', 'typed');
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_BACKGROUND_CHECK, 'c', 's', 'typed');

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->assertSet('currentStep', 4);
    });

    it('auto-detects step 5 when all documents are uploaded', function () {
        $this->crew->crewProfile->update([
            'had_onboarding_call' => true,
            'insurance_photo' => 'crew-documents/insurance/test.pdf',
            'workers_comp_document' => 'crew-documents/workers-comp/test.pdf',
            'drivers_license_front' => 'crew-documents/license/front.jpg',
        ]);
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_COLORADO_COMPLIANCE, 'c', 's', 'typed');
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT, 'c', 's', 'typed');
        CrewSignedContract::sign($this->crew, CrewSignedContract::TYPE_BACKGROUND_CHECK, 'c', 's', 'typed');
        CrewW9Form::factory()->create(['user_id' => $this->crew->id]);

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->assertSet('currentStep', 5);
    });

    it('pre-fills W9 name from user name when no W9 exists', function () {
        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->assertSet('w9Name', $this->crew->name);
    });

    it('pre-fills W9 from existing W9 form data', function () {
        CrewW9Form::factory()->create([
            'user_id' => $this->crew->id,
            'name' => 'Existing W9 Name',
            'address' => '456 Elm St',
            'city_state_zip' => 'Boulder, CO 80301',
            'tax_classification' => 'llc',
            'tin_type' => 'ein',
        ]);

        Livewire::actingAs($this->crew, 'crew')
            ->test('crew.onboarding')
            ->assertSet('w9Name', 'Existing W9 Name')
            ->assertSet('w9Address', '456 Elm St')
            ->assertSet('w9CityStateZip', 'Boulder, CO 80301')
            ->assertSet('w9TaxClassification', 'llc')
            ->assertSet('w9TinType', 'ein');
    });
});
