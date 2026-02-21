<?php

use App\Automation\Actions\CreateLeadSourceCommission;
use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\LeadSource;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkflowRun;

it('creates flat commission for vendor lead source', function () {
    $vendor = Vendor::factory()->create(['email' => 'vendor@example.com']);
    $user = User::factory()->internal()->create(['email' => 'vendor@example.com']);
    $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $leadSource = LeadSource::factory()->create(['vendor_id' => $vendor->id]);

    $deal = Deal::factory()->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'context' => ['lead_source_id' => $leadSource->id],
    ]);

    $action = new CreateLeadSourceCommission;
    $result = $action->execute($run, [
        'commission_type' => 'flat',
        'amount' => 150,
    ]);

    expect($result['commission_created'])->toBeTrue()
        ->and($result['amount'])->toBe(150.0)
        ->and($result['vendor_name'])->toBe($vendor->name)
        ->and($result['commission_entry_id'])->toBeInt();

    $entry = CommissionEntry::find($result['commission_entry_id']);
    expect($entry->employee_profile_id)->toBe($profile->id)
        ->and($entry->commission_type)->toBe('flat')
        ->and((float) $entry->amount)->toBe(150.0)
        ->and($entry->status)->toBe('pending')
        ->and($entry->source_label)->toContain('Lead Source Commission')
        ->and($entry->commissionable_type)->toBe(Vendor::class)
        ->and($entry->commissionable_id)->toBe($vendor->id);
});

it('creates revenue percentage commission', function () {
    $vendor = Vendor::factory()->create(['email' => 'rev@example.com']);
    $user = User::factory()->internal()->create(['email' => 'rev@example.com']);
    EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $leadSource = LeadSource::factory()->create(['vendor_id' => $vendor->id]);

    $deal = Deal::factory()->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'context' => [
            'lead_source_id' => $leadSource->id,
            'estimate_total' => 10000,
        ],
    ]);

    $action = new CreateLeadSourceCommission;
    $result = $action->execute($run, [
        'commission_type' => 'revenue_percentage',
        'rate' => 10,
    ]);

    expect($result['commission_created'])->toBeTrue()
        ->and($result['amount'])->toBe(1000.0);

    $entry = CommissionEntry::find($result['commission_entry_id']);
    expect($entry->commission_type)->toBe('revenue_percentage')
        ->and((float) $entry->basis_amount)->toBe(10000.0)
        ->and((float) $entry->rate)->toBe(0.1);
});

it('creates profit percentage commission', function () {
    $vendor = Vendor::factory()->create(['email' => 'profit@example.com']);
    $user = User::factory()->internal()->create(['email' => 'profit@example.com']);
    EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $leadSource = LeadSource::factory()->create(['vendor_id' => $vendor->id]);

    $deal = Deal::factory()->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'context' => [
            'lead_source_id' => $leadSource->id,
            'estimate_profit' => 5000,
        ],
    ]);

    $action = new CreateLeadSourceCommission;
    $result = $action->execute($run, [
        'commission_type' => 'profit_percentage',
        'rate' => 20,
    ]);

    expect($result['commission_created'])->toBeTrue()
        ->and($result['amount'])->toBe(1000.0);

    $entry = CommissionEntry::find($result['commission_entry_id']);
    expect($entry->commission_type)->toBe('profit_percentage')
        ->and((float) $entry->basis_amount)->toBe(5000.0)
        ->and((float) $entry->rate)->toBe(0.2);
});

it('returns false when no vendor linked', function () {
    $leadSource = LeadSource::factory()->create(['vendor_id' => null]);

    $run = WorkflowRun::factory()->create([
        'context' => ['lead_source_id' => $leadSource->id],
    ]);

    $action = new CreateLeadSourceCommission;
    $result = $action->execute($run, [
        'commission_type' => 'flat',
        'amount' => 100,
    ]);

    expect($result['commission_created'])->toBeFalse()
        ->and($result['reason'])->toBe('No vendor linked to lead source');
});

