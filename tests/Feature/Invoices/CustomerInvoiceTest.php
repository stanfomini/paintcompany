<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Livewire\Livewire;

// --- Index Tests ---

it('renders invoice index for authenticated customer', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    Invoice::factory()->sent()->create(['customer_id' => $customer->id]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.index')
        ->assertOk()
        ->assertSee('Invoices');
});

it('shows only own invoices', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    $otherCustomer = Customer::factory()->create();

    $ownInvoice = Invoice::factory()->sent()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-OWN-001',
    ]);
    $otherInvoice = Invoice::factory()->sent()->create([
        'customer_id' => $otherCustomer->id,
        'invoice_number' => 'INV-OTHER-001',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.index')
        ->assertSee('INV-OWN-001')
        ->assertDontSee('INV-OTHER-001');
});

it('hides draft invoices from listing', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    Invoice::factory()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-DRAFT-001',
        'status' => 'draft',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.index')
        ->assertDontSee('INV-DRAFT-001');
});

it('hides void invoices from listing', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    Invoice::factory()->void()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-VOID-001',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.index')
        ->assertDontSee('INV-VOID-001');
});

it('filters invoices by search', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    Invoice::factory()->sent()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-SEARCH-AAA',
    ]);
    Invoice::factory()->sent()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-SEARCH-BBB',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.index')
        ->set('search', 'AAA')
        ->assertSee('INV-SEARCH-AAA')
        ->assertDontSee('INV-SEARCH-BBB');
});

it('filters invoices by status', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    Invoice::factory()->sent()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-SENT-001',
    ]);
    Invoice::factory()->paid()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-PAID-001',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.index')
        ->set('status', 'sent')
        ->assertSee('INV-SENT-001')
        ->assertDontSee('INV-PAID-001');
});

it('shows empty state when no invoices', function () {
    $customerUser = User::factory()->customer()->create();
    Customer::factory()->create(['user_id' => $customerUser->id]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.index')
        ->assertSee('No invoices found');
});

it('shows outstanding balance summary', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    Invoice::factory()->sent()->create([
        'customer_id' => $customer->id,
        'balance_due' => 1500.00,
        'total' => 1500.00,
    ]);
    Invoice::factory()->sent()->create([
        'customer_id' => $customer->id,
        'balance_due' => 2500.00,
        'total' => 2500.00,
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.index')
        ->assertSee('4,000.00');
});

// --- Show Tests ---

it('renders invoice show for own invoice', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $invoice = Invoice::factory()->sent()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-SHOW-001',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.show', ['invoice' => $invoice])
        ->assertOk()
        ->assertSee('INV-SHOW-001');
});

it('returns 404 for another customers invoice', function () {
    $customerUser = User::factory()->customer()->create();
    Customer::factory()->create(['user_id' => $customerUser->id]);

    $otherCustomer = Customer::factory()->create();
    $otherInvoice = Invoice::factory()->sent()->create([
        'customer_id' => $otherCustomer->id,
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.show', ['invoice' => $otherInvoice])
        ->assertNotFound();
});

it('returns 404 for draft invoice', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'draft',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.show', ['invoice' => $invoice])
        ->assertNotFound();
});

it('displays invoice details on show page', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $invoice = Invoice::factory()->sent()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-DETAIL-001',
        'total' => 5250.00,
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.show', ['invoice' => $invoice])
        ->assertSee('INV-DETAIL-001')
        ->assertSee('5,250.00');
});

it('displays payment history', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $invoice = Invoice::factory()->sent()->create([
        'customer_id' => $customer->id,
        'total' => 2000.00,
        'balance_due' => 1500.00,
        'amount_paid' => 500.00,
        'status' => 'partially_paid',
    ]);

    Payment::factory()->forInvoice($invoice)->create([
        'amount' => 500.00,
        'method' => 'check',
        'status' => 'completed',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.show', ['invoice' => $invoice])
        ->assertSee('500.00');
});

it('shows pay button for unpaid invoice with valid token', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $invoice = Invoice::factory()->sent()->create([
        'customer_id' => $customer->id,
    ]);
    $invoice->generatePublicToken(30);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.show', ['invoice' => $invoice])
        ->assertSee('Pay Now');
});

it('hides pay button for paid invoice', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $invoice = Invoice::factory()->paid()->create([
        'customer_id' => $customer->id,
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.invoices.show', ['invoice' => $invoice])
        ->assertDontSee('Pay Now');
});
