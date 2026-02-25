<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\OverheadEntry;
use App\Models\Payment;
use App\Models\User;
use App\Observers\CustomerObserver;
use App\Observers\InvoiceObserver;
use App\Observers\OverheadEntryObserver;
use App\Observers\PaymentObserver;
use App\Observers\UserObserver;
use App\Observers\WorkflowModelObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        $this->configureMorphMap();

        Payment::observe(PaymentObserver::class);
        OverheadEntry::observe(OverheadEntryObserver::class);
        Invoice::observe(InvoiceObserver::class);
        User::observe(UserObserver::class);
        Customer::observe(CustomerObserver::class);

        // Workflow engine observer — fires on model lifecycle events
        $workflowModels = [Deal::class, Job::class, Estimate::class, Invoice::class, DoorKnock::class, Payment::class];
        foreach ($workflowModels as $model) {
            $model::observe(WorkflowModelObserver::class);
        }

        Event::listen(
            \Illuminate\Auth\Events\Login::class,
            \App\Listeners\NotifyOnCustomerLogin::class
        );

        // Tag all outgoing notification emails with headers to prevent feedback loops
        Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            \App\Listeners\TagNotificationEmails::class
        );
    }

    protected function configureMorphMap(): void
    {
        Relation::morphMap([
            'video-recording' => \App\Models\VideoRecording::class,
            'property-photo' => \App\Models\PropertyPhoto::class,
        ]);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            ! app()->runningUnitTests(),
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
