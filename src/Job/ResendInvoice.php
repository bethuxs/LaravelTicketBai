<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Job;

use Barnetik\Tbai\TicketBai;
use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\TicketBAI as TicketBAIService;
use EBethus\LaravelTicketBAI\Exceptions\MissingInvoicePathException;
use EBethus\LaravelTicketBAI\Exceptions\MissingTerritoryException;
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
            $this->fail(MissingTerritoryException::inInvoiceData());
            return;
        }

        if (empty($path)) {
            $this->fail(MissingInvoicePathException::forInvoice($invoice->getKey()));
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
            $statusColumn = Invoice::getColumnName('status');
            $dataColumn = Invoice::getColumnName('data');
            
            if ($sentColumn !== null) {
                $invoice->{$sentColumn} = date('Y-m-d H:i:s');
            }
            if ($statusColumn !== null) {
                $invoice->{$statusColumn} = 'sent';
            }
            
            // Also store in data JSON for reference
            if ($dataColumn !== null) {
                $payload = Invoice::getTicketBaiPayload($invoice);
                $payload['status'] = 'sent';
                $invoice->{$dataColumn} = $payload;
            }
            
            if ($sentColumn !== null || $statusColumn !== null || $dataColumn !== null) {
                $invoice->save();
            }
        } else {
            // ERROR: API rejected the invoice
            // Mark as failed, store error details, and fail job for retry
            $info = $result->content();
            Log::error('TicketBAI resend API error', ['response' => $info]);
            
            $dataColumn = Invoice::getColumnName('data');
            $statusColumn = Invoice::getColumnName('status');
            $payload = Invoice::getTicketBaiPayload($invoice);
            $payload['error'] = $info;
            
            if ($dataColumn !== null) {
                $payload['status'] = 'failed';
                $invoice->{$dataColumn} = $payload;
            }
            if ($statusColumn !== null) {
                $invoice->{$statusColumn} = 'failed';
            }
            
            if ($dataColumn !== null || $statusColumn !== null) {
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
