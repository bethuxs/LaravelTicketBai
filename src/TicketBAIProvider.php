<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI;

use Illuminate\Support\ServiceProvider;

class TicketBAIProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TicketBAI::class, function ($app) {
            return new TicketBAI(config('services.ticketbai'));
        });
        $this->app->alias(TicketBAI::class, 'ticketbai');
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['ticketbai', TicketBAI::class];
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database');

        $this->publishes([
            __DIR__ . '/config/ticketbai.php' => config_path('ticketbai.php'),
        ], 'ticketbai-config');

        $this->mergeConfigFrom(
            __DIR__ . '/config/ticketbai.php',
            'ticketbai'
        );
    }
}
