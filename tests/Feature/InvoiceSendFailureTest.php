<?php

declare(strict_types=1);

use Barnetik\Tbai\Api;
use Barnetik\Tbai\PrivateKey;
use Barnetik\Tbai\TicketBai as BarnetikTicketBai;
use EBethus\LaravelTicketBAI\Exceptions\CertificateNotFoundException;
use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\InvoiceSend;
use EBethus\LaravelTicketBAI\TicketBAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses()->beforeEach(function () {
    Storage::fake('local');
    config(['ticketbai.cert_path' => __DIR__.'/../stubs/nonexistent.p12']);
});

test('invoice send job fails when certificate not found', function () {
    $path = 'ticketbai/signed.xml';
    Storage::disk('local')->put($path, '<?xml version="1.0"?><root/>');

    $invoice = new Invoice;
    $invoice->path = $path;
    $invoice->issuer = 1;
    $invoice->provider_reference = 'INV-SEND-1';
    $invoice->data = ['ticketbai' => ['territory' => '01']];
    $invoice->save();

    $ticketbai = new TicketBAI(config('services.ticketbai'));
    $ref = new \ReflectionClass($ticketbai);
    $ref->getProperty('model')->setValue($ticketbai, $invoice);
    $ref->getProperty('signedFilename')->setValue($ticketbai, sys_get_temp_dir().'/tbai-tmp.xml');
    $ref->getProperty('ticketbai')->setValue($ticketbai, \Mockery::mock(BarnetikTicketBai::class));

    $job = new InvoiceSend($ticketbai);

    expect(fn () => $job->handle())->toThrow(CertificateNotFoundException::class);
});

test('invoice send job logs and fails when api throws exception', function () {
    Log::spy();

    $path = 'ticketbai/signed.xml';
    $xmlContent = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
    Storage::disk('local')->put($path, $xmlContent);

    $invoice = new Invoice;
    $invoice->path = $path;
    $invoice->issuer = 1;
    $invoice->provider_reference = 'INV-API-FAIL';
    $invoice->data = ['ticketbai' => ['territory' => '01']];
    $invoice->save();

    $privateKeyMock = \Mockery::mock(PrivateKey::class);

    $ticketbai = new class(config('services.ticketbai')) extends TicketBAI
    {
        public $privateKey;

        public function getCertificate(): PrivateKey
        {
            return $this->privateKey;
        }
    };
    $ticketbai->privateKey = $privateKeyMock;

    $ref = new \ReflectionClass($ticketbai);
    $ref->getProperty('model')->setValue($ticketbai, $invoice);
    $ref->getProperty('signedFilename')->setValue($ticketbai, sys_get_temp_dir().'/tbai-tmp-'.uniqid().'.xml');
    $ref->getProperty('ticketbai')->setValue($ticketbai, \Mockery::mock(BarnetikTicketBai::class));

    $apiMock = \Mockery::mock(Api::class);
    $apiMock->shouldReceive('submitInvoice')->andThrow(new \RuntimeException('API connection failed'));

    $job = new class($ticketbai, $apiMock) extends InvoiceSend
    {
        private $api;

        public function __construct(TicketBAI $ticketbai, Api $api, ?string $disk = null)
        {
            parent::__construct($ticketbai, $disk);
            $this->api = $api;
        }

        protected function createApi(BarnetikTicketBai $tbai, bool $test, bool $debug): Api
        {
            return $this->api;
        }
    };

    expect(fn () => $job->handle())->toThrow(\Exception::class);

    Log::shouldHaveReceived('error')->atLeast()->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'TicketBAI invoice send failed. XML content logged.'
            && isset($context['invoice_number'], $context['exception'], $context['xml_length'])
            && $context['invoice_number'] === 'INV-API-FAIL'
            && $context['exception'] === 'API connection failed'
            && $context['xml_length'] > 0;
    });
});

