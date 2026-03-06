<?php

use App\Models\Corporation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo(['business_plan.view', 'business_plan.manage']);
    $this->actingAs($this->user);

    $this->corporation = Corporation::factory()->create(['is_active' => true]);
});

it('renders the document manager component', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->assertStatus(200)
        ->assertSee('Documents')
        ->assertSee('Upload');
});

it('toggles the upload form', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->assertSet('showUploadForm', false)
        ->assertDontSee('Upload Document')
        ->call('toggleUploadForm')
        ->assertSet('showUploadForm', true)
        ->assertSee('Upload Document')
        ->assertSee('Title')
        ->assertSee('PDF File');
});

it('uploads a document successfully', function () {
    Storage::fake();
    Queue::fake();

    $file = UploadedFile::fake()->create('test-document.pdf', 1024, 'application/pdf');

    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->call('toggleUploadForm')
        ->set('uploadTitle', 'Test Operating Agreement')
        ->set('uploadType', 'operating_agreement')
        ->set('uploadFile', $file)
        ->call('saveDocument')
        ->assertSet('showUploadForm', false);

    $this->assertDatabaseHas('corporate_documents', [
        'corporation_id' => $this->corporation->id,
        'title' => 'Test Operating Agreement',
        'type' => 'operating_agreement',
        'status' => 'processing',
    ]);
});

it('validates required fields on upload', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->call('toggleUploadForm')
        ->call('saveDocument')
        ->assertHasErrors(['uploadTitle', 'uploadFile']);
});
