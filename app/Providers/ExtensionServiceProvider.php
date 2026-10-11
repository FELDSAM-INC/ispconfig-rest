<?php

namespace App\Providers;

use App\Extensions\ExtensionRegistry;
use App\Extensions\Hooks;
use Illuminate\Support\ServiceProvider;

/**
 * Loads the installed extensions (spec 058) and offers them the hook points of App\Extensions\Hooks.
 */
class ExtensionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Hooks::class);
        $this->app->singleton(ExtensionRegistry::class, fn ($app) => new ExtensionRegistry(
            (string) config('api.extensions_path'),
            trim((string) @file_get_contents($app->basePath('VERSION'))) ?: '0.0.0'
        ));

        $this->app->make(ExtensionRegistry::class)->boot($this->app);
    }
}
