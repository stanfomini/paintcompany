<?php

use App\Models\Estimate;
use App\Models\EstimatePackage;
use App\Models\Job;
use App\Models\WarrantyReserve;

beforeEach(function () {
    $this->estimate = Estimate::factory()->create();
    $this->package = EstimatePackage::factory()->create([
        'estimate_id' => $this->estimate->id,
        'warranty_years' => 3,
        'warranty_price' => 150.00,
    ]);
});

describe('WarrantyReserve Model', function () {
    test('can create warranty reserve from package', function () {
        $warranty = WarrantyReserve::createFromPackage($this->package);

        expect($warranty)->not->toBeNull()
            ->and($warranty->estimate_id)->toBe($this->estimate->id)
            ->and($warranty->estimate_package_id)->toBe($this->package->id)
            ->and($warranty->warranty_years)->toBe(3)
            ->and((float) $warranty->amount_reserved)->toBe(150.00)
            ->and((float) $warranty->amount_claimed)->toBe(0.00)
            ->and($warranty->status)->toBe(WarrantyReserve::STATUS_PENDING);
    });

    test('returns null when package has no warranty', function () {
        $packageNoWarranty = EstimatePackage::factory()->create([
            'estimate_id' => $this->estimate->id,
            'warranty_years' => 0,
            'warranty_price' => 0,
        ]);

        $warranty = WarrantyReserve::createFromPackage($packageNoWarranty);

        expect($warranty)->toBeNull();
    });

    test('returns existing warranty if already created for package', function () {
        $first = WarrantyReserve::createFromPackage($this->package);
        $second = WarrantyReserve::createFromPackage($this->package);

        expect($first->id)->toBe($second->id);
    });

    test('calculates remaining balance correctly', function () {
        $warranty = WarrantyReserve::factory()->create([
            'amount_reserved' => 200.00,
            'amount_claimed' => 50.00,
        ]);

        expect($warranty->remaining_balance)->toBe(150.00);
    });

    test('calculates claim percentage correctly', function () {
        $warranty = WarrantyReserve::factory()->create([
            'amount_reserved' => 200.00,
            'amount_claimed' => 50.00,
        ]);

        expect($warranty->claim_percentage)->toBe(25.0);
    });

    test('handles zero reserved amount in claim percentage', function () {
        $warranty = WarrantyReserve::factory()->create([
            'amount_reserved' => 0.00,
            'amount_claimed' => 0.00,
        ]);

        expect($warranty->claim_percentage)->toBe(0.0);
    });

    test('is_valid returns true for active unexpired warranty', function () {
        $warranty = WarrantyReserve::factory()->active()->create();

        expect($warranty->is_valid)->toBeTrue();
    });

    test('is_valid returns false for expired warranty', function () {
        $warranty = WarrantyReserve::factory()->expired()->create();

        expect($warranty->is_valid)->toBeFalse();
    });

    test('is_valid returns false for pending warranty', function () {
        $warranty = WarrantyReserve::factory()->create([
            'status' => WarrantyReserve::STATUS_PENDING,
        ]);

        expect($warranty->is_valid)->toBeFalse();
    });

    test('has_available_balance returns true when balance remains', function () {
        $warranty = WarrantyReserve::factory()->create([
            'amount_reserved' => 200.00,
            'amount_claimed' => 100.00,
        ]);

        expect($warranty->has_available_balance)->toBeTrue();
    });

    test('has_available_balance returns false when fully claimed', function () {
        $warranty = WarrantyReserve::factory()->fullyClaimed()->create();

        expect($warranty->has_available_balance)->toBeFalse();
    });

    test('calculates days until expiration', function () {
        $warranty = WarrantyReserve::factory()->create([
            'warranty_expires_at' => now()->addDays(30),
        ]);

        // Use range due to time-of-day variations in Carbon diffInDays
        expect($warranty->days_until_expiration)->toBeGreaterThanOrEqual(29)
            ->and($warranty->days_until_expiration)->toBeLessThanOrEqual(30);
    });

    test('days until expiration is negative when expired', function () {
        $warranty = WarrantyReserve::factory()->create([
            'warranty_expires_at' => now()->subDays(10),
        ]);

        expect($warranty->days_until_expiration)->toBeGreaterThanOrEqual(-11)
            ->and($warranty->days_until_expiration)->toBeLessThanOrEqual(-10);
    });
});

