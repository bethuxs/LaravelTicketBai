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
 * - FAILURE - Duplicate Invoice (isCorrect = false, duplicate error codes):
 *   - Treats as success (invoice was already accepted in a previous submission)
 *   - Sets status = 'sent'
 *   - Stores duplicate indicator in data
 *
 * - FAILURE - Validation/Business Error (isCorrect = false, non-duplicate):
 *   - Sets status = 'failed'
 *   - Stores error response in invoice.data['error']
 *   - Logs error with full API response
 *   - Completes job successfully (no retry - validation errors won't be fixed by retrying)
 *
 * - EXCEPTION:
 *   - Catches connection/certificate errors
 *   - Logs detailed error with XML content
 *   - Fails the job for retry (transient errors may resolve)
 *
 * The invoice is never marked as 'sent' unless the API explicitly returns isCorrect = true or duplicate.
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

        // Guarantee submission order: do not send this invoice if there are earlier invoices
        // from the same seller still pending. This prevents error 010 (encadenamiento) caused
        // by queue workers submitting invoices out of creation order.
        $issuerColumn = Invoice::getColumnName('issuer');
        $statusColumn = Invoice::getColumnName('status');
        if ($issuerColumn !== null && $invoice->{$issuerColumn} !== null) {
            $hasPendingPrevious = false;
            if ($statusColumn !== null) {
                $hasPendingPrevious = Invoice::where($issuerColumn, $invoice->{$issuerColumn})
                    ->where('id', '<', $invoice->getKey())
                    ->where($statusColumn, 'pending')
                    ->exists();
            }

            if ($hasPendingPrevious) {
                Log::info('TicketBAI invoice deferred: earlier invoice for same seller still pending', [
                    'invoice_id' => $invoice->getKey(),
                    'issuer' => $invoice->{$issuerColumn},
                ]);
                $this->release(30);
                return;
            }
        }

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
                Invoice::mergeTicketBaiMetadata($invoice, [
                    'error' => $e->getMessage(),
                    'status' => 'failed',
                ]);
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
                Invoice::mergeTicketBaiMetadata($invoice, ['status' => 'sent']);
            }
            
            // Save if at least one column was modified
            if ($sentColumn !== null || $statusColumn !== null || $dataColumn !== null) {
                $invoice->save();
            }
        } else {
            // ERROR: API returned an error response
            // But check if it's a duplicate invoice (005 / B4_2000003 / 5040) - these should be treated as success
            
            try {
                $isDuplicate = $this->isDuplicateInvoiceError($result);
            } catch (\Throwable $e) {
                Log::warning('TicketBAI: could not verify duplicate status, treating as real error', [
                    'invoice_id' => $invoice->getKey(),
                    'exception' => $e->getMessage(),
                ]);
                $isDuplicate = false;
            }

            if ($isDuplicate) {
                // SOFT-ACCEPTANCE: API returned codes indicating the invoice was already accepted.
                // Codes: 005 (fichero ya recibido), 010 (posible encadenamiento — factura aceptada con aviso),
                // B4_2000003 (Bizkaia duplicado), 5040 (misma serie/número/año).
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
                    Invoice::mergeTicketBaiMetadata($invoice, [
                        'status' => 'sent',
                        'duplicate' => true,
                        'error' => $result->content(),
                    ]);
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
                
                // Save error to data column if it exists
                if ($dataColumn !== null) {
                    Invoice::mergeTicketBaiMetadata($invoice, [
                        'error' => $info,
                        'status' => 'failed',
                    ]);
                }
                // Mark as failed if status column exists
                if ($statusColumn !== null) {
                    $invoice->{$statusColumn} = 'failed';
                }
                
                if ($dataColumn !== null || $statusColumn !== null) {
                    $invoice->save();
                }
                
                // Validation/business rejection: do not fail the queue job.
                // Retries will not fix invalid XML, incorrect codes, or business rule violations.
                // The error has been persisted and logged above.
                return;
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
     * Check if API error response indicates that the invoice was already accepted (soft-accept).
     *
     * Soft-accept codes (treat as 'sent'):
     * - "005"        (ALTA): "El fichero ya se ha recibido anteriormente"
     * - "010"        (ALTA/Araba): "Posible error de encadenamiento" — Araba accepted the invoice
     *                (Estado=00) but added a chain warning in ResultadosValidacion; isCorrect()
     *                returns false for any ResultadosValidacion, so without this code the invoice
     *                would be incorrectly marked as failed even though it IS in the authority's system.
     * - "B4_2000003" (Bizkaia): "Registro duplicado"
     * - "5040"       (ALTA): "Existe una factura con la misma serie, número de factura y año"
     */
    protected function isDuplicateInvoiceError(\Barnetik\Tbai\Api\ResponseInterface $result): bool
    {
        try {
            $errorData = $result->errorDataRegistry();
        } catch (\Throwable $e) {
            return false;
        }

        if ($errorData === []) {
            return false;
        }

        $duplicateCodes = ['005', '010', 'B4_2000003', '5040'];

        foreach ($errorData as $error) {
            $code = (string)($error['errorCode'] ?? '');

            if (!in_array($code, $duplicateCodes, true)) {
                return false;
            }
        }

        return true;
    }
}
