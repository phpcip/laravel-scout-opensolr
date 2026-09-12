<?php

namespace Opensolr\ScoutOpensolr;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

/**
 * Registers the "opensolr" Scout engine.
 *
 * The engine factory closure must not touch $this: since Laravel 13, Manager::extend() binds
 * custom driver callbacks to the manager itself, so $this inside the closure is the
 * EngineManager, not this provider. The application container is captured explicitly.
 */
class OpensolrScoutServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/scout-opensolr.php', 'scout-opensolr');

        $this->publishes([
            __DIR__ . '/../config/scout-opensolr.php' => config_path('scout-opensolr.php'),
        ], 'scout-opensolr-config');

        $app = $this->app;
        $this->app->make(EngineManager::class)->extend('opensolr', function () use ($app) {
            $config = $app['config'];

            return new OpensolrEngine(
                client: new OpensolrClient(
                    email: $config->get('scout-opensolr.email', env('OPENSOLR_EMAIL', '')),
                    apiKey: $config->get('scout-opensolr.api_key', env('OPENSOLR_API_KEY', '')),
                ),
                index: $config->get('scout-opensolr.index', ''),
                hybrid: (bool) $config->get('scout-opensolr.hybrid', true),
                alpha: (float) $config->get('scout-opensolr.alpha', 0.5),
                softDelete: (bool) $config->get('scout.soft_delete', false),
                mode: (string) $config->get('scout-opensolr.mode', 'hybrid'),
                ingestWait: (bool) $config->get('scout-opensolr.ingest_wait', false),
                // Fresh Results Bias — off unless the application opts in. Wired here
                // because the container builds the engine: a constructor flag nothing
                // ever sets is an option that does not exist.
                freshBias: (bool) $config->get('scout-opensolr.fresh_bias', false),
                freshBiasWeight: $config->get('scout-opensolr.fresh_bias_weight') !== null
                    ? (float) $config->get('scout-opensolr.fresh_bias_weight')
                    : null,
            );
        });
    }
}
