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
    Route::livewire('settings/notification-preferences', 'pages::settings.notification-preferences')->name('notification-preferences.edit');
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

    Route::livewire('settings/commissions', 'pages::settings.commissions')
        ->middleware('permission:payroll.manage')
        ->name('commissions.edit');

    Route::livewire('settings/email-accounts', 'pages::settings.email-accounts')
        ->middleware('permission:email.manage')
        ->name('email-accounts.edit');

    Route::livewire('settings/vendors', 'pages::settings.vendors')
        ->middleware('permission:vendors.view')
        ->name('vendors.edit');

    Route::middleware('permission:vendors.manage')->group(function () {
        Route::get('settings/vendors/documents/{vendorDocument}/download', [\App\Http\Controllers\VendorDocumentController::class, 'download'])
            ->name('vendors.documents.download');
        Route::get('settings/vendors/documents/{vendorDocument}/view', [\App\Http\Controllers\VendorDocumentController::class, 'view'])
            ->name('vendors.documents.view');
        Route::get('settings/vendors/{vendor}/contracts/{contract}/pdf', [\App\Http\Controllers\VendorContractPdfController::class, 'download'])
            ->name('vendor-contracts.pdf.download');
        Route::livewire('settings/vendors/{vendor}/contracts/{contract}/edit', 'pages::settings.vendor-contract-editor')
            ->name('vendor-contracts.edit');
        Route::livewire('settings/vendors/{vendor}/contracts/{contract}/sign', 'pages::settings.vendor-contract-sign-internal')
            ->name('vendor-contracts.sign-internal');
    });

    Route::livewire('settings/messenger', 'pages::settings.messenger-settings')
        ->middleware('permission:messenger.manage')
        ->name('messenger.edit');

    Route::livewire('settings/messenger/flows/create', 'pages::settings.bot-flow-builder')
        ->middleware('permission:messenger.manage')
        ->name('messenger.flows.create');

    Route::livewire('settings/messenger/flows/{flow}/edit', 'pages::settings.bot-flow-builder')
        ->middleware('permission:messenger.manage')
        ->name('messenger.flows.edit');

    Route::get('settings/messenger/connect', [\App\Http\Controllers\FacebookOAuthController::class, 'redirect'])
        ->middleware('permission:messenger.manage')
        ->name('facebook.oauth.redirect');

    Route::get('settings/messenger/callback', [\App\Http\Controllers\FacebookOAuthController::class, 'callback'])
        ->middleware('permission:messenger.manage')
        ->name('facebook.oauth.callback');

    Route::livewire('settings/booking', 'pages::settings.booking-config')
        ->middleware('permission:settings.company')
        ->name('settings.booking');

    Route::livewire('settings/color-visualizer', 'pages::settings.color-visualizer')
        ->middleware('permission:settings.company')
        ->name('settings.color-visualizer');

    Route::livewire('settings/phone-system', 'pages::settings.phone-system')
        ->middleware('permission:settings.phone_system')
        ->name('settings.phone-system');

    Route::livewire('settings/sms-bot', 'pages::settings.sms-bot')
        ->middleware('permission:settings.company')
        ->name('settings.sms-bot');

    Route::livewire('settings/sms-bot/flows/create', 'pages::settings.sms-bot-flow-builder')
        ->middleware('permission:settings.company')
        ->name('settings.sms-bot.flows.create');

    Route::livewire('settings/sms-bot/flows/{flow}/edit', 'pages::settings.sms-bot-flow-builder')
        ->middleware('permission:settings.company')
        ->name('settings.sms-bot.flows.edit');

    Route::livewire('settings/ai-estimator', 'pages::settings.ai-estimator')
        ->middleware('permission:ai.estimate_builder')
        ->name('settings.ai-estimator');

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
