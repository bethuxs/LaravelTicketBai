<?php
namespace EBethus\LaravelTicketBAI;

use Illuminate\Support\ServiceProvider;

class TicketBAIProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(TicketBAI::class, function ($app) {
            return new TicketBAI(config('services.ticketbai'));
        });
        $this->app->alias(TicketBAI::class, 'ticketbai');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['ticketbai', TicketBAI::class];
    }

    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/database');
        
        // Publish configuration file
        $this->publishes([
            __DIR__.'/config/ticketbai.php' => config_path('ticketbai.php'),
        ], 'ticketbai-config');
        
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__.'/config/ticketbai.php', 'ticketbai'
        );
    }
}
