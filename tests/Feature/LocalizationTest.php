<?php

use App\Http\Middleware\SetLocale;
use App\Models\User;

it('defaults to English locale', function () {
    $this->get('/')
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('switches locale to Spanish via route', function () {
    $response = $this->get(route('locale.switch', 'es'));

    $response->assertRedirect();
    expect(session('locale'))->toBe('es');
});

it('switches locale back to English', function () {
    session(['locale' => 'es']);

    $response = $this->get(route('locale.switch', 'en'));

    $response->assertRedirect();
    expect(session('locale'))->toBe('en');
});

it('rejects unsupported locales', function () {
    $response = $this->get(route('locale.switch', 'fr'));

    $response->assertRedirect();
    expect(session('locale'))->toBeNull();
});

it('persists locale across requests via middleware', function () {
    // Set Spanish locale
    $this->get(route('locale.switch', 'es'));

    // Next request should use Spanish
    $this->get('/');
    expect(app()->getLocale())->toBe('es');
});

it('translates framework strings to Spanish', function () {
    app()->setLocale('es');

    expect(__('Dashboard'))->toBe('Panel');
    expect(__('Settings'))->toBe('Configuración');
    expect(__('Log Out'))->toBe('Cerrar Sesión');
});

it('translates app-specific strings to Spanish', function () {
    app()->setLocale('es');

    expect(__('Active Leads'))->toBe('Prospectos Activos');
    expect(__('Business Plan'))->toBe('Plan de Negocio');
    expect(__('Messages'))->toBe('Mensajes');
    expect(__('Referrals'))->toBe('Referencias');
    expect(__('Jobs'))->toBe('Trabajos');
    expect(__('Customers'))->toBe('Clientes');
});

it('falls back to English key when translation is missing', function () {
    app()->setLocale('es');

    expect(__('Some string without translation'))->toBe('Some string without translation');
});

it('shows language switcher on internal layout', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('locale.switch', 'es'));
});

it('shows language switcher on customer layout', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer')
        ->get(route('customer.dashboard'))
        ->assertOk()
        ->assertSee(route('locale.switch', 'es'));
});

it('shows language switcher on login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(route('locale.switch', 'es'));
});

it('renders internal dashboard in Spanish when locale is set', function () {
    session(['locale' => 'es']);
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Panel', false); // "Dashboard" → "Panel"
});

it('has supported locales constant on middleware', function () {
    expect(SetLocale::SUPPORTED_LOCALES)->toBe(['en', 'es']);
});

it('handles locale parameter replacement in Spanish', function () {
    app()->setLocale('es');

    expect(__('Welcome back, :name!', ['name' => 'Juan']))->toBe('¡Bienvenido de nuevo, Juan!');
});
