<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Feature;

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\InvoiceSend;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use EBethus\LaravelTicketBAI\TicketBAI;
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
    public function save_stores_file_in_storage_creates_invoice_and_dispatches_job(): void
    {
        Queue::fake();

        $ticketbai = new TicketBAI(config('services.ticketbai'));
        $ticketbai->setVendor('L', 'B1', 'App', '1.0');
        $ticketbai->issuer('B12345678', 'Company', 1);
        $ticketbai->setVat(21);
        $ticketbai->add('Item', 10.0, 1);

        $tmpFile = tempnam(sys_get_temp_dir(), 'ticketbai');
        file_put_contents($tmpFile, '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>');

        $tbaiMock = $this->createMock(\Barnetik\Tbai\TicketBai::class);
        $tbaiMock->method('chainSignatureValue')->willReturn('test-signature-value');
        $tbaiMock->method('territory')->willReturn('01');

        $ref = new \ReflectionClass($ticketbai);
        $ref->getProperty('idIssuer')->setValue($ticketbai, 1);
        $ref->getProperty('invoiceNumber')->setValue($ticketbai, 'TEST-INV-001');
        $ref->getProperty('signedFilename')->setValue($ticketbai, $tmpFile);
        $ref->getProperty('ticketbai')->setValue($ticketbai, $tbaiMock);

        $ticketbai->save();

        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }

        $this->assertDatabaseHas('invoices', [
            'issuer' => 1,
            'provider_reference' => 'TEST-INV-001',
            'signature' => 'test-signature-value',
        ]);

        $invoice = Invoice::query()->where('provider_reference', 'TEST-INV-001')->first();
        $this->assertNotNull($invoice);
        $this->assertNotEmpty($invoice->path);
        $this->assertTrue(Storage::disk('local')->exists($invoice->path));

        Queue::assertPushed(InvoiceSend::class);
    }
}
