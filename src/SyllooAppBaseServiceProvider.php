<?php

namespace Rishadblack\SyllooAppBase;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Rishadblack\SyllooAppBase\Console\InstallCommand;
use Rishadblack\SyllooAppBase\Console\SignConfigCommand;
use Rishadblack\SyllooAppBase\Console\SignUpdateCommand;
use Rishadblack\SyllooAppBase\Contracts\DeviceEntries;
use Rishadblack\SyllooAppBase\Contracts\ScreenNotices;
use Rishadblack\SyllooAppBase\Device\SingleDeviceEntries;
use Rishadblack\SyllooAppBase\Device\StateRegistry;
use Rishadblack\SyllooAppBase\Http\Middleware\RequireDeviceReady;
use Rishadblack\SyllooAppBase\Http\Middleware\UseChosenLanguage;
use Rishadblack\SyllooAppBase\Livewire\Modals\AboutModal;
use Rishadblack\SyllooAppBase\Livewire\Modals\LanguageModal;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooAppBase\Screens\Notices\AlertScreenNotices;
use Rishadblack\SyllooAppBase\Screens\Notices\EventScreenNotices;

/**
 * The shared base of the Sylloo apps (Sylloo POS, the desktop app, the mobile app): config (`erp`, `sylloo-app-base`),
 * texts (`sylloo-app-base::device.*` in en, bn, fi), the shared screens' web faces and routes, the `sylloo.device` and
 * `sylloo.language` middleware, the waiting-list and saved-data tables, and `php artisan sylloo-app-base:install`.
 *
 * Each app binds its own storage in its own service provider: Contracts\DeviceStore, Contracts\MemberDirectory and
 * Contracts\ReleaseSteps (one business), or Contracts\DeviceEntries (several); optionally Contracts\BrowserOpener,
 * Contracts\AppUpdater, Contracts\Notifier, Contracts\SecretStore.
 */
class SyllooAppBaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/erp.php', 'erp');
        $this->mergeConfigFrom(__DIR__.'/../config/sylloo-app-base.php', 'sylloo-app-base');

        $this->app->singleton(StateRegistry::class);
        $this->app->singleton(Hooks::class);
        $this->app->singleton(Presence::class);
        $this->app->bindIf(DeviceEntries::class, SingleDeviceEntries::class, true);
        $this->app->bindIf(ScreenNotices::class, fn () => config('sylloo-app-base.screens.notices') === 'events' ? new EventScreenNotices : new AlertScreenNotices);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sylloo-app-base');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'sylloo-app-base');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'sylloo-app-base');

        if (config('sylloo-app-base.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('sylloo.device', RequireDeviceReady::class);
        $router->aliasMiddleware('sylloo.language', UseChosenLanguage::class);

        if (config('sylloo-app-base.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        Livewire::component('sylloo-app-base.about', AboutModal::class);
        Livewire::component('sylloo-app-base.language', LanguageModal::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/sylloo-app-base.php' => config_path('sylloo-app-base.php')], 'sylloo-app-base-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/sylloo-app-base')], 'sylloo-app-base-views');
            $this->publishes([__DIR__.'/../resources/lang' => lang_path('vendor/sylloo-app-base')], 'sylloo-app-base-lang');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'sylloo-app-base-migrations');

            $this->commands([InstallCommand::class]);

            // Release signing (base 1.1.0): an app that still keeps its own copies sets `release.commands` false.
            if (config('sylloo-app-base.release.commands', true)) {
                $this->commands([SignUpdateCommand::class, SignConfigCommand::class]);
            }
        }
    }
}
