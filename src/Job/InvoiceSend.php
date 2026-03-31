<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Job;

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\TicketBAI;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * InvoiceSend Job
 *
 * Handles the submission of signed TicketBAI invoices to the Basque Country tax authority API.
 *
 * SECURITY NOTE: Only serializes the Invoice model, not the TicketBAI service.
 * The TicketBAI service (with certificate password) is resolved from the container at execution time.
 *
 * Behavior:
 * - SUCCESS (isCorrect = true):
 *   - Sets status = 'sent' and sent timestamp
 *   - Clears temporary XML file
 *   - Marks as successful
 *
 * - FAILURE (isCorrect = false):
 *   - Sets status = 'failed'
 *   - Stores error response in invoice.data['error']
 *   - Logs error with full API response
 *   - Fails the job so it can be retried
 *
 * - EXCEPTION:
 *   - Catches connection/certificate errors
 *   - Logs detailed error with XML content
 *   - Fails the job appropriately
 *
 * The invoice is never marked as 'sent' unless the API explicitly returns isCorrect = true.
 */
class InvoiceSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Only the Invoice model is serialized, not the TicketBAI service.
     * This prevents certificate passwords from being stored in the queue.
     */
    public function __construct(
        protected Invoice $invoice,
        protected ?string $disk = null
    ) {}

    public function handle(TicketBAI $ticketbaiService): void
    {
        $invoice = $this->invoice;
        $payload = Invoice::getTicketBaiPayload($invoice);
        $path = $payload['path'] ?? null;
        
        // Get path from payload or model column
        if ($path === null) {
            $pathCol = Invoice::getColumnName('path');
            if ($pathCol !== null) {
                $path = $invoice->{$pathCol};
            }
        }

        if ($path === null) {
            $this->fail(new \RuntimeException(
                sprintf('TicketBAI invoice [%s]: path is empty or not found', $invoice->getKey())
            ));
            return;
        }

        // Load signed XML from storage
        $diskName = $this->disk ?? $ticketbaiService->getDisk();
        try {
            $xmlContent = Storage::disk($diskName)->get($path);
            $territory = $payload['territory'] ?? null;
            
            if (empty($territory)) {
                throw new \RuntimeException('Territory is required in invoice data');
            }

            // Reconstruct TicketBAI from XML for submission
            $tbai = \Barnetik\Tbai\TicketBai::createFromXml($xmlContent, $territory, false);
            $privateKey = $ticketbaiService->getCertificate();
            $certPassword = $ticketbaiService->getCertPassword() ?? '';
            $test = ! App::environment('production');
            $debug = config('app.debug');
            $api = \Barnetik\Tbai\Api::createForTicketBai($tbai, $test, $debug);

            $result = $api->submitInvoice($tbai, $privateKey, $certPassword);
        } catch (\Throwable $e) {
            // Exception path: Certificate, connection, or validation errors
            Log::error('TicketBAI invoice send failed', [
                'invoice_id' => $invoice->getKey(),
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->fail($e);
            return;
        }

        // API response received - check result
        if ($result->isCorrect()) {
            // SUCCESS: API accepted the invoice
            // Mark as sent with current timestamp (only if columns are configured)
            $sentColumn = Invoice::getColumnName('sent');
            $statusColumn = Invoice::getColumnName('status');
            
            if ($sentColumn !== null) {
                $invoice->{$sentColumn} = date('Y-m-d H:i:s');
            }
            if ($statusColumn !== null) {
                $invoice->{$statusColumn} = 'sent';
            }
            
            // Only save if at least one column was modified
            if ($sentColumn !== null || $statusColumn !== null) {
                $invoice->save();
            }
        } else {
            // ERROR: API rejected the invoice
            // Mark as failed, store error details, and fail job for potential retry
            $info = $result->content();
            Log::error('TicketBAI API returned error response', ['response' => $info]);
            
            $dataColumn = Invoice::getColumnName('data');
            $statusColumn = Invoice::getColumnName('status');
            $payload = Invoice::getTicketBaiPayload($invoice);
            $payload['error'] = $info;
            
            // Always save error to data column if it exists
            if ($dataColumn !== null) {
                $invoice->{$dataColumn} = $payload;
            }
            // Mark as failed if status column exists
            if ($statusColumn !== null) {
                $invoice->{$statusColumn} = 'failed';
            }
            
            if ($dataColumn !== null || $statusColumn !== null) {
                $invoice->save();
            }
            
            $errorMessage = sprintf(
                'TicketBAI invoice [%s] rejected: %s',
                $invoice->getKey(),
                is_array($info) ? json_encode($info) : $info
            );
            $this->fail(new \Exception($errorMessage));
        }
    }
}
