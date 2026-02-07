<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoiceService;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'invoices.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'invoices.create', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'invoices.send', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'invoices.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'payments.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'payments.record', 'guard_name' => 'web']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

// --- Model Tests ---

it('has correct type constants', function () {
    expect(Invoice::TYPE_DEPOSIT)->toBe('deposit')
        ->and(Invoice::TYPE_FINAL)->toBe('final')
        ->and(Invoice::TYPE_CHANGE_ORDER)->toBe('change_order')
        ->and(Invoice::TYPE_CUSTOM)->toBe('custom');
});

it('has correct status constants', function () {
    expect(Invoice::STATUS_DRAFT)->toBe('draft')
        ->and(Invoice::STATUS_SENT)->toBe('sent')
        ->and(Invoice::STATUS_PARTIALLY_PAID)->toBe('partially_paid')
        ->and(Invoice::STATUS_PAID)->toBe('paid')
        ->and(Invoice::STATUS_VOID)->toBe('void')
        ->and(Invoice::STATUS_OVERDUE)->toBe('overdue');
});

it('belongs to company setting', function () {
    $invoice = Invoice::factory()->create();

    expect($invoice->companySetting)->toBeInstanceOf(CompanySetting::class);
});

it('belongs to customer', function () {
    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id]);

    expect($invoice->customer)->toBeInstanceOf(Customer::class)
        ->and($invoice->customer->id)->toBe($customer->id);
});

it('belongs to estimate when set', function () {
    $estimate = Estimate::factory()->create();
    $invoice = Invoice::factory()->forEstimate($estimate)->create();

    expect($invoice->estimate)->toBeInstanceOf(Estimate::class)
        ->and($invoice->estimate->id)->toBe($estimate->id);
});

it('has many payments', function () {
    $invoice = Invoice::factory()->create();
    Payment::factory()->forInvoice($invoice)->create();
    Payment::factory()->forInvoice($invoice)->create();

    expect($invoice->payments)->toHaveCount(2);
});

it('generates and validates public token', function () {
    $invoice = Invoice::factory()->create();

    expect($invoice->public_token)->toBeNull();

    $token = $invoice->generatePublicToken(30);

    expect($token)->not->toBeNull()
        ->and($invoice->isPublicTokenValid())->toBeTrue()
        ->and($invoice->getPublicUrl())->toContain($token);

    $invoice->revokePublicToken();

    expect($invoice->public_token)->toBeNull()
        ->and($invoice->isPublicTokenValid())->toBeFalse();
});

it('recalculates amounts from payments', function () {
    $invoice = Invoice::factory()->sent()->create([
        'total' => 1000,
        'balance_due' => 1000,
        'amount_paid' => 0,
    ]);

    Payment::factory()->forInvoice($invoice)->create([
        'amount' => 400,
        'status' => 'completed',
    ]);

    $invoice->recalculateAmounts();

    expect((float) $invoice->amount_paid)->toBe(400.0)
        ->and((float) $invoice->balance_due)->toBe(600.0)
        ->and($invoice->status)->toBe('partially_paid');
});

it('marks as paid when fully paid', function () {
    $invoice = Invoice::factory()->sent()->create([
        'total' => 500,
        'balance_due' => 500,
        'amount_paid' => 0,
    ]);

    Payment::factory()->forInvoice($invoice)->create([
        'amount' => 500,
        'status' => 'completed',
    ]);

    $invoice->recalculateAmounts();

    expect($invoice->status)->toBe('paid')
        ->and($invoice->paid_at)->not->toBeNull()
        ->and((float) $invoice->balance_due)->toBe(0.0);
});

it('can check if it accepts payments', function () {
    $draft = Invoice::factory()->create(['status' => 'draft']);
    $sent = Invoice::factory()->sent()->create();
    $paid = Invoice::factory()->paid()->create();
    $void = Invoice::factory()->void()->create();

    expect($draft->canAcceptPayments())->toBeFalse()
        ->and($sent->canAcceptPayments())->toBeTrue()
        ->and($paid->canAcceptPayments())->toBeFalse()
        ->and($void->canAcceptPayments())->toBeFalse();
});

it('scopes by status', function () {
    Invoice::factory()->create(['status' => 'draft']);
    Invoice::factory()->sent()->create();
    Invoice::factory()->paid()->create();

    expect(Invoice::byStatus('draft')->count())->toBe(1)
        ->and(Invoice::byStatus('sent')->count())->toBe(1)
        ->and(Invoice::byStatus('paid')->count())->toBe(1);
});

it('scopes unpaid invoices', function () {
    Invoice::factory()->create(['status' => 'draft']);
    Invoice::factory()->sent()->create();
    Invoice::factory()->create(['status' => 'partially_paid']);
    Invoice::factory()->paid()->create();

    expect(Invoice::unpaid()->count())->toBe(2);
});

