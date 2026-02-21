<?php

use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the company phone number from CompanySetting', function () {
    CompanySetting::getInstance()->update([
        'company_phone' => '(303) 555-1234',
    ]);

    Livewire::test('public.landing-page')
        ->assertSee('(303) 555-1234');
});

it('renders all four login portal links with correct URLs', function () {
    CompanySetting::getInstance();

    Livewire::test('public.landing-page')
        ->assertSee('Team Portal')
        ->assertSee('Agent Portal')
        ->assertSee('Customer Portal')
        ->assertSee('Crew Portal')
        ->assertSeeHtml('href="'.route('login').'"')
        ->assertSeeHtml('href="'.route('agent.login').'"')
        ->assertSeeHtml('href="'.route('customer.login').'"')
        ->assertSeeHtml('href="'.route('crew.login').'"');
});

it('renders the click-to-call tel link', function () {
    CompanySetting::getInstance()->update([
        'company_phone' => '(303) 555-1234',
    ]);

    Livewire::test('public.landing-page')
        ->assertSeeHtml('href="tel:(303) 555-1234"');
});
