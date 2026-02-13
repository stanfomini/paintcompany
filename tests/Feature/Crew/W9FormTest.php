<?php

use App\Models\CrewW9Form;
use App\Models\User;

it('can create a W9 form via factory', function () {
    $crew = User::factory()->crew()->create();
    $w9 = CrewW9Form::factory()->for($crew)->create();

    expect($w9)->toBeInstanceOf(CrewW9Form::class)
        ->and($w9->user_id)->toBe($crew->id)
        ->and($w9->tax_classification)->toBe('individual')
        ->and($w9->tin_type)->toBe('ssn');
});

it('encrypts TIN in database', function () {
    $crew = User::factory()->crew()->create();
    $w9 = CrewW9Form::factory()->for($crew)->create([
        'tin' => '123-45-6789',
    ]);

    // The raw database value should not contain the plain TIN
    $this->assertDatabaseMissing('crew_w9_forms', [
        'id' => $w9->id,
        'tin' => '123-45-6789',
    ]);

    // But the model accessor should decrypt it
    $w9->refresh();
    expect($w9->tin)->toBe('123-45-6789');
});

it('masks SSN correctly via masked_tin accessor', function () {
    $crew = User::factory()->crew()->create();
    $w9 = CrewW9Form::factory()->for($crew)->create([
        'tin_type' => 'ssn',
        'tin' => '123-45-6789',
    ]);

    expect($w9->masked_tin)->toBe('***-**-6789');
});

it('masks EIN correctly via masked_tin accessor', function () {
    $crew = User::factory()->crew()->create();
    $w9 = CrewW9Form::factory()->for($crew)->withEin()->create([
        'tin' => '12-3456789',
    ]);

    expect($w9->masked_tin)->toBe('**-***6789');
});

it('returns empty string for masked_tin when TIN is empty', function () {
    $crew = User::factory()->crew()->create();
    $w9 = CrewW9Form::factory()->for($crew)->create();

    // Simulate clearing the TIN after creation
    $w9->tin = '';
    expect($w9->masked_tin)->toBe('');
});

it('returns human-readable tax classification labels', function () {
    $crew = User::factory()->crew()->create();
    $w9 = CrewW9Form::factory()->for($crew)->create([
        'tax_classification' => 'individual',
    ]);

    expect($w9->tax_classification_label)->toBe('Individual/sole proprietor or single-member LLC');
});

it('includes LLC sub-type in tax classification label', function () {
    $crew = User::factory()->crew()->create();
    $w9 = CrewW9Form::factory()->for($crew)->asLlc()->create([
        'llc_tax_classification' => 'C',
    ]);

    expect($w9->tax_classification_label)->toBe('Limited liability company (taxed as C)');
});

it('user has crewW9Form HasOne relationship', function () {
    $crew = User::factory()->crew()->create();
    CrewW9Form::factory()->for($crew)->create();

    $crew->refresh();

    expect($crew->crewW9Form)->toBeInstanceOf(CrewW9Form::class)
        ->and($crew->crewW9Form->user_id)->toBe($crew->id);
});

it('factory states produce correct configurations', function () {
    $ein = CrewW9Form::factory()->withEin()->create();
    expect($ein->tin_type)->toBe('ein');

    $llc = CrewW9Form::factory()->asLlc()->create();
    expect($llc->tax_classification)->toBe('llc')
        ->and($llc->llc_tax_classification)->not->toBeNull()
        ->and($llc->business_name)->toContain('LLC');

    $corp = CrewW9Form::factory()->asCorporation()->create();
    expect($corp->tax_classification)->toBe('c_corp')
        ->and($corp->tin_type)->toBe('ein')
        ->and($corp->business_name)->toContain('Inc.');
});

it('has labels for all tax classifications', function () {
    $classifications = CrewW9Form::TAX_CLASSIFICATIONS;

    expect($classifications)->toHaveCount(7)
        ->and($classifications)->toHaveKeys([
            'individual',
            'c_corp',
            's_corp',
            'partnership',
            'trust',
            'llc',
            'other',
        ]);

    // Verify every classification has a non-empty label
    foreach ($classifications as $key => $label) {
        expect($label)->toBeString()->not->toBeEmpty();
    }
});

it('belongs to a user', function () {
    $crew = User::factory()->crew()->create();
    $w9 = CrewW9Form::factory()->for($crew)->create();

    expect($w9->user)->toBeInstanceOf(User::class)
        ->and($w9->user->id)->toBe($crew->id);
});