describe('WarrantyReserve Claims', function () {
    test('can record a claim on active warranty', function () {
        $warranty = WarrantyReserve::factory()->active()->create([
            'amount_reserved' => 200.00,
            'amount_claimed' => 0.00,
        ]);

        $warranty->recordClaim(75.00, 'Minor touch-up repair');

        $warranty->refresh();
        expect((float) $warranty->amount_claimed)->toBe(75.00)
            ->and($warranty->remaining_balance)->toBe(125.00)
            ->and($warranty->notes)->toContain('Claim of $75')
            ->and($warranty->notes)->toContain('Minor touch-up repair');
    });

    test('throws exception when claiming on invalid warranty', function () {
        $warranty = WarrantyReserve::factory()->create([
            'status' => WarrantyReserve::STATUS_PENDING,
            'amount_reserved' => 200.00,
        ]);

        $warranty->recordClaim(50.00);
    })->throws(\RuntimeException::class, 'Cannot record claim on invalid warranty.');

    test('throws exception when claim exceeds available balance', function () {
        $warranty = WarrantyReserve::factory()->active()->create([
            'amount_reserved' => 100.00,
            'amount_claimed' => 0.00,
        ]);

        $warranty->recordClaim(150.00);
    })->throws(\RuntimeException::class, 'Claim amount exceeds available warranty balance.');

    test('automatically sets status to fully claimed when balance depleted', function () {
        $warranty = WarrantyReserve::factory()->active()->create([
            'amount_reserved' => 100.00,
            'amount_claimed' => 50.00,
        ]);

        $warranty->recordClaim(50.00, 'Final claim');

        $warranty->refresh();
        expect($warranty->status)->toBe(WarrantyReserve::STATUS_FULLY_CLAIMED)
            ->and($warranty->remaining_balance)->toBe(0.0);
    });

    test('appends multiple claims to notes', function () {
        $warranty = WarrantyReserve::factory()->active()->create([
            'amount_reserved' => 200.00,
            'amount_claimed' => 0.00,
            'notes' => null,
        ]);

        $warranty->recordClaim(50.00, 'First repair');
        $warranty->recordClaim(30.00, 'Second repair');

        $warranty->refresh();
        expect($warranty->notes)->toContain('First repair')
            ->and($warranty->notes)->toContain('Second repair')
            ->and((float) $warranty->amount_claimed)->toBe(80.00);
    });
});

describe('WarrantyReserve Activation', function () {
    test('can activate a pending warranty', function () {
        $warranty = WarrantyReserve::factory()->create([
            'status' => WarrantyReserve::STATUS_PENDING,
            'warranty_years' => 3,
        ]);

        $warranty->activate();

        $warranty->refresh();
        expect($warranty->status)->toBe(WarrantyReserve::STATUS_ACTIVE)
            ->and($warranty->warranty_starts_at->isToday())->toBeTrue()
            ->and($warranty->warranty_expires_at->year)->toBe(now()->addYears(3)->year);
    });

    test('does not reactivate already active warranty', function () {
        $originalStart = now()->subMonths(6);
        $warranty = WarrantyReserve::factory()->create([
            'status' => WarrantyReserve::STATUS_ACTIVE,
            'warranty_starts_at' => $originalStart,
            'warranty_expires_at' => $originalStart->copy()->addYears(3),
        ]);

        $warranty->activate();

        $warranty->refresh();
        expect($warranty->warranty_starts_at->format('Y-m-d'))->toBe($originalStart->format('Y-m-d'));
    });
});

describe('WarrantyReserve Expiration', function () {
    test('can expire old warranties in bulk', function () {
        // Create some active warranties that should expire
        WarrantyReserve::factory()->count(3)->create([
            'status' => WarrantyReserve::STATUS_ACTIVE,
            'warranty_expires_at' => now()->subDay(),
        ]);

        // Create some that should NOT expire
        WarrantyReserve::factory()->count(2)->create([
            'status' => WarrantyReserve::STATUS_ACTIVE,
            'warranty_expires_at' => now()->addDays(30),
        ]);

        $expiredCount = WarrantyReserve::expireOldWarranties();

        expect($expiredCount)->toBe(3)
            ->and(WarrantyReserve::where('status', WarrantyReserve::STATUS_EXPIRED)->count())->toBe(3)
            ->and(WarrantyReserve::where('status', WarrantyReserve::STATUS_ACTIVE)->count())->toBe(2);
    });
});

describe('WarrantyReserve Scopes', function () {
    beforeEach(function () {
        // Clear any existing warranties
        WarrantyReserve::query()->delete();

        // Create warranties in different states
        WarrantyReserve::factory()->create(['status' => WarrantyReserve::STATUS_PENDING]);
        WarrantyReserve::factory()->create(['status' => WarrantyReserve::STATUS_PENDING]);
        WarrantyReserve::factory()->active()->create();
        WarrantyReserve::factory()->active()->create();
        WarrantyReserve::factory()->active()->create();
        WarrantyReserve::factory()->expired()->create();
        WarrantyReserve::factory()->fullyClaimed()->create();
    });

    test('pending scope returns only pending warranties', function () {
        expect(WarrantyReserve::pending()->count())->toBe(2);
    });

    test('active scope returns only active warranties', function () {
        expect(WarrantyReserve::active()->count())->toBe(3);
    });

    test('expired scope returns only expired warranties', function () {
        expect(WarrantyReserve::expired()->count())->toBe(1);
    });

    test('expiringSoon scope returns warranties expiring within days', function () {
        // Clear and create fresh
        WarrantyReserve::query()->delete();

        // Create one expiring in 7 days
        WarrantyReserve::factory()->expiringSoon(7)->create();

        // Create one expiring in 60 days (should not match default 30 days)
        WarrantyReserve::factory()->create([
            'status' => WarrantyReserve::STATUS_ACTIVE,
            'warranty_expires_at' => now()->addDays(60),
        ]);

        expect(WarrantyReserve::expiringSoon()->count())->toBe(1)
            ->and(WarrantyReserve::expiringSoon(90)->count())->toBe(2);
    });
});

