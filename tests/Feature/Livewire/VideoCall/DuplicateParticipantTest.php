<?php

use App\Models\User;

it('renders the video call page for an authenticated user', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('video-call.test'))
        ->assertSuccessful();
});

it('coerces userId to string on initialization to prevent self-connection', function () {
    $user = User::factory()->internal()->create();

    $response = $this->actingAs($user)
        ->get(route('video-call.test'));

    $response->assertSuccessful()
        ->assertSee('userId: String(config.userId)', false);
});

it('uses String() coercion when filtering lobby peers', function () {
    $user = User::factory()->internal()->create();

    $response = $this->actingAs($user)
        ->get(route('video-call.test'));

    $response->assertSuccessful()
        ->assertSee('String(u.id) !== this.userId', false);
});

it('uses String() coercion when iterating channel members', function () {
    $user = User::factory()->internal()->create();

    $response = $this->actingAs($user)
        ->get(route('video-call.test'));

    $response->assertSuccessful()
        ->assertSee('String(member.id) !== this.userId', false);
});

it('uses numeric comparison for polite peer pattern in handleOffer', function () {
    $user = User::factory()->internal()->create();

    $response = $this->actingAs($user)
        ->get(route('video-call.test'));

    $content = $response->getContent();

    $response->assertSuccessful();

    // The < character may be HTML-encoded in the rendered output,
    // so check for both the raw and encoded variants
    expect(
        str_contains($content, 'Number(this.userId) < Number(peerId)')
        || str_contains($content, 'Number(this.userId) &lt; Number(peerId)')
    )->toBeTrue();
});
