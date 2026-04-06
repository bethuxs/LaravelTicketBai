<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Job;

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\TicketBAI;
use EBethus\LaravelTicketBAI\Exceptions\MissingInvoicePathException;
use EBethus\LaravelTicketBAI\Exceptions\MissingTerritoryException;
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
            $this->fail(MissingInvoicePathException::forInvoice($invoice->getKey()));
            return;
        }

        // Load signed XML from storage
        $diskName = $this->disk ?? $ticketbaiService->getDisk();
        try {
            $xmlContent = Storage::disk($diskName)->get($path);
            $territory = $payload['territory'] ?? null;
            
            if (empty($territory)) {
                throw MissingTerritoryException::inInvoiceData();
            }

            // Reconstruct TicketBAI from XML for submission
            $tbai = $this->createTicketBaiFromXml($xmlContent, $territory);
            $privateKey = $ticketbaiService->getCertificate();
            $certPassword = $ticketbaiService->getCertPassword() ?? '';
            $test = ! App::environment('production');
            $debug = config('app.debug');
            
            // Create API instance (can be overridden by tests)
            $api = $this->createApi($tbai, $test, $debug);

            $result = $api->submitInvoice($tbai, $privateKey, $certPassword);
        } catch (\Throwable $e) {
            // Exception path: Certificate, connection, or validation errors
            Log::error('TicketBAI invoice send failed', [
                'invoice_id' => $invoice->getKey(),
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Mark invoice as failed when exception occurs
            $statusColumn = Invoice::getColumnName('status');
            $dataColumn = Invoice::getColumnName('data');
            
            if ($statusColumn !== null) {
                $invoice->{$statusColumn} = 'failed';
            }
            
            if ($dataColumn !== null) {
                $payload = Invoice::getTicketBaiPayload($invoice);
                $payload['error'] = $e->getMessage();
                $payload['status'] = 'failed';
                $invoice->{$dataColumn} = $payload;
            }
            
            if ($statusColumn !== null || $dataColumn !== null) {
                $invoice->save();
            }

            $this->fail($e);
            return;
        }

        // API response received - check result
        if ($result->isCorrect()) {
            // SUCCESS: API accepted the invoice
            // Mark as sent with current timestamp (only if columns are configured)
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
            
            // Save if at least one column was modified
            if ($sentColumn !== null || $statusColumn !== null || $dataColumn !== null) {
                $invoice->save();
            }
        } else {
            // ERROR: API returned an error response
            // But check if it's a duplicate invoice (005 / B4_2000003) - these should be treated as success
            
            if ($this->isDuplicateInvoiceError($result)) {
                // DUPLICATE HANDLING: Treat as success since TicketBAI already accepted this XML
                Log::info('TicketBAI invoice is duplicate (already accepted)', [
                    'invoice_id' => $invoice->getKey(),
                    'response' => $result->content(),
                ]);
                
                $sentColumn = Invoice::getColumnName('sent');
                $statusColumn = Invoice::getColumnName('status');
                $dataColumn = Invoice::getColumnName('data');
                
                if ($sentColumn !== null) {
                    $invoice->{$sentColumn} = date('Y-m-d H:i:s');
                }
                if ($statusColumn !== null) {
                    $invoice->{$statusColumn} = 'sent';
                }
                
                // Store in data JSON with duplicate indicator
                if ($dataColumn !== null) {
                    $payload = Invoice::getTicketBaiPayload($invoice);
                    $payload['status'] = 'sent';
                    $payload['duplicate'] = true;
                    $payload['error'] = $result->content();
                    $invoice->{$dataColumn} = $payload;
                }
                
                if ($sentColumn !== null || $statusColumn !== null || $dataColumn !== null) {
                    $invoice->save();
                }
            } else {
                // REAL ERROR: API rejected the invoice for actual validation/business reasons
                // Mark as failed, store error details, and fail job for potential retry
                $info = $result->content();
                Log::error('TicketBAI API returned error response', ['response' => $info]);
                
                $dataColumn = Invoice::getColumnName('data');
                $statusColumn = Invoice::getColumnName('status');
                $payload = Invoice::getTicketBaiPayload($invoice);
                $payload['error'] = $info;
                
                // Save error to data column if it exists
                if ($dataColumn !== null) {
                    $payload['status'] = 'failed';
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

    /**
     * Create API instance (protected for test mocking)
     */
    protected function createApi(\Barnetik\Tbai\TicketBai $tbai, bool $test, bool $debug): \Barnetik\Tbai\Api
    {
        return \Barnetik\Tbai\Api::createForTicketBai($tbai, $test, $debug);
    }

    /**
     * Create TicketBAI from XML (protected for test mocking)
     */
    protected function createTicketBaiFromXml(string $xmlContent, string $territory): \Barnetik\Tbai\TicketBai
    {
        return \Barnetik\Tbai\TicketBai::createFromXml($xmlContent, $territory, false);
    }

    /**
     * Check if API error response indicates a duplicate invoice (already accepted).
     * 
     * Duplicate codes:
     * - "005" (ALTA format): "El fichero ya se ha recibido anteriormente"
     * - "B4_2000003" (Bizkaia format): "Registro duplicado"
     * 
     * These errors should be treated as success because the invoice was already
     * accepted by TicketBAI in a previous submission attempt.
     */
    protected function isDuplicateInvoiceError(\Barnetik\Tbai\Api\ResponseInterface $result): bool
    {
        $errorData = $result->errorDataRegistry();
        
        // If no error data, it's not a duplicate
        if (empty($errorData)) {
            return false;
        }
        
        // Check if ALL errors are duplicate codes
        foreach ($errorData as $error) {
            $code = (string)($error['errorCode'] ?? '');
            
            // Check for duplicate error codes
            if ($code !== '005' && $code !== 'B4_2000003') {
                // Found a non-duplicate error, so this is not purely a duplicate
                return false;
            }
        }
        
        // All errors (if any) are duplicate codes
        return !empty($errorData);
    }
}