// --- Service Tests ---

it('generates unique invoice numbers', function () {
    $service = new InvoiceService;

    $num1 = $service->generateInvoiceNumber();
    Invoice::factory()->create(['invoice_number' => $num1]);

    $num2 = $service->generateInvoiceNumber();

    expect($num1)->toStartWith('INV-'.now()->year.'-')
        ->and($num2)->toStartWith('INV-'.now()->year.'-')
        ->and($num1)->not->toBe($num2);
});

it('creates deposit invoice from estimate', function () {
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
        'total' => 10000,
        'tax_rate' => 0,
    ]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    $service = new InvoiceService;
    $invoice = $service->createDepositInvoice($estimate, $job);

    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($invoice->exists)->toBeTrue()
        ->and($invoice->type)->toBe('deposit')
        ->and($invoice->status)->toBe('draft')
        ->and((float) $invoice->subtotal)->toBe(5000.0) // 50% deposit
        ->and($invoice->estimate_id)->toBe($estimate->id)
        ->and($invoice->job_id)->toBe($job->id)
        ->and($invoice->customer_id)->toBe($customer->id);
});

it('creates final invoice for completed job', function () {
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
        'total' => 10000,
        'tax_rate' => 0,
    ]);
    $job = Job::factory()->completed()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    $service = new InvoiceService;
    $invoice = $service->createFinalInvoice($job);

    expect($invoice->type)->toBe('final')
        ->and($invoice->status)->toBe('draft')
        ->and((float) $invoice->subtotal)->toBe(10000.0)
        ->and($invoice->job_id)->toBe($job->id);
});

it('creates final invoice minus paid deposits', function () {
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
        'total' => 10000,
        'tax_rate' => 0,
    ]);
    $job = Job::factory()->completed()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    // Create a paid deposit invoice
    Invoice::factory()->deposit()->paid()->create([
        'job_id' => $job->id,
        'total' => 5000,
    ]);

    $service = new InvoiceService;
    $invoice = $service->createFinalInvoice($job);

    expect((float) $invoice->subtotal)->toBe(5000.0); // 10000 - 5000 deposit
});

it('marks invoice as void', function () {
    $invoice = Invoice::factory()->sent()->create();

    $service = new InvoiceService;
    $service->markAsVoid($invoice);

    expect($invoice->fresh()->status)->toBe('void');
});

it('marks invoice as paid', function () {
    $invoice = Invoice::factory()->sent()->create([
        'total' => 1000,
    ]);

    $service = new InvoiceService;
    $service->markAsPaid($invoice);

    $invoice->refresh();
    expect($invoice->status)->toBe('paid')
        ->and($invoice->paid_at)->not->toBeNull()
        ->and((float) $invoice->amount_paid)->toBe(1000.0)
        ->and((float) $invoice->balance_due)->toBe(0.0);
});

it('records manual payment', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $invoice = Invoice::factory()->sent()->create([
        'total' => 1000,
        'balance_due' => 1000,
    ]);

    $service = new InvoiceService;
    $payment = $service->recordManualPayment($invoice, [
        'amount' => 500,
        'method' => 'check',
        'reference_number' => '1234',
        'notes' => 'Partial payment',
        'received_at' => now(),
    ]);

    expect($payment)->toBeInstanceOf(Payment::class)
        ->and($payment->exists)->toBeTrue()
        ->and((float) $payment->amount)->toBe(500.0)
        ->and($payment->method)->toBe('check')
        ->and($payment->reference_number)->toBe('1234')
        ->and($payment->status)->toBe('completed');

    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(500.0)
        ->and((float) $invoice->balance_due)->toBe(500.0)
        ->and($invoice->status)->toBe('partially_paid');
});

it('creates custom invoice', function () {
    $customer = Customer::factory()->create();

    $service = new InvoiceService;
    $invoice = $service->createCustomInvoice($customer, [
        'subtotal' => 750,
        'notes' => 'Custom work',
    ]);

    expect($invoice->type)->toBe('custom')
        ->and($invoice->status)->toBe('draft')
        ->and((float) $invoice->subtotal)->toBe(750.0)
        ->and($invoice->customer_id)->toBe($customer->id);
});

// --- Livewire Component Tests ---

it('renders invoice index', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('invoices.index')
        ->assertOk()
        ->assertSee('Invoices');
});

it('shows invoices in the list', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Invoice::factory()->create(['invoice_number' => 'INV-2026-00042']);

    Livewire::actingAs($user)
        ->test('invoices.index')
        ->assertSee('INV-2026-00042');
});

it('filters invoices by status', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Invoice::factory()->create(['status' => 'draft', 'invoice_number' => 'INV-DRAFT-001']);
    Invoice::factory()->sent()->create(['invoice_number' => 'INV-SENT-001']);

    Livewire::actingAs($user)
        ->test('invoices.index')
        ->set('statusFilter', 'draft')
        ->assertSee('INV-DRAFT-001')
        ->assertDontSee('INV-SENT-001');
});

