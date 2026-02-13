<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use App\Notifications\EstimateReadyForAgent;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it('notifies the referring agent when estimate is marked as sent', function () {
    $agent = User::factory()->agent()->create();
    $property = Property::factory()->create();
    $deal = Deal::factory()->forProperty($property)->referredByAgent($agent)->lead()->create();
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create();

    $estimate->markAsSent();

    Notification::assertSentTo($agent, EstimateReadyForAgent::class);
});

it('does not notify when deal has no referring agent', function () {
    $deal = Deal::factory()->lead()->create();
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create();

    $estimate->markAsSent();

    Notification::assertCount(0);
});

it('notification contains correct data', function () {
    $agent = User::factory()->agent()->create();
    $property = Property::factory()->create();
    $deal = Deal::factory()->forProperty($property)->referredByAgent($agent)->lead()->create();
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create();

    $estimate->markAsSent();

    Notification::assertSentTo($agent, EstimateReadyForAgent::class, function ($notification) use ($agent, $estimate, $deal, $property) {
        $data = $notification->toArray($agent);

        expect($data['estimate_id'])->toBe($estimate->id)
            ->and($data['deal_id'])->toBe($deal->id)
            ->and($data['property_address'])->toBe($property->full_address)
            ->and($data['estimate_total'])->toBe($estimate->total);

        return true;
    });
});

it('email has correct subject and body text', function () {
    $agent = User::factory()->agent()->create();
    $property = Property::factory()->create();
    $deal = Deal::factory()->forProperty($property)->referredByAgent($agent)->lead()->create();
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create();

    $estimate->markAsSent();

    Notification::assertSentTo($agent, EstimateReadyForAgent::class, function ($notification) use ($agent, $property, $estimate) {
        $mail = $notification->toMail($agent);

        expect($mail->subject)->toBe("Estimate Ready: {$property->full_address}")
            ->and($mail->greeting)->toBe("Hello {$agent->name}!")
            ->and($mail->actionText)->toBe('View Dashboard')
            ->and(collect($mail->introLines)->join(' '))->toContain($property->full_address)
            ->and(collect($mail->introLines)->join(' '))->toContain($estimate->estimate_number);

        return true;
    });
});
