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
    public function it_returns_null_for_optional_signature_column_when_not_configured()
    {
        config(['ticketbai.table.columns' => []]);

        $this->assertNull(Invoice::getColumnName('signature'));
    }

    /** @test */
    public function it_returns_null_for_optional_data_column_when_not_configured()
    {
        config(['ticketbai.table.columns' => []]);

        $this->assertNull(Invoice::getColumnName('data'));
    }

    /** @test */
    public function it_returns_null_when_column_is_explicitly_set_to_null()
    {
        config(['ticketbai.table.columns.signature' => null]);

        $this->assertNull(Invoice::getColumnName('signature'));
    }

    /** @test */
    public function it_returns_null_when_column_is_set_to_empty_string()
    {
        config(['ticketbai.table.columns.signature' => '']);

        $this->assertNull(Invoice::getColumnName('signature'));
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
    public function get_ticketbai_payload_reads_from_dedicated_columns_when_data_key_not_set(): void
    {
        config(['ticketbai.ticketbai_data_key' => null]);
        $invoice = new Invoice;
        $invoice->path = 'ticketbai/foo.xml';
        $invoice->signature = 'sig100';
        $invoice->territory = '02';

        $payload = Invoice::getTicketBaiPayload($invoice);

        $this->assertSame('ticketbai/foo.xml', $payload['path']);
        $this->assertSame('sig100', $payload['signature']);
        $this->assertSame('02', $payload['territory']);
    }

    /** @test */
    public function get_ticketbai_payload_reads_from_data_key_when_configured(): void
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