it('can create a custom invoice from modal', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create();

    Livewire::actingAs($user)
        ->test('invoices.index')
        ->call('openCreateModal')
        ->assertSet('showCreateModal', true)
        ->set('createCustomerId', $customer->id)
        ->set('createSubtotal', '2500')
        ->set('createNotes', 'Test invoice')
        ->call('createInvoice')
        ->assertSet('showCreateModal', false);

    $invoice = Invoice::where('notes', 'Test invoice')->first();
    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->subtotal)->toBe(2500.0)
        ->and($invoice->customer_id)->toBe($customer->id);
});

it('requires invoices.view permission', function () {
    $viewOnly = Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewOnly]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    Livewire::actingAs($user)
        ->test('invoices.index')
        ->assertForbidden();
});

it('renders invoice show page', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $invoice = Invoice::factory()->create(['invoice_number' => 'INV-2026-00099']);

    Livewire::actingAs($user)
        ->test('invoices.show', ['invoice' => $invoice])
        ->assertOk()
        ->assertSee('INV-2026-00099');
});

it('can send invoice from show page', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $invoice = Invoice::factory()->create(['status' => 'draft']);

    Livewire::actingAs($user)
        ->test('invoices.show', ['invoice' => $invoice])
        ->call('sendInvoice');

    expect($invoice->fresh()->status)->toBe('sent')
        ->and($invoice->fresh()->sent_at)->not->toBeNull()
        ->and($invoice->fresh()->public_token)->not->toBeNull();
});

it('can record payment from show page', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $invoice = Invoice::factory()->sent()->create([
        'total' => 2000,
        'balance_due' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('invoices.show', ['invoice' => $invoice])
        ->call('openPaymentModal')
        ->assertSet('showPaymentModal', true)
        ->set('paymentAmount', '1000')
        ->set('paymentMethod', 'check')
        ->set('paymentReference', '5678')
        ->set('paymentReceivedAt', now()->format('Y-m-d'))
        ->call('recordPayment')
        ->assertSet('showPaymentModal', false);

    expect(Payment::count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe('partially_paid');
});

it('can void invoice from show page', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $invoice = Invoice::factory()->sent()->create();

    Livewire::actingAs($user)
        ->test('invoices.show', ['invoice' => $invoice])
        ->call('voidInvoice');

    expect($invoice->fresh()->status)->toBe('void');
});

// --- Payment Model Tests ---

it('payment belongs to invoice', function () {
    $invoice = Invoice::factory()->create();
    $payment = Payment::factory()->forInvoice($invoice)->create();

    expect($payment->invoice)->toBeInstanceOf(Invoice::class)
        ->and($payment->invoice->id)->toBe($invoice->id);
});

it('payment has method constants', function () {
    expect(Payment::METHOD_STRIPE)->toBe('stripe')
        ->and(Payment::METHOD_CHECK)->toBe('check')
        ->and(Payment::METHOD_CASH)->toBe('cash')
        ->and(Payment::METHOD_ACH)->toBe('ach')
        ->and(Payment::METHOD_OTHER)->toBe('other');
});

it('payment has status constants', function () {
    expect(Payment::STATUS_PENDING)->toBe('pending')
        ->and(Payment::STATUS_COMPLETED)->toBe('completed')
        ->and(Payment::STATUS_FAILED)->toBe('failed')
        ->and(Payment::STATUS_REFUNDED)->toBe('refunded');
});

// --- Public Payment Page Tests ---

it('shows invoice details on public payment page', function () {
    $invoice = Invoice::factory()->sent()->create([
        'invoice_number' => 'INV-2026-00077',
    ]);
    $invoice->generatePublicToken(30);

    Livewire::test('public.invoice-payment', ['token' => $invoice->public_token])
        ->assertOk()
        ->assertSee('INV-2026-00077')
        ->assertSee('Balance Due');
});

it('shows not found for invalid token', function () {
    Livewire::test('public.invoice-payment', ['token' => 'invalid-token-here'])
        ->assertOk()
        ->assertSee('Invoice Not Found');
});

it('shows paid status for paid invoice', function () {
    $invoice = Invoice::factory()->paid()->create();
    $invoice->generatePublicToken(30);

    Livewire::test('public.invoice-payment', ['token' => $invoice->public_token])
        ->assertOk()
        ->assertSee('Invoice Paid');
});

it('shows voided status for void invoice', function () {
    $invoice = Invoice::factory()->void()->create();
    $invoice->generatePublicToken(30);

    Livewire::test('public.invoice-payment', ['token' => $invoice->public_token])
        ->assertOk()
        ->assertSee('Invoice Voided');
});
