<?php

declare(strict_types=1);

use Barnetik\Tbai\Api;
use Barnetik\Tbai\TicketBai as BarnetikTicketBai;
use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\InvoiceSend;
use EBethus\LaravelTicketBAI\TicketBAI;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['ticketbai.cert_path' => __DIR__.'/../stubs/nonexistent.p12']);
});

test('certificate not found throws exception', function () {
    $path = 'ticketbai/signed.xml';
    Storage::disk('local')->put($path, '<?xml version="1.0"?><root/>');

    $invoice = new Invoice();
    $invoice->path = $path;
    $invoice->issuer = 1;
    $invoice->provider_reference = 'INV-SEND-1';
    $invoice->data = ['ticketbai' => ['territory' => '01']];
    $invoice->save();

    $job = new InvoiceSend($invoice);
    $ticketbai = app(TicketBAI::class);

    expect(fn () => $job->handle($ticketbai))->toThrow(\Exception::class);
});

test('api exception is logged and job fails', function () {
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

    $ticketbai = app(TicketBAI::class);

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

    expect(fn () => $job->handle($ticketbai))->toThrow(\Exception::class);

    Log::shouldHaveReceived('error')->atLeast()->once();
});

test('api error response marks invoice as failed', function () {
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

    $ticketbai = app(TicketBAI::class);

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

    expect(fn () => $job->handle($ticketbai))->toThrow(\Exception::class);

    $invoice->refresh();
    expect($invoice->status)->toBe('failed');
    expect($invoice->data)->toBeArray();
    expect($invoice->data)->toHaveKey('error');
    expect($invoice->data['error'])->toBe($errorContent);

    Log::shouldHaveReceived('error')->atLeast()->once();
});

test('api error does not mark invoice as sent', function () {
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

    $ticketbai = app(TicketBAI::class);

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

    expect(fn () => $job->handle($ticketbai))->toThrow(\Exception::class);

    $invoice->refresh();
    expect($invoice->sent)->toBeNull();
    expect($invoice->status)->toBe('failed');
});
