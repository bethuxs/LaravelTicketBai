<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests;

use EBethus\LaravelTicketBAI\TicketBAIProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations
        $this->loadMigrationsFrom(__DIR__.'/../src/database');
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            TicketBAIProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup TicketBAI config
        $app['config']->set('services.ticketbai', [
            'license' => 'TEST_LICENSE',
            'nif' => 'B12345678',
            'appName' => 'Test App',
            'appVersion' => '1.0',
            'certPassword' => 'test_password',
            'disk' => 'local',
        ]);

        // Setup TicketBAI table config
        $app['config']->set('ticketbai.table.name', 'invoices');
        $app['config']->set('ticketbai.table.columns', [
            'issuer' => 'issuer',
            'number' => 'number',
            'territory' => 'territory',
            'signature' => 'signature',
            'path' => 'path',
            'data' => 'data',
            'sent' => 'sent',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ]);
    }
}
