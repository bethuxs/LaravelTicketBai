<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Feature;

use Barnetik\Tbai\Api;
use Barnetik\Tbai\TicketBai as BarnetikTicketBai;
use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\InvoiceSend;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use EBethus\LaravelTicketBAI\TicketBAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class InvoiceSendFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['ticketbai.cert_path' => __DIR__.'/../stubs/nonexistent.p12']);
    }

    #[Test]
    public function certificate_not_found_throws_exception(): void
    {
        $path = 'ticketbai/signed.xml';
        Storage::disk('local')->put($path, '<?xml version="1.0"?><root/>');

        $invoice = new Invoice();
        $invoice->path = $path;
        $invoice->issuer = 1;
        $invoice->provider_reference = 'INV-SEND-1';
        $invoice->data = ['ticketbai' => ['territory' => '01']];
        $invoice->save();

        $job = new InvoiceSend($invoice);
        $ticketbai = $this->app->make(TicketBAI::class);

        $this->expectException(\Exception::class);
        $job->handle($ticketbai);
    }

    #[Test]
    public function api_exception_is_logged_and_job_fails(): void
    {
        Log::spy();

        $path = 'ticketbai/signed.xml';
        $xmlContent = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
        Storage::disk('local')->put($path, $xmlContent);

        $invoice = new Invoice();
        $invoice->path = $path;
        $invoice->issuer = 1;
        $invoice->provider_reference = 'INV-API-FAIL';
        $invoice->data = ['ticketbai' => ['territory' => '01']];
        $invoice->save();

        $ticketbai = $this->app->make(TicketBAI::class);

        // Create a test job with mocked API
        $job = new class($invoice) extends InvoiceSend {
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

        $apiMock = Mockery::mock(Api::class);
        $apiMock->shouldReceive('submitInvoice')->andThrow(new \RuntimeException('API connection failed'));
        $job->setApiMock($apiMock);

        $this->expectException(\Exception::class);
        $job->handle($ticketbai);

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    #[Test]
    public function api_error_response_marks_invoice_as_failed(): void
    {
        Log::spy();

        $path = 'ticketbai/signed.xml';
        $xmlContent = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
        Storage::disk('local')->put($path, $xmlContent);

        $invoice = new Invoice();
        $invoice->path = $path;
        $invoice->issuer = 1;
        $invoice->provider_reference = 'INV-REJECTED';
        $invoice->data = ['ticketbai' => ['territory' => '01']];
        $invoice->save();

        $ticketbai = $this->app->make(TicketBAI::class);

        $resultMock = Mockery::mock(\Barnetik\Tbai\Response\SubmitResult::class);
        $errorContent = [
            'code' => '002',
            'description' => 'Fichero no cumple el esquema XSD',
        ];
        $resultMock->shouldReceive('isCorrect')->andReturn(false);
        $resultMock->shouldReceive('content')->andReturn($errorContent);

        $apiMock = Mockery::mock(Api::class);
        $apiMock->shouldReceive('submitInvoice')->andReturn($resultMock);

        $job = new class($invoice) extends InvoiceSend {
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
        $this->assertEquals('failed', $invoice->status);
        $this->assertIsArray($invoice->data);
        $this->assertArrayHasKey('error', $invoice->data);
        $this->assertEquals($errorContent, $invoice->data['error']);

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    #[Test]
    public function api_error_does_not_mark_invoice_as_sent(): void
    {
        $path = 'ticketbai/signed.xml';
        $xmlContent = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
        Storage::disk('local')->put($path, $xmlContent);

        $invoice = new Invoice();
        $invoice->path = $path;
        $invoice->issuer = 1;
        $invoice->provider_reference = 'INV-NOT-SENT';
        $invoice->data = ['ticketbai' => ['territory' => '01']];
        $invoice->status = null;
        $invoice->sent = null;
        $invoice->save();

        $ticketbai = $this->app->make(TicketBAI::class);

        $resultMock = Mockery::mock(\Barnetik\Tbai\Response\SubmitResult::class);
        $resultMock->shouldReceive('isCorrect')->andReturn(false);
        $resultMock->shouldReceive('content')->andReturn(['code' => '002']);

        $apiMock = Mockery::mock(Api::class);
        $apiMock->shouldReceive('submitInvoice')->andReturn($resultMock);

        $job = new class($invoice) extends InvoiceSend {
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
        $this->assertNull($invoice->sent);
        $this->assertEquals('failed', $invoice->status);
    }
}
