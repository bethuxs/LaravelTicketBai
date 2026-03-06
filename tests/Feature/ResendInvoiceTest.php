<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Feature;

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\ResendInvoice;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class ResendInvoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function resend_throws_when_territory_missing_in_data(): void
    {
        $invoice = new Invoice;
        $invoice->path = 'ticketbai/dummy.xml';
        $invoice->issuer = 1;
        $invoice->provider_reference = 'INV-1';
        $invoice->data = ['ticketbai' => ['signature' => 'sig']];
        $invoice->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('territory is not configured or missing');

        $job = new ResendInvoice($invoice);
        $job->handle(app(\EBethus\LaravelTicketBAI\TicketBAI::class));
    }

    /** @test */
    public function resend_throws_when_data_has_no_ticketbai_key(): void
    {
        $invoice = new Invoice;
        $invoice->path = 'ticketbai/dummy.xml';
        $invoice->issuer = 1;
        $invoice->provider_reference = 'INV-1';
        $invoice->data = [];
        $invoice->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('territory is not configured or missing');

        $job = new ResendInvoice($invoice);
        $job->handle(app(\EBethus\LaravelTicketBAI\TicketBAI::class));
    }

    /** @test */
    public function resend_throws_when_xml_in_storage_is_invalid(): void
    {
        $path = 'ticketbai/invalid.xml';
        Storage::disk('local')->put($path, 'not valid xml at all');

        $invoice = new Invoice;
        $invoice->path = $path;
        $invoice->issuer = 1;
        $invoice->provider_reference = 'INV-1';
        $invoice->data = ['ticketbai' => ['signature' => 'sig', 'territory' => '01']];
        $invoice->save();

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/Invalid XML|Start tag expected/');

        $job = new ResendInvoice($invoice);
        $job->handle(app(\EBethus\LaravelTicketBAI\TicketBAI::class));
    }
}
