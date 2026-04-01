<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\ResendInvoice;
use EBethus\LaravelTicketBAI\TicketBAI;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use Barnetik\Tbai\Api;
use Barnetik\Tbai\TicketBai as BarnetikTicketBai;
use Mockery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('local');
});

test('resend accepts invoice model', function () {
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
    expect($job)->not->toBeNull();
});

test('resend marks invoice failed on error', function () {
    Log::spy();

    $xml = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
    Storage::disk('local')->put('ticketbai/test.xml', $xml);

    $invoice = new Invoice();
    $invoice->path = 'ticketbai/test.xml';
    $invoice->issuer = 1;
    $invoice->provider_reference = 'INV-API-ERROR';
    $invoice->data = ['ticketbai' => ['signature' => 'sig', 'territory' => '01']];
    $invoice->save();

    $ticketbai = app(TicketBAI::class);

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
    expect(fn () => $job->handle($ticketbai))->toThrow(\Exception::class);

    // Invoice should be marked as failed
    $invoice->refresh();
    expect($invoice->status)->toBe('failed');
    expect($invoice->data)->toHaveKey('error');

    Log::shouldHaveReceived('error')->atLeast()->once();
});

test('resend stores error response on failure', function () {
    Log::spy();

    $xml = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
    Storage::disk('local')->put('ticketbai/test.xml', $xml);

    $invoice = new Invoice();
    $invoice->path = 'ticketbai/test.xml';
    $invoice->issuer = 1;
    $invoice->provider_reference = 'INV-STORE-ERROR';
    $invoice->data = ['ticketbai' => ['signature' => 'sig', 'territory' => '01']];
    $invoice->save();

    $ticketbai = app(TicketBAI::class);

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

    expect(fn () => $job->handle($ticketbai))->toThrow(\Exception::class);

    $invoice->refresh();
    expect($invoice->data['error'])->toBe($errorContent);
});

