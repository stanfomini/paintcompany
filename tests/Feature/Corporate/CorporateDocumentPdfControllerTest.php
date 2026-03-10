<?php

use App\Models\CompanySetting;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\MeetingMinutes;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());

    $this->settings = CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
    $this->corporation = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
});

// -- Authentication & Authorization --

it('requires authentication to download a document pdf', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->create();

    $this->get(route('corporate.document.pdf', $doc))
        ->assertRedirect(route('login'));
});

it('requires business_plan.view permission to download document pdf', function () {
    $user = User::factory()->internal()->create();
    $doc = CorporateDocument::factory()->for($this->corporation)->create();

    $this->actingAs($user)
        ->get(route('corporate.document.pdf', $doc))
        ->assertForbidden();
});

it('requires authentication to download meeting minutes pdf', function () {
    $meeting = MeetingMinutes::factory()->for($this->corporation)->create();

    $this->get(route('corporate.minutes.pdf', $meeting))
        ->assertRedirect(route('login'));
});

it('requires business_plan.view permission to download minutes pdf', function () {
    $user = User::factory()->internal()->create();
    $meeting = MeetingMinutes::factory()->for($this->corporation)->create();

    $this->actingAs($user)
        ->get(route('corporate.minutes.pdf', $meeting))
        ->assertForbidden();
});

it('requires authentication to download packet', function () {
    $this->get(route('corporate.packet.download'))
        ->assertRedirect(route('login'));
});

it('requires business_plan.view permission to download packet', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('corporate.packet.download'))
        ->assertForbidden();
});

it('requires authentication to send packet', function () {
    $this->post(route('corporate.packet.send'))
        ->assertRedirect(route('login'));
});

it('requires business_plan.manage permission to send packet', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->post(route('corporate.packet.send'))
        ->assertForbidden();
});

// -- View Original PDF --

it('requires authentication to view original pdf', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->create();

    $this->get(route('corporate.document.view', $doc))
        ->assertRedirect(route('login'));
});

it('requires business_plan.view permission to view original pdf', function () {
    $user = User::factory()->internal()->create();
    $doc = CorporateDocument::factory()->for($this->corporation)->create();

    $this->actingAs($user)
        ->get(route('corporate.document.view', $doc))
        ->assertForbidden();
});

it('falls back to generated pdf when original file does not exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $doc = CorporateDocument::factory()->for($this->corporation)->withParsedContent()->create([
        'original_file_path' => 'nonexistent/path.pdf',
    ]);

    $this->actingAs($user)
        ->get(route('corporate.document.view', $doc))
        ->assertSuccessful();
});

it('streams the original pdf inline for viewing', function () {
    \Illuminate\Support\Facades\Storage::fake();
    \Illuminate\Support\Facades\Storage::put('corporate-documents/test.pdf', 'fake-pdf-content');

    $user = User::factory()->internal()->withFullAccess()->create();
    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'original_file_path' => 'corporate-documents/test.pdf',
    ]);

    $response = $this->actingAs($user)
        ->get(route('corporate.document.view', $doc));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'application/pdf');
});

it('downloads the original pdf when available', function () {
    \Illuminate\Support\Facades\Storage::fake();
    \Illuminate\Support\Facades\Storage::put('corporate-documents/test.pdf', 'fake-pdf-content');

    $user = User::factory()->internal()->withFullAccess()->create();
    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'original_file_path' => 'corporate-documents/test.pdf',
    ]);

    $response = $this->actingAs($user)
        ->get(route('corporate.document.pdf', $doc));

    $response->assertSuccessful();
});

// -- Packet Validation --

it('validates send packet requires email and subject', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('corporate.packet.send'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'subject']);
});

it('validates send packet email must be valid', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('corporate.packet.send'), [
            'email' => 'not-an-email',
            'subject' => 'Test',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('validates document_ids must exist in database', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('corporate.packet.send'), [
            'email' => 'test@example.com',
            'subject' => 'Test',
            'document_ids' => [99999],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document_ids.0']);
});

it('validates minutes_ids must exist in database', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('corporate.packet.send'), [
            'email' => 'test@example.com',
            'subject' => 'Test',
            'minutes_ids' => [99999],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['minutes_ids.0']);
});

// -- Send Packet --

it('sends a packet email with valid data', function () {
    \Illuminate\Support\Facades\Mail::fake();

    $user = User::factory()->internal()->withFullAccess()->create();
    $doc = CorporateDocument::factory()->for($this->corporation)->withParsedContent()->create();

    $this->actingAs($user)
        ->postJson(route('corporate.packet.send'), [
            'email' => 'recipient@example.com',
            'subject' => 'Your Corporate Documents',
            'document_ids' => [$doc->id],
        ])
        ->assertSuccessful()
        ->assertJson(['success' => true]);
});

// -- Merged Packet PDF Tests --

function createRealPdf(string $text = 'Test PDF'): string
{
    $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'pt');
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 20, $text);

    return $pdf->Output('', 'S');
}

