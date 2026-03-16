<?php

namespace App\Providers;

use App\Ai\Gateway\XaiImageEditGateway;
use App\Ai\Gateway\XaiTextGateway;
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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Ai;

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
        $this->configureAiGateways();
        $this->configureRateLimiting();

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

    protected function configureAiGateways(): void
    {
        // AiManager is registered as "scoped" — it gets flushed after each
        // queue job. Ai::extend() only registers on the current instance,
        // so subsequent jobs lose the custom creator. Instead, use the
        // container's resolving callback to apply our override every time
        // a new AiManager is created.
        $this->app->resolving(\Laravel\Ai\AiManager::class, function (\Laravel\Ai\AiManager $manager, $app) {
            $manager->extend('xai', function ($app, array $config) {
                $provider = new \Laravel\Ai\Providers\XaiProvider(
                    new \Laravel\Ai\Gateway\Prism\PrismGateway($app['events']),
                    $config,
                    $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
                );

                return $provider
                    ->useTextGateway(new XaiTextGateway)
                    ->useImageGateway(new XaiImageEditGateway);
            });
        });
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('ai-workflow', function ($request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
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
