<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Models\OverheadEntry;
use App\Models\Payment;
use App\Observers\InvoiceObserver;
use App\Observers\OverheadEntryObserver;
use App\Observers\PaymentObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Payment::observe(PaymentObserver::class);
        OverheadEntry::observe(OverheadEntryObserver::class);
        Invoice::observe(InvoiceObserver::class);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