it('downloads a single document as PDF directly', function () {
    \Illuminate\Support\Facades\Storage::fake();

    $pdfContent = createRealPdf('Document Content');
    \Illuminate\Support\Facades\Storage::put('corporate-documents/real.pdf', $pdfContent);

    $user = User::factory()->internal()->withFullAccess()->create();
    $doc = CorporateDocument::factory()->for($this->corporation)->withParsedContent()->create([
        'original_file_path' => 'corporate-documents/real.pdf',
    ]);

    $response = $this->actingAs($user)
        ->get(route('corporate.packet.download').'?'.http_build_query([
            'document_ids' => [$doc->id],
        ]));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'application/pdf');

    // Single document returns PDF directly
    expect(str_starts_with($response->getContent(), '%PDF'))->toBeTrue();
});

it('falls back to DomPDF when stored file is missing in packet', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $doc = CorporateDocument::factory()->for($this->corporation)->withParsedContent()->create([
        'original_file_path' => 'nonexistent/missing.pdf',
    ]);

    $response = $this->actingAs($user)
        ->get(route('corporate.packet.download').'?'.http_build_query([
            'document_ids' => [$doc->id],
        ]));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($response->getContent(), '%PDF'))->toBeTrue();
});

it('includes meeting minutes in merged packet', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $minutes = MeetingMinutes::factory()->for($this->corporation)->withSummary()->create();

    $response = $this->actingAs($user)
        ->get(route('corporate.packet.download').'?'.http_build_query([
            'minutes_ids' => [$minutes->id],
        ]));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($response->getContent(), '%PDF'))->toBeTrue();
});

it('downloads multiple documents as a zip file', function () {
    \Illuminate\Support\Facades\Storage::fake();

    $pdf1 = createRealPdf('First Document');
    $pdf2 = createRealPdf('Second Document');
    \Illuminate\Support\Facades\Storage::put('corporate-documents/doc1.pdf', $pdf1);
    \Illuminate\Support\Facades\Storage::put('corporate-documents/doc2.pdf', $pdf2);

    $user = User::factory()->internal()->withFullAccess()->create();
    $doc1 = CorporateDocument::factory()->for($this->corporation)->create([
        'original_file_path' => 'corporate-documents/doc1.pdf',
    ]);
    $doc2 = CorporateDocument::factory()->for($this->corporation)->create([
        'original_file_path' => 'corporate-documents/doc2.pdf',
    ]);

    $response = $this->actingAs($user)
        ->get(route('corporate.packet.download').'?'.http_build_query([
            'document_ids' => [$doc1->id, $doc2->id],
        ]));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'application/zip');

    // Verify output starts with ZIP magic bytes (PK)
    expect(str_starts_with($response->getContent(), 'PK'))->toBeTrue();
});

it('sends email with individual PDF attachments', function () {
    \Illuminate\Support\Facades\Mail::fake();
    \Illuminate\Support\Facades\Storage::fake();

    $pdf1 = createRealPdf('First Attachment');
    $pdf2 = createRealPdf('Second Attachment');
    \Illuminate\Support\Facades\Storage::put('corporate-documents/doc1.pdf', $pdf1);
    \Illuminate\Support\Facades\Storage::put('corporate-documents/doc2.pdf', $pdf2);

    $user = User::factory()->internal()->withFullAccess()->create();
    $doc1 = CorporateDocument::factory()->for($this->corporation)->create([
        'title' => 'Bylaws',
        'original_file_path' => 'corporate-documents/doc1.pdf',
    ]);
    $doc2 = CorporateDocument::factory()->for($this->corporation)->create([
        'title' => 'Articles',
        'original_file_path' => 'corporate-documents/doc2.pdf',
    ]);

    $this->actingAs($user)
        ->postJson(route('corporate.packet.send'), [
            'email' => 'recipient@example.com',
            'subject' => 'Your Documents',
            'message' => 'Here are your documents.',
            'document_ids' => [$doc1->id, $doc2->id],
        ])
        ->assertSuccessful()
        ->assertJson(['success' => true]);
});
