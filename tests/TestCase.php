<?php

namespace Rishadblack\SyllooAppBase\Tests;

use Illuminate\Support\ServiceProvider;
use Jantinnerezo\LivewireAlert\LivewireAlertServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Rishadblack\SyllooAppBase\Rest\WaitingList;
use Rishadblack\SyllooAppBase\SyllooAppBaseServiceProvider;
use Rishadblack\WireBootstrap\WireBootstrapServiceProvider;
use Rishadblack\WireModals\WireModalsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        WaitingList::forgetAllowed();
    }

    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            WireBootstrapServiceProvider::class,
            WireModalsServiceProvider::class,
            LivewireAlertServiceProvider::class,
            SyllooAppBaseServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('logging.default', 'null');
        $app['config']->set('nativephp.version', '2.0.0');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('sylloo-app-base.app.name', 'Sylloo App');
    }
}
