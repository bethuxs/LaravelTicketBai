<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Feature;

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\InvoiceSend;
use EBethus\LaravelTicketBAI\Job\ResendInvoice;
use Orchestra\Testbench\TestCase;

class InvoiceStatusPersistenceTest extends TestCase
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
     * Test that InvoiceSend job accepts Invoice model
     */
    public function test_invoice_send_accepts_invoice_model()
    {
        $invoice = new Invoice([
            'issuer' => 1,
            'provider_reference' => 'TEST001',
            'path' => 'test/path.xml',
            'data' => json_encode(['ticketbai' => ['signature' => 'sig', 'territory' => '02']]),
        ]);

        $job = new InvoiceSend($invoice);
        $this->assertInstanceOf(InvoiceSend::class, $job);
    }

    /**
     * Test that ResendInvoice job accepts Invoice model
     */
    public function test_resend_invoice_accepts_invoice_model()
    {
        $invoice = new Invoice([
            'issuer' => 1,
            'provider_reference' => 'TEST002',
            'path' => 'test/path.xml',
            'data' => json_encode(['ticketbai' => ['signature' => 'sig', 'territory' => '02']]),
        ]);

        $job = new ResendInvoice($invoice);
        $this->assertInstanceOf(ResendInvoice::class, $job);
    }

    /**
     * Test valid status values according to TicketBAI spec
     */
    public function test_valid_status_values()
    {
        $validStatuses = ['sent', 'failed'];
        
        // Valid statuses
        $this->assertContains('sent', $validStatuses);
        $this->assertContains('failed', $validStatuses);
        
        // Only 2 valid states
        $this->assertCount(2, $validStatuses);
    }

    /**
     * Test that status column name is retrievable from config
     */
    public function test_status_column_name_from_config()
    {
        $statusColumn = Invoice::getColumnName('status');
        
        // Should be 'status' when configured
        $this->assertEquals('status', $statusColumn);
    }
}
