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
        ->middleware('permission:manage roles')
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
});
