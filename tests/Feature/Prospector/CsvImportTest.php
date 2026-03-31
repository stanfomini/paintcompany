<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function createCsvFile(array $headers, array $rows): UploadedFile
{
    $content = implode(',', $headers)."\n";
    foreach ($rows as $row) {
        $content .= implode(',', $row)."\n";
    }

    return UploadedFile::fake()->createWithContent('contacts.csv', $content);
}

beforeEach(function () {
    $this->user = User::factory()->create(['type' => 'internal']);
    $this->company = Company::factory()->create();
    $this->actingAs($this->user);
});

it('parses CSV headers correctly on upload', function () {
    $csv = createCsvFile(
        ['First Name', 'Last Name', 'Phone', 'Email'],
        [['John', 'Doe', '7205551234', 'john@example.com']],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->assertSet('importStep', 2)
        ->assertSet('csvHeaders', ['First Name', 'Last Name', 'Phone', 'Email'])
        ->assertSet('importTotal', 1);
});

it('auto-detects column mapping from common header names', function () {
    $csv = createCsvFile(
        ['first_name', 'last_name', 'phone', 'email', 'address'],
        [['Jane', 'Smith', '7205559999', 'jane@test.com', '123 Main St']],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->assertSet('columnMapping.first_name', 'first_name')
        ->assertSet('columnMapping.last_name', 'last_name')
        ->assertSet('columnMapping.phone', 'phone')
        ->assertSet('columnMapping.email', 'email')
        ->assertSet('columnMapping.address', 'address');
});

it('auto-detects alternative header names', function () {
    $csv = createCsvFile(
        ['FirstName', 'Surname', 'Mobile', 'E-Mail'],
        [['Bob', 'Jones', '7205551111', 'bob@test.com']],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->assertSet('columnMapping.FirstName', 'first_name')
        ->assertSet('columnMapping.Mobile', 'phone')
        ->assertSet('columnMapping.E-Mail', 'email'); // e-mail normalizes to e_mail which matches
});

it('requires first_name and phone column mapping', function () {
    $csv = createCsvFile(
        ['Name', 'Email'],
        [['John', 'john@test.com']],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', ['Name' => 'last_name', 'Email' => 'email'])
        ->call('submitMapping')
        ->assertHasErrors('columnMapping');
});

it('requires phone mapping specifically', function () {
    $csv = createCsvFile(
        ['first_name', 'email'],
        [['John', 'john@test.com']],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', ['first_name' => 'first_name', 'email' => 'email'])
        ->call('submitMapping')
        ->assertHasErrors('columnMapping');
});

it('prevents duplicate field mappings', function () {
    $csv = createCsvFile(
        ['col1', 'col2'],
        [['John', '7205551234']],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', ['col1' => 'first_name', 'col2' => 'first_name'])
        ->call('submitMapping')
        ->assertHasErrors('columnMapping');
});

it('creates contacts via WebhookIntakeService', function () {
    $csv = createCsvFile(
        ['first_name', 'last_name', 'phone', 'email'],
        [
            ['John', 'Doe', '7205551234', 'john@example.com'],
            ['Jane', 'Smith', '7205555678', 'jane@example.com'],
        ],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'phone' => 'phone',
            'email' => 'email',
        ])
        ->call('submitMapping')
        ->set('importCompanyId', $this->company->id)
        ->call('startImport')
        ->assertSet('importStep', 5)
        ->assertSet('importCreated', 2)
        ->assertSet('importUpdated', 0)
        ->assertSet('importErrorCount', 0);

    expect(Contact::where('company_id', $this->company->id)->count())->toBe(2);

    $contact = Contact::where('phone', '+17205551234')->first();
    expect($contact)
        ->first_name->toBe('John')
        ->last_name->toBe('Doe')
        ->email->toBe('john@example.com')
        ->source->toBe('csv_import');
});

it('skips DNC contacts during import', function () {
    // Create a DNC contact
    Contact::factory()->forCompany($this->company)->dnc()->create([
        'phone' => '+17205551234',
        'first_name' => 'DNC',
    ]);

    $csv = createCsvFile(
        ['first_name', 'phone'],
        [
            ['John', '7205551234'], // DNC number
            ['Jane', '7205555678'], // New contact
        ],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', ['first_name' => 'first_name', 'phone' => 'phone'])
        ->call('submitMapping')
        ->set('importCompanyId', $this->company->id)
        ->call('startImport')
        ->assertSet('importStep', 5)
        ->assertSet('importCreated', 1)
        ->assertSet('importSkipped', 1);
});

it('detects and handles duplicate contacts', function () {
    // Create existing contact
    Contact::factory()->forCompany($this->company)->create([
        'phone' => '+17205551234',
        'first_name' => 'Existing',
        'email' => 'old@example.com',
    ]);

    $csv = createCsvFile(
        ['first_name', 'phone', 'email'],
        [
            ['Updated', '7205551234', 'new@example.com'], // Duplicate phone
            ['New Person', '7205559999', 'new-person@example.com'],
        ],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', [
            'first_name' => 'first_name',
            'phone' => 'phone',
            'email' => 'email',
        ])
        ->call('submitMapping')
        ->set('importCompanyId', $this->company->id)
        ->call('startImport')
        ->assertSet('importStep', 5)
        ->assertSet('importCreated', 1)
        ->assertSet('importUpdated', 1);

    // Existing contact should be updated
    $existing = Contact::where('phone', '+17205551234')->first();
    expect($existing)
        ->first_name->toBe('Updated')
        ->email->toBe('new@example.com');

    // Total contacts for company should be 2
    expect(Contact::where('company_id', $this->company->id)->count())->toBe(2);
});

it('enrolls imported contacts in campaign', function () {
    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();

    $csv = createCsvFile(
        ['first_name', 'phone'],
        [
            ['Alice', '7205551001'],
            ['Bob', '7205551002'],
        ],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', ['first_name' => 'first_name', 'phone' => 'phone'])
        ->call('submitMapping')
        ->set('importCompanyId', $this->company->id)
        ->set('enrollInCampaign', true)
        ->set('importCampaignId', $campaign->id)
        ->call('startImport')
        ->assertSet('importStep', 5)
        ->assertSet('importCreated', 2);

    $enrolledCount = CampaignContact::where('campaign_id', $campaign->id)->count();
    expect($enrolledCount)->toBe(2);

    $campaign->refresh();
    expect($campaign->contact_count)->toBe(2);
});

it('skips rows with missing required phone', function () {
    $csv = createCsvFile(
        ['first_name', 'phone'],
        [
            ['Valid', '7205551234'],
            ['No Phone', ''],
            ['Also Valid', '7205559999'],
        ],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', ['first_name' => 'first_name', 'phone' => 'phone'])
        ->call('submitMapping')
        ->set('importCompanyId', $this->company->id)
        ->call('startImport')
        ->assertSet('importStep', 5)
        ->assertSet('importCreated', 2)
        ->assertSet('importErrorCount', 1);

    expect(Contact::where('company_id', $this->company->id)->count())->toBe(2);
});

it('handles tags column as comma-separated values', function () {
    // Build CSV manually to properly quote the tags field with commas
    $content = "first_name,phone,tags\nJohn,7205551234,\"homeowner, denver, interested\"\n";
    $csv = UploadedFile::fake()->createWithContent('contacts.csv', $content);

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', [
            'first_name' => 'first_name',
            'phone' => 'phone',
            'tags' => 'tags',
        ])
        ->call('submitMapping')
        ->set('importCompanyId', $this->company->id)
        ->call('startImport')
        ->assertSet('importStep', 5)
        ->assertSet('importCreated', 1);

    $contact = Contact::where('phone', '+17205551234')->first();
    expect($contact->tags)->toContain('homeowner')
        ->toContain('denver')
        ->toContain('interested');
});

it('requires company selection before import', function () {
    $csv = createCsvFile(
        ['first_name', 'phone'],
        [['John', '7205551234']],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', ['first_name' => 'first_name', 'phone' => 'phone'])
        ->call('submitMapping')
        ->call('startImport')
        ->assertHasErrors('importCompanyId');
});

it('rejects non-csv files', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $file)
        ->assertHasErrors('csvFile');
});

it('resets state when closing import modal', function () {
    $csv = createCsvFile(
        ['first_name', 'phone'],
        [['John', '7205551234']],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->assertSet('importStep', 2)
        ->call('closeImportModal')
        ->assertSet('importStep', 1)
        ->assertSet('csvHeaders', [])
        ->assertSet('importTotal', 0);
});

it('maps all optional fields correctly', function () {
    $csv = createCsvFile(
        ['first_name', 'last_name', 'phone', 'email', 'address', 'city', 'state', 'zip'],
        [['John', 'Doe', '7205551234', 'john@test.com', '123 Main St', 'Denver', 'CO', '80202']],
    );

    Livewire::test('pages::prospector.contacts')
        ->call('openImportModal')
        ->set('csvFile', $csv)
        ->set('columnMapping', [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'phone' => 'phone',
            'email' => 'email',
            'address' => 'address',
            'city' => 'city',
            'state' => 'state',
            'zip' => 'zip',
        ])
        ->call('submitMapping')
        ->set('importCompanyId', $this->company->id)
        ->call('startImport')
        ->assertSet('importStep', 5)
        ->assertSet('importCreated', 1);

    $contact = Contact::where('phone', '+17205551234')->first();
    expect($contact)
        ->first_name->toBe('John')
        ->last_name->toBe('Doe')
        ->email->toBe('john@test.com')
        ->address->toBe('123 Main St')
        ->city->toBe('Denver')
        ->state->toBe('CO')
        ->zip->toBe('80202');
});