test('invoice marked as failed when api returns error response', function () {
    Log::spy();

    $path = 'ticketbai/signed.xml';
    $xmlContent = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
    Storage::disk('local')->put($path, $xmlContent);

    $invoice = new Invoice;
    $invoice->path = $path;
    $invoice->issuer = 1;
    $invoice->provider_reference = 'INV-REJECTED';
    $invoice->data = ['ticketbai' => ['territory' => '01']];
    $invoice->save();

    $privateKeyMock = \Mockery::mock(PrivateKey::class);

    $ticketbai = new class(config('services.ticketbai')) extends TicketBAI
    {
        public $privateKey;

        public function getCertificate(): PrivateKey
        {
            return $this->privateKey;
        }
    };
    $ticketbai->privateKey = $privateKeyMock;

    $ref = new \ReflectionClass($ticketbai);
    $ref->getProperty('model')->setValue($ticketbai, $invoice);
    $ref->getProperty('signedFilename')->setValue($ticketbai, sys_get_temp_dir().'/tbai-tmp-'.uniqid().'.xml');
    $barnetikMock = \Mockery::mock(BarnetikTicketBai::class);
    $ref->getProperty('ticketbai')->setValue($ticketbai, $barnetikMock);

    // Create mock result with error
    $resultMock = \Mockery::mock(\Barnetik\Tbai\Response\SubmitResult::class);
    $errorContent = [
        'code' => '002',
        'description' => 'Fichero no cumple el esquema XSD',
    ];
    $resultMock->shouldReceive('isCorrect')->andReturn(false);
    $resultMock->shouldReceive('content')->andReturn($errorContent);

    $apiMock = \Mockery::mock(Api::class);
    $apiMock->shouldReceive('submitInvoice')->andReturn($resultMock);

    $job = new class($ticketbai, $apiMock) extends InvoiceSend
    {
        private $api;

        public function __construct(TicketBAI $ticketbai, Api $api, ?string $disk = null)
        {
            parent::__construct($ticketbai, $disk);
            $this->api = $api;
        }

        protected function createApi(BarnetikTicketBai $tbai, bool $test, bool $debug): Api
        {
            return $this->api;
        }
    };

    // Job fails when API returns error
    expect(fn () => $job->handle())->toThrow(\Exception::class, 'TicketBAI invoice');

    // Even though job fails, invoice should be updated with error status
    $invoice->refresh();
    expect($invoice->status)->toBe('failed');
    expect($invoice->data)->toBeArray();
    expect($invoice->data)->toHaveKey('error');
    expect($invoice->data['error'])->toBe($errorContent);
    
    // Verify error was logged
    Log::shouldHaveReceived('error')->atLeast()->once();
});

test('invoice not marked as sent when api returns error', function () {
    $path = 'ticketbai/signed.xml';
    $xmlContent = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
    Storage::disk('local')->put($path, $xmlContent);

    $invoice = new Invoice;
    $invoice->path = $path;
    $invoice->issuer = 1;
    $invoice->provider_reference = 'INV-NOT-SENT';
    $invoice->data = ['ticketbai' => ['territory' => '01']];
    $invoice->status = null;
    $invoice->sent = null;
    $invoice->save();

    $privateKeyMock = \Mockery::mock(PrivateKey::class);

    $ticketbai = new class(config('services.ticketbai')) extends TicketBAI
    {
        public $privateKey;

        public function getCertificate(): PrivateKey
        {
            return $this->privateKey;
        }
    };
    $ticketbai->privateKey = $privateKeyMock;

    $ref = new \ReflectionClass($ticketbai);
    $ref->getProperty('model')->setValue($ticketbai, $invoice);
    $ref->getProperty('signedFilename')->setValue($ticketbai, sys_get_temp_dir().'/tbai-tmp-'.uniqid().'.xml');
    $barnetikMock = \Mockery::mock(BarnetikTicketBai::class);
    $ref->getProperty('ticketbai')->setValue($ticketbai, $barnetikMock);

    $resultMock = \Mockery::mock(\Barnetik\Tbai\Response\SubmitResult::class);
    $resultMock->shouldReceive('isCorrect')->andReturn(false);
    $resultMock->shouldReceive('content')->andReturn(['code' => '002']);

    $apiMock = \Mockery::mock(Api::class);
    $apiMock->shouldReceive('submitInvoice')->andReturn($resultMock);

    $job = new class($ticketbai, $apiMock) extends InvoiceSend
    {
        private $api;

        public function __construct(TicketBAI $ticketbai, Api $api, ?string $disk = null)
        {
            parent::__construct($ticketbai, $disk);
            $this->api = $api;
        }

        protected function createApi(BarnetikTicketBai $tbai, bool $test, bool $debug): Api
        {
            return $this->api;
        }
    };

    expect(fn () => $job->handle())->toThrow(\Exception::class);

    $invoice->refresh();
    expect($invoice->sent)->toBeNull();
    expect($invoice->status)->toBe('failed');
});