<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests;

use EBethus\LaravelTicketBAI\TicketBAIProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;
    use WithWorkbench;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        // Load stubs for missing Barnetik classes BEFORE parent::setUp()
        if (! class_exists(\Barnetik\Tbai\Fingerprint\Vendor::class)) {
            require_once __DIR__.'/stubs/Barnetik/Tbai/Fingerprint/Vendor.php';
        }
        if (! class_exists(\Barnetik\Tbai\TicketBai::class)) {
            require_once __DIR__.'/stubs/Barnetik/Tbai/TicketBai.php';
        }
        
        parent::setUp();

        // Run migrations AFTER parent::setUp()
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
            'number' => 'provider_reference',
            'path' => 'path',
            'data' => 'data',
            'sent' => 'sent',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ]);
    }
}
