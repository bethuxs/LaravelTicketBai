<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Unit;

use EBethus\LaravelTicketBAI\Invoice;
use Orchestra\Testbench\TestCase;

class InvoiceStatusTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return ['EBethus\LaravelTicketBAI\TicketBAIProvider'];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('ticketbai.table.name', 'invoices');
        $app['config']->set('ticketbai.table.columns', [
            'issuer' => 'issuer',
            'number' => 'provider_reference',
            'path' => 'path',
            'data' => 'data',
            'sent' => 'sent',
            'status' => 'status',
        ]);
    }

    /**
     * Test that status column is configured
     */
    public function test_status_column_is_configured()
    {
        $statusColumn = Invoice::getColumnName('status');
        $this->assertNotNull($statusColumn);
        $this->assertEquals('status', $statusColumn);
    }

    /**
     * Test that status column can be disabled
     */
    public function test_status_column_can_be_disabled()
    {
        config(['ticketbai.table.columns' => [
            'issuer' => 'issuer',
            'number' => 'provider_reference',
            'status' => null,  // Disabled
        ]]);
        
        $statusColumn = Invoice::getColumnName('status');
        $this->assertNull($statusColumn);
    }

    /**
     * Test that status column can have custom name
     */
    public function test_status_column_with_custom_name()
    {
        config(['ticketbai.table.columns' => [
            'issuer' => 'issuer',
            'number' => 'provider_reference',
            'status' => 'invoice_status',  // Custom column name
        ]]);
        
        $statusColumn = Invoice::getColumnName('status');
        $this->assertEquals('invoice_status', $statusColumn);
    }
}
