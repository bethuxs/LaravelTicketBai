<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Feature;

use Barnetik\Tbai\Api;
use Barnetik\Tbai\PrivateKey;
use Barnetik\Tbai\TicketBai as BarnetikTicketBai;
use EBethus\LaravelTicketBAI\Exceptions\CertificateNotFoundException;
use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\InvoiceSend;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use EBethus\LaravelTicketBAI\TicketBAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InvoiceSendFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['ticketbai.cert_path' => __DIR__.'/../stubs/nonexistent.p12']);
    }

    /** @test */
    public function invoice_send_job_fails_when_certificate_not_found_and_logs(): void
    {
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
        $ref->getProperty('ticketbai')->setValue($ticketbai, $this->createMock(\Barnetik\Tbai\TicketBai::class));

        $job = new InvoiceSend($ticketbai);

        $this->expectException(CertificateNotFoundException::class);
        $this->expectExceptionMessage('not found or not readable');

        $job->handle();
    }

    /** @test */
    public function invoice_send_job_logs_and_fails_with_summary_when_api_throws(): void
    {
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

        $privateKeyMock = $this->getMockBuilder(PrivateKey::class)->disableOriginalConstructor()->getMock();

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
        $ref->getProperty('ticketbai')->setValue($ticketbai, $this->createMock(BarnetikTicketBai::class));

        $apiMock = $this->createMock(Api::class);
        $apiMock->method('submitInvoice')->willThrowException(new \RuntimeException('API connection failed'));

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

        $job->handle();

        // Catch branch: job logs and fails with summary (no full XML in exception)
        Log::shouldHaveReceived('error')->atLeast()->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'TicketBAI invoice send failed. XML content logged.'
                && isset($context['invoice_number'], $context['exception'], $context['xml_length'])
                && $context['invoice_number'] === 'INV-API-FAIL'
                && $context['exception'] === 'API connection failed'
                && $context['xml_length'] > 0;
        });
    }
}
