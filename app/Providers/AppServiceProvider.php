<?php

namespace App\Providers;

use App\Services\DatalogService;
use App\Support\IspContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One acting ISPConfig identity per request (see App\Support\IspContext).
        $this->app->scoped(IspContext::class);

        // Scoped so per-request state (session id grouping, username cache)
        // stays request-local.
        $this->app->scoped(DatalogService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
