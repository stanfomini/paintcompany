<?php

use App\Models\ProjectPreview;
use App\Models\User;

it('stores preview token in session on login with valid return URL', function () {
    $user = User::factory()->customer()->create();
    $preview = ProjectPreview::factory()->create();

    $response = $this->post(route('customer.login').'?return=/preview/'.$preview->public_token, [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('customer.dashboard'));
    expect(session('preview_return_token'))->toBe($preview->public_token);
});

it('ignores invalid return URL and redirects to dashboard', function () {
    $user = User::factory()->customer()->create();

    $response = $this->post(route('customer.login').'?return=https://evil.com/phish', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('customer.dashboard'));
    expect(session('preview_return_token'))->toBeNull();
});

it('ignores return URL that does not match preview pattern', function () {
    $user = User::factory()->customer()->create();

    $response = $this->post(route('customer.login').'?return=/admin/dashboard', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('customer.dashboard'));
    expect(session('preview_return_token'))->toBeNull();
});

it('passes return param to login form view', function () {
    $response = $this->get(route('customer.login').'?return=/preview/test-token-1234');

    $response->assertOk()
        // The return URL is URL-encoded in the form action
        ->assertSee('return=', false);
});

it('passes return param through form action URL', function () {
    $response = $this->get(route('customer.login').'?return=/preview/some-token');

    $response->assertOk()
        ->assertSee('return=', false);
});

it('handles login with full app URL in return param', function () {
    $user = User::factory()->customer()->create();
    $preview = ProjectPreview::factory()->create();

    $fullUrl = config('app.url').'/preview/'.$preview->public_token;

    $response = $this->post(route('customer.login').'?return='.urlencode($fullUrl), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('customer.dashboard'));
    expect(session('preview_return_token'))->toBe($preview->public_token);
});
