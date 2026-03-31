<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Job;

use Barnetik\Tbai\TicketBai;
use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\TicketBAI as TicketBAIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ResendInvoice Job
 *
 * Handles resending of previously signed invoices that may have failed initially.
 * Behaves consistently with InvoiceSend for error handling.
 *
 * On success: Sets status='sent' and sent timestamp
 * On error: Sets status='failed', stores error response, and fails the job for retry
 */
class ResendInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected Invoice $invoice,
        protected ?string $disk = null
    ) {}

    public function handle(TicketBAIService $ticketbaiService): void
    {
        $invoice = $this->invoice;
        $payload = Invoice::getTicketBaiPayload($invoice);
        $path = $payload['path'] ?? null;
        $territory = $payload['territory'] ?? null;

        if ($path === null) {
            $pathColumn = Invoice::getColumnName('path');
            if ($pathColumn !== null) {
                $path = $invoice->{$pathColumn};
            }
        }

        if (empty($territory)) {
            $this->fail(new \RuntimeException(
                'Cannot resend invoice: territory is not configured or missing. Ensure data column contains territory under data_key.'
            ));
            return;
        }

        if (empty($path)) {
            $this->fail(new \RuntimeException(
                sprintf('Cannot resend invoice id [%s]: path is empty.', $invoice->getKey())
            ));
            return;
        }

        $diskName = $this->disk ?? $ticketbaiService->getDisk();
        
        try {
            $xml = Storage::disk($diskName)->get($path);
            $tbai = TicketBai::createFromXml($xml, $territory, false);
            $privateKey = $ticketbaiService->getCertificate();
            $certPassword = $ticketbaiService->getCertPassword() ?? '';
            $test = ! App::environment('production');
            $debug = config('app.debug');
            $api = \Barnetik\Tbai\Api::createForTicketBai($tbai, $test, $debug);

            $result = $api->submitInvoice($tbai, $privateKey, $certPassword);
        } catch (\Throwable $e) {
            Log::error('TicketBAI resend failed', [
                'invoice_id' => $invoice->getKey(),
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->fail($e);
            return;
        }

        // Check API response
        if ($result->isCorrect()) {
            // SUCCESS: API accepted the invoice
            $sentColumn = Invoice::getColumnName('sent');
            $dataColumn = Invoice::getColumnName('data');
            
            if ($sentColumn !== null) {
                $invoice->{$sentColumn} = date('Y-m-d H:i:s');
            }
            
            // Store status in data JSON
            if ($dataColumn !== null) {
                $payload = Invoice::getTicketBaiPayload($invoice);
                $payload['status'] = 'sent';
                $invoice->{$dataColumn} = $payload;
            }
            
            if ($sentColumn !== null || $dataColumn !== null) {
                $invoice->save();
            }
        } else {
            // ERROR: API rejected the invoice
            // Mark as failed, store error and status in data JSON, fail job for retry
            $info = $result->content();
            Log::error('TicketBAI resend API error', ['response' => $info]);
            
            $dataColumn = Invoice::getColumnName('data');
            $payload = Invoice::getTicketBaiPayload($invoice);
            $payload['error'] = $info;
            $payload['status'] = 'failed';
            
            if ($dataColumn !== null) {
                $invoice->{$dataColumn} = $payload;
                $invoice->save();
            }
            
            $errorMessage = sprintf(
                'TicketBAI resend for invoice [%s] rejected: %s',
                $invoice->getKey(),
                is_array($info) ? json_encode($info) : $info
            );
            $this->fail(new \Exception($errorMessage));
        }
    }
}
