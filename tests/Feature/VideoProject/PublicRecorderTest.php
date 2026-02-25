<?php

use App\Models\RecordingInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('loads public recorder page for valid invite', function () {
    $this->withoutVite();
    $invite = RecordingInvite::factory()->create(['expires_at' => now()->addDay()]);

    $this->get(route('recording.invite', $invite->token))
        ->assertSuccessful()
        ->assertSee($invite->title);
});

it('shows 404 for invalid token', function () {
    $this->withoutVite();

    $this->get(route('recording.invite', 'invalid-token'))
        ->assertNotFound();
});

it('shows 410 for expired invite', function () {
    $this->withoutVite();
    $invite = RecordingInvite::factory()->create(['expires_at' => now()->subHour()]);

    $this->get(route('recording.invite', $invite->token))
        ->assertStatus(410);
});

it('shows completed state for already recorded invite', function () {
    $this->withoutVite();
    $invite = RecordingInvite::factory()->completed()->create();

    $this->get(route('recording.invite', $invite->token))
        ->assertSuccessful();
});