describe('WarrantyReserve Relationships', function () {
    test('belongs to estimate', function () {
        $warranty = WarrantyReserve::factory()->create([
            'estimate_id' => $this->estimate->id,
        ]);

        expect($warranty->estimate)->toBeInstanceOf(Estimate::class)
            ->and($warranty->estimate->id)->toBe($this->estimate->id);
    });

    test('belongs to package', function () {
        $warranty = WarrantyReserve::factory()->create([
            'estimate_package_id' => $this->package->id,
        ]);

        expect($warranty->package)->toBeInstanceOf(EstimatePackage::class)
            ->and($warranty->package->id)->toBe($this->package->id);
    });

    test('optionally belongs to job', function () {
        // Create warranty first, then link a job
        $warranty = WarrantyReserve::factory()->create([
            'estimate_id' => $this->estimate->id,
            'estimate_package_id' => $this->package->id,
            'job_id' => null,
        ]);

        // Create job from same estimate
        $job = Job::factory()->create([
            'estimate_id' => $this->estimate->id,
        ]);

        // Update warranty with job
        $warranty->update(['job_id' => $job->id]);
        $warranty->refresh();

        expect($warranty->job)->toBeInstanceOf(Job::class)
            ->and($warranty->job->id)->toBe($job->id);
    });

    test('job is nullable', function () {
        $warranty = WarrantyReserve::factory()->create([
            'job_id' => null,
        ]);

        expect($warranty->job)->toBeNull();
    });
});

describe('WarrantyReserve Factory States', function () {
    test('active state creates valid active warranty', function () {
        $warranty = WarrantyReserve::factory()->active()->create();

        expect($warranty->status)->toBe(WarrantyReserve::STATUS_ACTIVE)
            ->and($warranty->warranty_starts_at->isPast())->toBeTrue()
            ->and($warranty->warranty_expires_at->isFuture())->toBeTrue();
    });

    test('expired state creates expired warranty', function () {
        $warranty = WarrantyReserve::factory()->expired()->create();

        expect($warranty->status)->toBe(WarrantyReserve::STATUS_EXPIRED)
            ->and($warranty->warranty_expires_at->isPast())->toBeTrue();
    });

    test('fullyClaimed state creates fully claimed warranty', function () {
        // Create with specific reserved amount, then make it fully claimed
        $warranty = WarrantyReserve::factory()->create([
            'amount_reserved' => 200.00,
            'amount_claimed' => 200.00,
            'status' => WarrantyReserve::STATUS_FULLY_CLAIMED,
        ]);

        expect($warranty->status)->toBe(WarrantyReserve::STATUS_FULLY_CLAIMED)
            ->and((float) $warranty->amount_claimed)->toBe(200.00)
            ->and($warranty->remaining_balance)->toBe(0.0);
    });

    test('years state sets custom warranty period', function () {
        $warranty = WarrantyReserve::factory()->years(5)->create();

        expect($warranty->warranty_years)->toBe(5);
    });

    test('withClaims state sets specific claimed amount', function () {
        $warranty = WarrantyReserve::factory()
            ->withClaims(75.50)
            ->create(['amount_reserved' => 200.00]);

        expect((float) $warranty->amount_claimed)->toBe(75.50)
            ->and($warranty->remaining_balance)->toBe(124.50);
    });

    test('expiringSoon state creates warranty near expiration', function () {
        $warranty = WarrantyReserve::factory()->expiringSoon(14)->create();

        // Use range due to time-of-day variations
        expect($warranty->status)->toBe(WarrantyReserve::STATUS_ACTIVE)
            ->and($warranty->days_until_expiration)->toBeGreaterThanOrEqual(13)
            ->and($warranty->days_until_expiration)->toBeLessThanOrEqual(14);
    });

    test('forPackage state uses package values', function () {
        $warranty = WarrantyReserve::factory()->forPackage($this->package)->create();

        expect($warranty->estimate_id)->toBe($this->package->estimate_id)
            ->and($warranty->estimate_package_id)->toBe($this->package->id)
            ->and($warranty->warranty_years)->toBe($this->package->warranty_years)
            ->and((float) $warranty->amount_reserved)->toBe((float) $this->package->warranty_price);
    });
});

describe('WarrantyReserve Status Helpers', function () {
    test('getStatuses returns all available statuses', function () {
        $statuses = WarrantyReserve::getStatuses();

        expect($statuses)->toBeArray()
            ->and($statuses)->toHaveCount(4)
            ->and($statuses[WarrantyReserve::STATUS_PENDING])->toBe('Pending')
            ->and($statuses[WarrantyReserve::STATUS_ACTIVE])->toBe('Active')
            ->and($statuses[WarrantyReserve::STATUS_EXPIRED])->toBe('Expired')
            ->and($statuses[WarrantyReserve::STATUS_FULLY_CLAIMED])->toBe('Fully Claimed');
    });
});
