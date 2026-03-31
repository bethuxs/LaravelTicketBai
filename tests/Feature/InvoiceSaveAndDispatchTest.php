<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Feature;

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\InvoiceSend;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class InvoiceSaveAndDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function invoice_can_be_created_and_stored(): void
    {
        // Create an invoice directly
        $xml = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
        Storage::disk('local')->put('ticketbai/test.xml', $xml);

        $invoice = new Invoice();
        $invoice->issuer = 1;
        $invoice->provider_reference = 'TEST-INV-001';
        $invoice->path = 'ticketbai/test.xml';
        $invoice->data = [
            'ticketbai' => [
                'territory' => '01',
                'signature' => 'test-signature-value',
            ],
        ];
        $invoice->save();

        // Verify invoice was stored
        $this->assertDatabaseHas('invoices', [
            'issuer' => 1,
            'provider_reference' => 'TEST-INV-001',
        ]);

        $retrieved = Invoice::query()->where('provider_reference', 'TEST-INV-001')->first();
        $this->assertNotNull($retrieved);
        $this->assertIsArray($retrieved->data);
        $this->assertArrayHasKey('ticketbai', $retrieved->data);
        $this->assertSame('test-signature-value', $retrieved->data['ticketbai']['signature']);
        $this->assertSame('01', $retrieved->data['ticketbai']['territory']);
        $this->assertNotEmpty($retrieved->path);
        $this->assertTrue(Storage::disk('local')->exists($retrieved->path));
    }

    /** @test */
    public function invoice_send_job_accepts_invoice_model(): void
    {
        // Verify that InvoiceSend job constructor accepts Invoice model
        $invoice = new Invoice();
        $invoice->issuer = 1;
        $invoice->provider_reference = 'TEST-INV-002';
        $invoice->path = 'ticketbai/test.xml';
        $invoice->data = ['ticketbai' => ['territory' => '01']];
        $invoice->save();

        // Should not throw exception when creating job with Invoice
        $job = new InvoiceSend($invoice);
        $this->assertNotNull($job);
    }

    /** @test */
    public function invoice_send_job_can_be_queued(): void
    {
        Queue::fake();

        $invoice = new Invoice();
        $invoice->issuer = 1;
        $invoice->provider_reference = 'TEST-INV-003';
        $invoice->path = 'ticketbai/test.xml';
        $invoice->data = ['ticketbai' => ['territory' => '01']];
        $invoice->save();

        // Queue the job
        InvoiceSend::dispatch($invoice);

        // Verify job was queued
        Queue::assertPushed(InvoiceSend::class);
    }
}

