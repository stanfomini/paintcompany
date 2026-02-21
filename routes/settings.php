<?php

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
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
    Route::livewire('settings/roles/{roleName}', 'pages::settings.role-detail')
        ->middleware('permission:team.manage_roles')
        ->name('roles.detail');

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

    Route::livewire('settings/workflows', 'pages::settings.workflows')
        ->middleware('permission:settings.workflows')
        ->name('workflows.edit');

    Route::livewire('settings/workflows/{workflow}/builder', 'pages::settings.workflow-builder')
        ->middleware('permission:settings.workflows')
        ->name('workflows.builder');

    Route::livewire('settings/vendors', 'pages::settings.vendors')
        ->middleware('permission:vendors.view')
        ->name('vendors.edit');

    Route::get('settings/marketing-flyer', function () {
        $renderer = new ImageRenderer(new RendererStyle(300), new ImagickImageBackEnd);
        $qrPng = (new Writer($renderer))->writeString('https://paintingmadeeasydenver.com');
        $qrCodeDataUri = 'data:image/png;base64,'.base64_encode($qrPng);

        $pdf = Pdf::loadView('pdf.marketing-flyer', [
            'qrCodeDataUri' => $qrCodeDataUri,
        ])->setPaper('letter');

        return $pdf->download('painting-made-easy-flyer.pdf');
    })
        ->middleware('permission:settings.company')
        ->name('marketing-flyer.show');
});
