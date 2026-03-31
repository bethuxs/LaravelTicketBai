<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Feature;

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\ResendInvoice;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use EBethus\LaravelTicketBAI\TicketBAI;
use Barnetik\Tbai\Api;
use Barnetik\Tbai\TicketBai as BarnetikTicketBai;
use Mockery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ResendInvoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function resend_accepts_invoice_model(): void
    {
        $xml = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
        Storage::disk('local')->put('ticketbai/dummy.xml', $xml);

        $invoice = new Invoice();
        $invoice->path = 'ticketbai/dummy.xml';
        $invoice->issuer = 1;
        $invoice->provider_reference = 'INV-RESEND-1';
        $invoice->data = ['ticketbai' => ['signature' => 'sig', 'territory' => '01']];
        $invoice->save();

        // Should not throw when creating job with Invoice
        $job = new ResendInvoice($invoice);
        $this->assertNotNull($job);
    }

    /** @test */
    public function resend_marks_invoice_failed_on_error(): void
    {
        Log::spy();

        $xml = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
        Storage::disk('local')->put('ticketbai/test.xml', $xml);

        $invoice = new Invoice();
        $invoice->path = 'ticketbai/test.xml';
        $invoice->issuer = 1;
        $invoice->provider_reference = 'INV-API-ERROR';
        $invoice->data = ['ticketbai' => ['signature' => 'sig', 'territory' => '01']];
        $invoice->save();

        $ticketbai = $this->app->make(TicketBAI::class);

        // Mock API to return error
        $resultMock = Mockery::mock(\Barnetik\Tbai\Response\SubmitResult::class);
        $resultMock->shouldReceive('isCorrect')->andReturn(false);
        $resultMock->shouldReceive('content')->andReturn(['code' => '002', 'description' => 'Schema error']);

        $apiMock = Mockery::mock(Api::class);
        $apiMock->shouldReceive('submitInvoice')->andReturn($resultMock);

        $job = new class($invoice) extends ResendInvoice {
            private Api $apiMock;

            public function setApiMock(Api $apiMock): self
            {
                $this->apiMock = $apiMock;
                return $this;
            }

            protected function createApi(BarnetikTicketBai $tbai, bool $test, bool $debug): Api
            {
                return $this->apiMock;
            }
        };

        $job->setApiMock($apiMock);

        // Job should fail
        $this->expectException(\Exception::class);
        $job->handle($ticketbai);

        // Invoice should be marked as failed
        $invoice->refresh();
        $this->assertEquals('failed', $invoice->status);
        $this->assertArrayHasKey('error', $invoice->data);

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    /** @test */
    public function resend_stores_error_response_on_failure(): void
    {
        Log::spy();

        $xml = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
        Storage::disk('local')->put('ticketbai/test.xml', $xml);

        $invoice = new Invoice();
        $invoice->path = 'ticketbai/test.xml';
        $invoice->issuer = 1;
        $invoice->provider_reference = 'INV-STORE-ERROR';
        $invoice->data = ['ticketbai' => ['signature' => 'sig', 'territory' => '01']];
        $invoice->save();

        $ticketbai = $this->app->make(TicketBAI::class);

        $errorContent = ['code' => '003', 'description' => 'Invoice duplicate'];

        $resultMock = Mockery::mock(\Barnetik\Tbai\Response\SubmitResult::class);
        $resultMock->shouldReceive('isCorrect')->andReturn(false);
        $resultMock->shouldReceive('content')->andReturn($errorContent);

        $apiMock = Mockery::mock(Api::class);
        $apiMock->shouldReceive('submitInvoice')->andReturn($resultMock);

        $job = new class($invoice) extends ResendInvoice {
            private Api $apiMock;

            public function setApiMock(Api $apiMock): self
            {
                $this->apiMock = $apiMock;
                return $this;
            }

            protected function createApi(BarnetikTicketBai $tbai, bool $test, bool $debug): Api
            {
                return $this->apiMock;
            }
        };

        $job->setApiMock($apiMock);

        $this->expectException(\Exception::class);
        $job->handle($ticketbai);

        $invoice->refresh();
        $this->assertEquals($errorContent, $invoice->data['error']);
    }
}

