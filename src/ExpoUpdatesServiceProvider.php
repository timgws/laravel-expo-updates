<?php

namespace LaravelExpoUpdates;

use Illuminate\Support\ServiceProvider;
use LaravelExpoUpdates\Contracts\AssetInterface;
use LaravelExpoUpdates\Contracts\ManifestInterface;
use LaravelExpoUpdates\Contracts\ProjectInterface;
use LaravelExpoUpdates\Contracts\UpdateStatInterface;
use LaravelExpoUpdates\Http\Controllers\ExpoUpdatesController;
use LaravelExpoUpdates\Http\Controllers\UploadController;
use LaravelExpoUpdates\Http\Middleware\AuthenticateExpoUploadRequest;
use LaravelExpoUpdates\Http\Middleware\TrackUpdateRequests;
use LaravelExpoUpdates\Http\Middleware\ValidateExpoRequest;
use LaravelExpoUpdates\Services\AssetService;
use LaravelExpoUpdates\Services\ManifestService;
use LaravelExpoUpdates\Services\StatsService;
use LaravelExpoUpdates\Console\Commands\FixUniqueConstraint;

/**
 * Service provider for the Laravel Expo Updates package.
 */
class ExpoUpdatesServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/expo-updates.php', 'expo-updates'
        );

        $this->app->bind(ProjectInterface::class, function ($app) {
            $model = config('expo-updates.models.project');
            return new $model();
        });

        $this->app->bind(ManifestInterface::class, function ($app) {
            $model = config('expo-updates.models.manifest');
            return new $model();
        });

        $this->app->bind(AssetInterface::class, function ($app) {
            $model = config('expo-updates.models.asset');
            return new $model();
        });

        $this->app->bind(UpdateStatInterface::class, function ($app) {
            $model = config('expo-updates.models.update_stat');
            return new $model();
        });

        $this->app->singleton(ManifestService::class);
        $this->app->singleton(AssetService::class);
        $this->app->singleton(StatsService::class);
    }

    /**
     * Bootstrap application services.
     * Add routes for laravel-expo-update
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/expo-updates.php' => config_path('expo-updates.php'),
        ], 'config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                FixUniqueConstraint::class,
            ]);
        }

        $this->app['router']->aliasMiddleware('expo.validate', ValidateExpoRequest::class);
        $this->app['router']->aliasMiddleware('expo.track', TrackUpdateRequests::class);
        $this->app['router']->aliasMiddleware('expo.upload-auth', AuthenticateExpoUploadRequest::class);

        $prefix = config('expo-updates.route_prefix');

        // Project-specific routes
        $this->app['router']->group(['prefix' => $prefix.'/{projectSlug}', 'middleware' => ['expo.validate', 'expo.track']], function ($router) {
            $router->get('manifest', [ExpoUpdatesController::class, 'manifest']);
            $router->get('asset/{key}', [ExpoUpdatesController::class, 'asset']);
            $router->post('upload', [UploadController::class, 'upload'])
                ->middleware('expo.upload-auth');
        });

        // Default routes (will use project from header or config)
        $this->app['router']->group(['prefix' => $prefix, 'middleware' => ['expo.validate', 'expo.track']], function ($router) {
            $router->get('manifest', [ExpoUpdatesController::class, 'manifest']);
            $router->get('asset/{key}', [ExpoUpdatesController::class, 'asset']);
            $router->post('upload', [UploadController::class, 'upload'])
                ->middleware('expo.upload-auth');
        });
    }
} 