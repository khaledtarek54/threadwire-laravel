<?php

namespace Threadwire;

use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Threadwire\Http\Controllers\WebhookController;
use Threadwire\Http\Middleware\VerifyWebhookSignature;

class ThreadwireServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/threadwire.php', 'threadwire');

        $this->app->singleton(ThreadwireClient::class, fn ($app): ThreadwireClient => new ThreadwireClient(
            $app->make(Factory::class),
            $app['config']->get('threadwire.api_key'),
            (string) $app['config']->get('threadwire.url'),
            (int) $app['config']->get('threadwire.timeout', 30),
        ));
    }

    public function boot(Router $router): void
    {
        $this->publishes([__DIR__.'/../config/threadwire.php' => config_path('threadwire.php')], 'threadwire-config');

        $router->aliasMiddleware('threadwire.webhook', VerifyWebhookSignature::class);

        // Outside the "web" group: no session and no CSRF token, which a
        // webhook never has. The signature is its check.
        if (filled($path = config('threadwire.webhook.path')) && ! $this->app->routesAreCached()) {
            Route::post($path, WebhookController::class)
                ->middleware(VerifyWebhookSignature::class)
                ->name('threadwire.webhook');
        }
    }
}
