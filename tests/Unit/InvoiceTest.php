<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Unit;

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Tests\TestCase;

class InvoiceTest extends TestCase
{
    /** @test */
    public function it_uses_configured_table_name()
    {
        config(['ticketbai.table.name' => 'custom_invoices']);

        $invoice = new Invoice;
        $this->assertEquals('custom_invoices', $invoice->getTable());
    }

    /** @test */
    public function it_returns_default_table_name_when_not_configured()
    {
        config(['ticketbai.table.name' => null]);

        $invoice = new Invoice;
        $this->assertEquals('invoices', $invoice->getTable());
    }

    /** @test */
    public function it_returns_configured_column_name()
    {
        config(['ticketbai.table.columns.issuer' => 'transaction_id']);

        $this->assertEquals('transaction_id', Invoice::getColumnName('issuer'));
    }

    /** @test */
    public function it_returns_default_column_name_when_not_configured()
    {
        config(['ticketbai.table.columns' => []]);

        $this->assertEquals('issuer', Invoice::getColumnName('issuer'));
    }

    /** @test */
    public function it_returns_null_for_optional_data_column_when_not_configured()
    {
        config(['ticketbai.table.columns' => []]);

        $this->assertNull(Invoice::getColumnName('data'));
    }

    /** @test */
    public function it_can_get_all_column_mappings()
    {
        $columns = [
            'issuer' => 'transaction_id',
            'number' => 'invoice_number',
        ];

        config(['ticketbai.table.columns' => $columns]);

        $this->assertEquals($columns, Invoice::getColumnMappings());
    }

    /** @test */
    public function get_ticketbai_payload_returns_nulls_when_data_has_no_key(): void
    {
        $invoice = new Invoice;
        $invoice->path = 'ticketbai/foo.xml';
        $invoice->data = null;

        $payload = Invoice::getTicketBaiPayload($invoice);

        $this->assertSame('ticketbai/foo.xml', $payload['path']);
        $this->assertNull($payload['signature']);
        $this->assertNull($payload['territory']);
    }

    /** @test */
    public function get_ticketbai_payload_reads_from_data_key(): void
    {
        config(['ticketbai.ticketbai_data_key' => 'ticketbai']);
        $invoice = new Invoice;
        $invoice->path = 'ticketbai/bar.xml';
        $invoice->data = [
            'ticketbai' => [
                'signature' => 'chain-sig',
                'territory' => '01',
            ],
            'order_id' => 42,
        ];

        $payload = Invoice::getTicketBaiPayload($invoice);

        $this->assertSame('ticketbai/bar.xml', $payload['path']);
        $this->assertSame('chain-sig', $payload['signature']);
        $this->assertSame('01', $payload['territory']);
    }
}
