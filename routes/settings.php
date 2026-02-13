<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/password', 'pages::settings.password')->name('user-password.edit');
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');
    Route::livewire('settings/company', 'pages::settings.company')
        ->middleware('permission:settings.company')
        ->name('company.edit');
    Route::livewire('settings/signature', 'pages::settings.signature')->name('signature.edit');
    Route::livewire('settings/packages', 'pages::settings.packages')
        ->middleware('permission:settings.packages')
        ->name('packages.edit');
    Route::livewire('settings/allocations', 'pages::settings.allocations')
        ->middleware('permission:settings.allocations')
        ->name('allocations.edit');
    Route::livewire('settings/roles', 'pages::settings.roles')
        ->middleware('permission:team.manage_roles')
        ->name('roles.edit');

    Route::livewire('settings/two-factor', 'pages::settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');

    Route::livewire('settings/payment-integrations', 'pages::settings.payment-integrations')
        ->middleware('permission:settings.payment_integrations')
        ->name('payment-integrations.edit');

    Route::livewire('settings/service-categories', 'pages::settings.service-categories')
        ->middleware('permission:settings.service_categories')
        ->name('service-categories.edit');

    Route::livewire('settings/budget-mappings', 'pages::settings.budget-mappings')
        ->middleware('permission:settings.budget_mappings')
        ->name('budget-mappings.edit');

    Route::livewire('settings/referral', 'pages::settings.referral')
        ->middleware('permission:settings.referral')
        ->name('referral.edit');

    Route::livewire('settings/contracts', 'pages::settings.contracts')
        ->middleware('permission:settings.contracts')
        ->name('contracts.edit');

    Route::livewire('settings/lead-sources', 'pages::settings.lead-sources')
        ->middleware('permission:settings.lead_sources')
        ->name('lead-sources.edit');
});