it('creates commission even without employee profile for vendor', function () {
    $vendor = Vendor::factory()->create(['email' => 'noprofile@example.com']);
    $leadSource = LeadSource::factory()->create(['vendor_id' => $vendor->id]);

    $deal = Deal::factory()->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'context' => ['lead_source_id' => $leadSource->id],
    ]);

    $action = new CreateLeadSourceCommission;
    $result = $action->execute($run, [
        'commission_type' => 'flat',
        'amount' => 100,
    ]);

    expect($result['commission_created'])->toBeTrue()
        ->and($result['amount'])->toBe(100.0)
        ->and($result['vendor_name'])->toBe($vendor->name);

    $entry = CommissionEntry::find($result['commission_entry_id']);
    expect($entry->commissionable_type)->toBe(Vendor::class)
        ->and($entry->commissionable_id)->toBe($vendor->id)
        ->and($entry->employee_profile_id)->toBeNull();
});

it('returns false when commission amount is zero', function () {
    $vendor = Vendor::factory()->create(['email' => 'zero@example.com']);
    $user = User::factory()->internal()->create(['email' => 'zero@example.com']);
    EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $leadSource = LeadSource::factory()->create(['vendor_id' => $vendor->id]);

    $run = WorkflowRun::factory()->create([
        'context' => [
            'lead_source_id' => $leadSource->id,
            'estimate_total' => 0,
        ],
    ]);

    $action = new CreateLeadSourceCommission;
    $result = $action->execute($run, [
        'commission_type' => 'revenue_percentage',
        'rate' => 10,
    ]);

    expect($result['commission_created'])->toBeFalse()
        ->and($result['reason'])->toBe('Commission amount is zero');

    expect(CommissionEntry::count())->toBe(0);
});

it('stores commission entry id in workflow context', function () {
    $vendor = Vendor::factory()->create(['email' => 'ctx@example.com']);
    $user = User::factory()->internal()->create(['email' => 'ctx@example.com']);
    EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $leadSource = LeadSource::factory()->create(['vendor_id' => $vendor->id]);

    $deal = Deal::factory()->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'context' => ['lead_source_id' => $leadSource->id],
    ]);

    $action = new CreateLeadSourceCommission;
    $result = $action->execute($run, [
        'commission_type' => 'flat',
        'amount' => 250,
    ]);

    $run->refresh();
    expect($run->context['commission_entry_id'])->toBe($result['commission_entry_id'])
        ->and((float) $run->context['commission_amount'])->toBe(250.0);
});

it('resolves lead source by slug from config', function () {
    $vendor = Vendor::factory()->create(['email' => 'slug@example.com']);
    $user = User::factory()->internal()->create(['email' => 'slug@example.com']);
    EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $leadSource = LeadSource::factory()->create([
        'vendor_id' => $vendor->id,
        'slug' => 'facebook_ads',
    ]);

    $deal = Deal::factory()->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'context' => [],
    ]);

    $action = new CreateLeadSourceCommission;
    $result = $action->execute($run, [
        'commission_type' => 'flat',
        'amount' => 75,
        'lead_source_slug' => 'facebook_ads',
    ]);

    expect($result['commission_created'])->toBeTrue()
        ->and($result['amount'])->toBe(75.0);
});

it('returns false when no lead source found', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [],
    ]);

    $action = new CreateLeadSourceCommission;
    $result = $action->execute($run, [
        'commission_type' => 'flat',
        'amount' => 100,
    ]);

    expect($result['commission_created'])->toBeFalse()
        ->and($result['reason'])->toBe('No vendor linked to lead source');
});

it('has correct metadata for CreateLeadSourceCommission', function () {
    expect(CreateLeadSourceCommission::getName())->toBe('Create Lead Source Commission')
        ->and(CreateLeadSourceCommission::getCategory())->toBe('Financial')
        ->and(CreateLeadSourceCommission::getDescription())->toBeString()
        ->and(CreateLeadSourceCommission::getConfigSchema())->toHaveKeys([
            'commission_type', 'amount', 'rate', 'lead_source_slug',
        ]);
});
