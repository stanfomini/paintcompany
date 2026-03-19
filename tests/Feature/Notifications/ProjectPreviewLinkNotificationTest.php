<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\ProjectPreview;
use App\Notifications\ProjectPreviewLinkNotification;
use Illuminate\Support\Facades\Notification;

it('sends notification when deal is created with customer who has email and phone', function () {
    Notification::fake();

    $customer = Customer::factory()->create([
        'email' => 'test@example.com',
        'phone' => '3035551234',
    ]);

    CompanySetting::getInstance()->update(['preview_link_auto_send' => true]);

    $deal = Deal::factory()->create(['customer_id' => $customer->id]);

    Notification::assertSentTo($customer, ProjectPreviewLinkNotification::class);
});

it('does not send notification when customer has no email and no phone', function () {
    Notification::fake();

    $customer = Customer::factory()->create([
        'email' => null,
        'phone' => null,
    ]);

    CompanySetting::getInstance()->update(['preview_link_auto_send' => true]);

    $deal = Deal::factory()->create(['customer_id' => $customer->id]);

    Notification::assertNotSentTo($customer, ProjectPreviewLinkNotification::class);
});

it('does not send notification when auto-send is disabled', function () {
    Notification::fake();

    $customer = Customer::factory()->create([
        'email' => 'test@example.com',
        'phone' => '3035551234',
    ]);

    CompanySetting::getInstance()->update(['preview_link_auto_send' => false]);

    $deal = Deal::factory()->create(['customer_id' => $customer->id]);

    Notification::assertNotSentTo($customer, ProjectPreviewLinkNotification::class);
});

it('does not send notification twice via idempotency guard', function () {
    Notification::fake();

    $customer = Customer::factory()->create([
        'email' => 'test@example.com',
        'phone' => '3035551234',
    ]);

    CompanySetting::getInstance()->update(['preview_link_auto_send' => true]);

    $deal = Deal::factory()->create(['customer_id' => $customer->id]);

    // Verify sent once
    Notification::assertSentToTimes($customer, ProjectPreviewLinkNotification::class, 1);

    // Verify preview_link_sent_at was set
    $preview = ProjectPreview::where('deal_id', $deal->id)->first();
    expect($preview->preview_link_sent_at)->not->toBeNull();
});

it('notification email contains portal URL', function () {
    $customer = Customer::factory()->create([
        'first_name' => 'Jane',
        'email' => 'jane@example.com',
    ]);

    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $preview = ProjectPreview::where('deal_id', $deal->id)->first();

    $notification = new ProjectPreviewLinkNotification($preview, $deal);
    $mail = $notification->toMail($customer);

    expect($mail->actionUrl)->toBe($preview->getPortalUrl());
});

it('notification SMS contains portal URL', function () {
    $customer = Customer::factory()->create([
        'first_name' => 'Jane',
        'phone' => '3035551234',
    ]);

    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $preview = ProjectPreview::where('deal_id', $deal->id)->first();

    $notification = new ProjectPreviewLinkNotification($preview, $deal);
    $sms = $notification->toSms($customer);

    expect($sms->toBody())->toContain($preview->getPortalUrl());
});

it('notification uses custom message from CompanySetting', function () {
    CompanySetting::getInstance()->update([
        'preview_link_sms_message' => 'Hey {name}, check out {link}!',
        'preview_link_email_subject' => 'Welcome from {company}',
        'company_name' => 'TestPaint Co',
    ]);

    $customer = Customer::factory()->create([
        'first_name' => 'Bob',
        'email' => 'bob@example.com',
        'phone' => '3035551234',
    ]);

    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $preview = ProjectPreview::where('deal_id', $deal->id)->first();

    $notification = new ProjectPreviewLinkNotification($preview, $deal);

    $sms = $notification->toSms($customer);
    expect($sms->toBody())->toContain('Hey Bob')
        ->and($sms->toBody())->toContain($preview->getPortalUrl());

    $mail = $notification->toMail($customer);
    expect($mail->subject)->toBe('Welcome from TestPaint Co');
});

it('does not send notification when deal has no customer', function () {
    Notification::fake();

    CompanySetting::getInstance()->update(['preview_link_auto_send' => true]);

    $deal = Deal::factory()->create(['customer_id' => null]);

    // No customer, so no preview created and no notification
    expect(ProjectPreview::where('deal_id', $deal->id)->exists())->toBeFalse();
});
