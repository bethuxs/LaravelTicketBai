<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\ResendInvoice;
use EBethus\LaravelTicketBAI\TicketBAI;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use Barnetik\Tbai\Api;
use Barnetik\Tbai\Api\ResponseInterface;
use Barnetik\Tbai\TicketBai as BarnetikTicketBai;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['ticketbai.cert_path' => __DIR__.'/../stubs/nonexistent.p12']);
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

    // Job catches error and marks invoice as failed
    $job->handle($ticketbai);

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

    $privateKeyMock = Mockery::mock('Barnetik\Tbai\PrivateKey');

    $ticketbaiService = Mockery::mock(TicketBAI::class);
    $ticketbaiService->shouldReceive('getCertificate')->andReturn($privateKeyMock);
    $ticketbaiService->shouldReceive('getCertPassword')->andReturn(null);
    $ticketbaiService->shouldReceive('getDisk')->andReturn('local');
    $this->app->instance(TicketBAI::class, $ticketbaiService);

    $errorContent = ['code' => '003', 'description' => 'Invoice duplicate'];

    $tbaiMock = Mockery::mock(BarnetikTicketBai::class);

    // Create response object implementing ResponseInterface
    $responseMock = new class('400', [], json_encode($errorContent)) implements ResponseInterface {
        private string $statusCode;
        private array $headersList;
        private string $body;

        public function __construct(string $status, array $headers, string $content)
        {
            $this->statusCode = $status;
            $this->headersList = $headers;
            $this->body = $content;
        }

        public function status(): string { return $this->statusCode; }
        public function header(string $key): string { return $this->headersList[$key] ?? ''; }
        public function headers(): array { return $this->headersList; }
        public function content(): string { return $this->body; }
        public function isDelivered(): bool { return true; }
        public function isCorrect(): bool { return false; }
        public function mainErrorMessage(): string { return $this->body; }
        public function saveResponseContent(string $path): void {}
        public function saveFullResponse(string $path): void {}
        public function errorDataRegistry(): array { return json_decode($this->body, true) ?? []; }
        public function hasErrorData(): bool { return true; }
        public function toArray(): array { return json_decode($this->body, true) ?? []; }
    };
    
    $apiMock = Mockery::mock(Api::class);
    $apiMock->shouldReceive('submitInvoice')->andReturn($responseMock);

    $job = new class($invoice) extends ResendInvoice {
        private Api $apiMock;
        private BarnetikTicketBai $tbaiMock;

        public function setApiMock(Api $apiMock): self
        {
            $this->apiMock = $apiMock;
            return $this;
        }

        public function setTbaiMock(BarnetikTicketBai $tbaiMock): self
        {
            $this->tbaiMock = $tbaiMock;
            return $this;
        }

        protected function createApi(BarnetikTicketBai $tbai, bool $test, bool $debug): Api
        {
            return $this->apiMock;
        }

        protected function createTicketBaiFromXml(string $xmlContent, string $territory): BarnetikTicketBai
        {
            return $this->tbaiMock;
        }
    };

    $job->setApiMock($apiMock)->setTbaiMock($tbaiMock);

    // Job catches error and stores it in data
    $job->handle($ticketbaiService);

    $invoice->refresh();
    expect($invoice->data['error'])->toBe(json_encode($errorContent));
});

