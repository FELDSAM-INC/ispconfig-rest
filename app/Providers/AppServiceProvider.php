<?php

namespace App\Providers;

use App\Services\AliasServicesService;
use App\Services\DatalogService;
use App\Support\IspContext;
use App\Support\ProblemTypeCollector;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AliasServicesService::class);
        $this->app->rebinding('request', fn ($app) => $app->forgetInstance(AliasServicesService::class));

        // One acting ISPConfig identity per request (see App\Support\IspContext).
        $this->app->scoped(IspContext::class);

        // Scoped so per-request state (session id grouping, username cache)
        // stays request-local.
        $this->app->scoped(DatalogService::class);

        // Field problem types of one request (spec 023). Scoped instances are
        // not flushed between requests handled by one application (tests),
        // so every new request starts with an empty collector.
        $this->app->scoped(ProblemTypeCollector::class);
        $this->app->rebinding('request', fn ($app) => $app->forgetInstance(ProblemTypeCollector::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
