<?php
namespace SoftlogicGT\FacGateway;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class FacGatewayServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot(Router $router)
    {
        $this->mergeConfigFrom(__DIR__ . '/config/laravel-facgateway.php', 'laravel-facgateway');
        $this->loadViewsFrom(__DIR__ . '/resources/views/', 'laravel-facgateway');
        $this->publishes([
            __DIR__ . '/config/laravel-facgateway.php' => config_path('laravel-facgateway.php'),
        ], 'config');
    }

    public function register()
    {
        $this->app->singleton('laravel-fac-gateway', function ($app) {
            return new FacGateway;
        });
    }

    public function provides()
    {
        return ['laravel-fac-gateway'];
    }
}
