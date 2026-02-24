<?php

use App\Models\MessengerSetting;

it('renders messenger widget when enabled with page_id', function () {
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'website_widget_enabled' => true,
        'page_id' => '123456789',
        'widget_theme_color' => '#ff6600',
    ]);

    $response = $this->get('/');
    $response->assertSee('fb-customer-chat', false);
    $response->assertSee('123456789', false);
});

it('does not render messenger widget when disabled', function () {
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'website_widget_enabled' => false,
        'page_id' => '123456789',
    ]);

    $response = $this->get('/');
    $response->assertDontSee('fb-customer-chat', false);
});

it('does not render messenger widget when page_id is missing', function () {
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'website_widget_enabled' => true,
        'page_id' => null,
    ]);

    $response = $this->get('/');
    $response->assertDontSee('fb-customer-chat', false);
});

it('renders widget with custom theme color', function () {
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'website_widget_enabled' => true,
        'page_id' => '987654321',
        'widget_theme_color' => '#ff6600',
    ]);

    $response = $this->get('/');
    $response->assertSee('theme_color="#ff6600"', false);
});

it('renders widget with custom greetings', function () {
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'website_widget_enabled' => true,
        'page_id' => '123456789',
        'widget_greeting_logged_in' => 'Welcome back!',
        'widget_greeting_logged_out' => 'Hello visitor!',
    ]);

    $response = $this->get('/');
    $response->assertSee('Welcome back!', false);
    $response->assertSee('Hello visitor!', false);
});

it('renders widget partial correctly via blade view', function () {
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'website_widget_enabled' => true,
        'page_id' => '111222333',
        'widget_theme_color' => '#0084ff',
    ]);

    $html = view('partials.messenger-widget')->render();

    expect($html)->toContain('fb-customer-chat');
    expect($html)->toContain('111222333');
    expect($html)->toContain('xfbml.customerchat.js');
});

it('renders empty partial when widget is disabled', function () {
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'website_widget_enabled' => false,
        'page_id' => '111222333',
    ]);

    $html = view('partials.messenger-widget')->render();

    expect(trim($html))->not->toContain('fb-customer-chat');
});
