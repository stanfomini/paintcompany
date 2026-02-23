<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmailAccount;
use App\Models\EmailCrmLink;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\EmailCrmLinkingService;

beforeEach(function () {
    $this->service = new EmailCrmLinkingService;
    $this->account = EmailAccount::factory()->create(['address' => 'sales']);
});

describe('EmailCrmLinkingService', function () {
    it('auto-links inbound email to customer by email address', function () {
        $customer = Customer::factory()->create(['email' => 'customer@example.com']);

        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id]);
        $message = EmailMessage::factory()->inbound()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $this->account->id,
            'from_address' => 'customer@example.com',
            'to_addresses' => [['address' => $this->account->full_address, 'name' => null]],
        ]);

        $this->service->autoLink($message);

        $links = EmailCrmLink::where('email_message_id', $message->id)->get();

        expect($links)->toHaveCount(1);
        expect($links->first()->linkable_type)->toBe(Customer::class);
        expect($links->first()->linkable_id)->toBe($customer->id);
        expect($links->first()->link_type)->toBe('auto');
    });

    it('auto-links to customer active deals', function () {
        $customer = Customer::factory()->create(['email' => 'customer@example.com']);
        $activeDeal = Deal::factory()->forCustomer($customer)->lead()->create();

        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id]);
        $message = EmailMessage::factory()->inbound()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $this->account->id,
            'from_address' => 'customer@example.com',
            'to_addresses' => [['address' => $this->account->full_address, 'name' => null]],
        ]);

        $this->service->autoLink($message);

        $links = EmailCrmLink::where('email_message_id', $message->id)->get();

        // Should have link to customer and link to deal
        $linkableTypes = $links->pluck('linkable_type')->toArray();
        expect($linkableTypes)->toContain(Customer::class);
        expect($linkableTypes)->toContain(Deal::class);

        $dealLink = $links->firstWhere('linkable_type', Deal::class);
        expect($dealLink->linkable_id)->toBe($activeDeal->id);
    });

    it('does not link to completed or lost deals', function () {
        $customer = Customer::factory()->create(['email' => 'customer@example.com']);
        $completedDeal = Deal::factory()->forCustomer($customer)->completed()->create();
        $lostDeal = Deal::factory()->forCustomer($customer)->lost()->create();

        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id]);
        $message = EmailMessage::factory()->inbound()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $this->account->id,
            'from_address' => 'customer@example.com',
            'to_addresses' => [['address' => $this->account->full_address, 'name' => null]],
        ]);

        $this->service->autoLink($message);

        $dealLinks = EmailCrmLink::where('email_message_id', $message->id)
            ->where('linkable_type', Deal::class)
            ->get();

        expect($dealLinks)->toHaveCount(0);
    });

    it('manually links email to a model', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id]);
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $this->account->id,
        ]);

        $link = $this->service->manualLink($message, $customer, $user);

        expect($link)->toBeInstanceOf(EmailCrmLink::class);
        expect($link->link_type)->toBe('manual');
        expect($link->linked_by)->toBe($user->id);
        expect($link->linkable_type)->toBe(Customer::class);
        expect($link->linkable_id)->toBe($customer->id);
    });

    it('prevents duplicate links', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id]);
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $this->account->id,
        ]);

        $link1 = $this->service->manualLink($message, $customer, $user);
        $link2 = $this->service->manualLink($message, $customer, $user);

        expect($link1->id)->toBe($link2->id);
        expect(EmailCrmLink::where('email_message_id', $message->id)->count())->toBe(1);
    });

    it('unlinks a CRM link', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id]);
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $this->account->id,
        ]);

        $link = $this->service->manualLink($message, $customer, $user);

        expect(EmailCrmLink::count())->toBe(1);

        $this->service->unlink($link);

        expect(EmailCrmLink::count())->toBe(0);
    });
});
