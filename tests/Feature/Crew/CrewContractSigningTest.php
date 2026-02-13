<?php

use App\Models\CrewSignedContract;
use App\Models\User;

it('can create a signed contract via factory', function () {
    $crew = User::factory()->crew()->create();
    $contract = CrewSignedContract::factory()
        ->for($crew)
        ->coloradoCompliance()
        ->create();

    expect($contract)->toBeInstanceOf(CrewSignedContract::class)
        ->and($contract->contract_type)->toBe(CrewSignedContract::TYPE_COLORADO_COMPLIANCE)
        ->and($contract->user_id)->toBe($crew->id);
});

it('creates a new record via sign method', function () {
    $crew = User::factory()->crew()->create();

    $contract = CrewSignedContract::sign(
        user: $crew,
        contractType: CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT,
        contractContent: 'This is the contractor agreement content.',
        signatureData: 'data:image/png;base64,abc123',
        signatureType: 'drawn',
    );

    expect($contract)->toBeInstanceOf(CrewSignedContract::class)
        ->and($contract->user_id)->toBe($crew->id)
        ->and($contract->contract_type)->toBe(CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT)
        ->and($contract->contract_content)->toBe('This is the contractor agreement content.')
        ->and($contract->signature_data)->toBe('data:image/png;base64,abc123')
        ->and($contract->signature_type)->toBe('drawn')
        ->and($contract->signer_name)->toBe($crew->name)
        ->and($contract->signer_email)->toBe($crew->email);

    $this->assertDatabaseHas('crew_signed_contracts', [
        'user_id' => $crew->id,
        'contract_type' => CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT,
    ]);
});

it('updates existing record on re-sign via sign method', function () {
    $crew = User::factory()->crew()->create();

    $original = CrewSignedContract::sign(
        user: $crew,
        contractType: CrewSignedContract::TYPE_COLORADO_COMPLIANCE,
        contractContent: 'Version 1 content.',
        signatureData: 'sig-v1',
        signatureType: 'typed',
    );

    $updated = CrewSignedContract::sign(
        user: $crew,
        contractType: CrewSignedContract::TYPE_COLORADO_COMPLIANCE,
        contractContent: 'Version 2 content.',
        signatureData: 'sig-v2',
        signatureType: 'drawn',
    );

    expect($updated->id)->toBe($original->id)
        ->and($updated->contract_content)->toBe('Version 2 content.')
        ->and($updated->signature_data)->toBe('sig-v2')
        ->and($updated->signature_type)->toBe('drawn');

    expect(CrewSignedContract::where('user_id', $crew->id)
        ->where('contract_type', CrewSignedContract::TYPE_COLORADO_COMPLIANCE)
        ->count())->toBe(1);
});

it('returns human-readable type labels via accessor', function () {
    $contract = CrewSignedContract::factory()
        ->coloradoCompliance()
        ->create();

    expect($contract->type_label)->toBe('Colorado Contractor Compliance');

    $contract2 = CrewSignedContract::factory()
        ->contractorAgreement()
        ->create();

    expect($contract2->type_label)->toBe('Contractor Agreement');

    $contract3 = CrewSignedContract::factory()
        ->backgroundCheck()
        ->create();

    expect($contract3->type_label)->toBe('Background Check Consent');
});

it('has correct labels for all three contract types', function () {
    expect(CrewSignedContract::TYPES)->toBe([
        'colorado_compliance' => 'Colorado Contractor Compliance',
        'contractor_agreement' => 'Contractor Agreement',
        'background_check_consent' => 'Background Check Consent',
    ]);
});

it('user has crewSignedContracts relationship', function () {
    $crew = User::factory()->crew()->create();

    CrewSignedContract::factory()->for($crew)->coloradoCompliance()->create();
    CrewSignedContract::factory()->for($crew)->contractorAgreement()->create();
    CrewSignedContract::factory()->for($crew)->backgroundCheck()->create();

    $crew->refresh();

    expect($crew->crewSignedContracts)->toHaveCount(3)
        ->and($crew->crewSignedContracts->pluck('contract_type')->sort()->values()->all())->toBe([
            'background_check_consent',
            'colorado_compliance',
            'contractor_agreement',
        ]);
});

it('captures ip address and user agent', function () {
    $crew = User::factory()->crew()->create();

    $contract = CrewSignedContract::sign(
        user: $crew,
        contractType: CrewSignedContract::TYPE_BACKGROUND_CHECK,
        contractContent: 'Background check consent content.',
        signatureData: 'sig-data',
        signatureType: 'typed',
    );

    expect($contract->ip_address)->not->toBeNull()
        ->and($contract->user_agent)->not->toBeNull();
});

it('captures signed_at timestamp', function () {
    $crew = User::factory()->crew()->create();

    $contract = CrewSignedContract::sign(
        user: $crew,
        contractType: CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT,
        contractContent: 'Agreement content.',
        signatureData: 'sig',
        signatureType: 'drawn',
    );

    expect($contract->signed_at)->not->toBeNull()
        ->and($contract->signed_at)->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($contract->signed_at->isToday())->toBeTrue();
});

it('factory states produce correct contract types and signature types', function () {
    $colorado = CrewSignedContract::factory()->coloradoCompliance()->create();
    expect($colorado->contract_type)->toBe(CrewSignedContract::TYPE_COLORADO_COMPLIANCE);

    $agreement = CrewSignedContract::factory()->contractorAgreement()->create();
    expect($agreement->contract_type)->toBe(CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT);

    $background = CrewSignedContract::factory()->backgroundCheck()->create();
    expect($background->contract_type)->toBe(CrewSignedContract::TYPE_BACKGROUND_CHECK);

    $typed = CrewSignedContract::factory()->typed()->create();
    expect($typed->signature_type)->toBe('typed')
        ->and($typed->signature_data)->not->toContain('data:image/png');

    $drawn = CrewSignedContract::factory()->drawn()->create();
    expect($drawn->signature_type)->toBe('drawn')
        ->and($drawn->signature_data)->toContain('data:image/png;base64,');
});

it('belongs to a user', function () {
    $crew = User::factory()->crew()->create();
    $contract = CrewSignedContract::factory()->for($crew)->create();

    expect($contract->user)->toBeInstanceOf(User::class)
        ->and($contract->user->id)->toBe($crew->id);
});
