<?php

declare(strict_types=1);

use Barnetik\Tbai\Api;
use Barnetik\Tbai\Api\ResponseInterface;
use Barnetik\Tbai\TicketBai as BarnetikTicketBai;
use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\InvoiceSend;
use EBethus\LaravelTicketBAI\TicketBAI;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['ticketbai.cert_path' => __DIR__.'/../stubs/nonexistent.p12']);
});

test('certificate not found marks invoice as failed', function () {
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

    $job->handle($ticketbai);
    
    $invoice->refresh();
    expect($invoice->status)->toBe('failed');
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

    // Job catches exception and marks as failed - no exception thrown
    $job->handle($ticketbai);

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

    $privateKeyMock = Mockery::mock('Barnetik\Tbai\PrivateKey');
    
    $ticketbaiService = Mockery::mock(TicketBAI::class);
    $ticketbaiService->shouldReceive('getCertificate')->andReturn($privateKeyMock);
    $ticketbaiService->shouldReceive('getCertPassword')->andReturn(null);
    $ticketbaiService->shouldReceive('getDisk')->andReturn('local');
    $this->app->instance(TicketBAI::class, $ticketbaiService);

    $errorContent = [
        'code' => '002',
        'description' => 'Fichero no cumple el esquema XSD',
    ];

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

    $job = new class($invoice) extends InvoiceSend {
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

    // Job catches API error and marks invoice as failed
    $job->handle($ticketbaiService);

    $invoice->refresh();
    expect($invoice->status)->toBe('failed');
    expect($invoice->data)->toBeArray();
    expect($invoice->data)->toHaveKey('error');
    expect($invoice->data['error'])->toBe(json_encode($errorContent));

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

    // Job catches error and marks invoice as failed
    $job->handle($ticketbai);

    $invoice->refresh();
    expect($invoice->sent)->toBeNull();
    expect($invoice->status)->toBe('failed');
});
