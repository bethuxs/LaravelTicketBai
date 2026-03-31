<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Job;

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\TicketBAI;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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

    public function __construct(
        protected TicketBAI $ticketbai,
        protected ?string $disk = null
    ) {}

    public function handle(): void
    {
        $ticketbai = $this->ticketbai;
        $ticketbai->copySignatureOnLocal();
        $model = $ticketbai->getModel();
        $tbai = $ticketbai->getTBAI();
        $privateKey = $ticketbai->getCertificate();
        $certPassword = $ticketbai->getCertPassword();
        $debug = config('app.debug');
        $test = ! App::environment('production');
        $api = $this->createApi($tbai, $test, $debug);

        try {
            $result = $api->submitInvoice($tbai, $privateKey, $certPassword ?? '');
        } catch (\Throwable $e) {
            // Exception path: Certificate, connection, or validation errors
            // Log error and fail job for retry
            if ($model !== null) {
                $payload = Invoice::getTicketBaiPayload($model);
                $path = $payload['path'] ?? null;
                if ($path === null) {
                    $pathCol = Invoice::getColumnName('path');
                    if ($pathCol !== null) {
                        $path = $model->{$pathCol};
                    }
                }
                $diskName = $this->disk ?? $ticketbai->getDisk();
                if ($path !== null) {
                    $xmlContent = Storage::disk($diskName)->get($path);
                    Log::error('TicketBAI invoice send failed. XML content logged.', [
                        'invoice_number' => $model->{Invoice::getColumnName('number') ?? 'number'},
                        'exception' => $e->getMessage(),
                        'xml_length' => strlen($xmlContent),
                    ]);
                }
                $exception = new \Exception(
                    sprintf(
                        'TicketBAI send failed for invoice [%s]: %s',
                        $model->getKey(),
                        $e->getMessage()
                    )
                );
                $this->fail($exception);
            } else {
                $this->fail($e);
            }

            return;
        }

        // API response received - check result
        if ($result->isCorrect() && $model !== null) {
            // SUCCESS: API accepted the invoice
            // Mark as sent with current timestamp
            $sentColumn = Invoice::getColumnName('sent') ?? 'sent';
            $statusColumn = Invoice::getColumnName('status') ?? 'status';
            $model->{$sentColumn} = date('Y-m-d H:i:s');
            $model->{$statusColumn} = 'sent';
            $ticketbai->clearFile();
            $model->save();
        } else {
            // ERROR: API rejected the invoice
            // Mark as failed, store error details, and fail job for potential retry
            $info = $result->content();
            Log::error('TicketBAI API returned error response', ['response' => $info]);
            
            if ($model !== null) {
                $dataColumn = Invoice::getColumnName('data') ?? 'data';
                $statusColumn = Invoice::getColumnName('status') ?? 'status';
                $payload = Invoice::getTicketBaiPayload($model);
                $payload['error'] = $info;
                $model->{$dataColumn} = $payload;
                $model->{$statusColumn} = 'failed';
                $model->save();
            }
            
            $errorMessage = sprintf(
                'TicketBAI invoice [%s] rejected: %s',
                $model?->getKey() ?? 'unknown',
                is_array($info) ? json_encode($info) : $info
            );
            $this->fail(new \Exception($errorMessage));
        }
    }

    /**
     * Create the TicketBAI API instance. Override in tests to inject a mock.
     */
    protected function createApi(\Barnetik\Tbai\TicketBai $tbai, bool $test, bool $debug): \Barnetik\Tbai\Api
    {
        return \Barnetik\Tbai\Api::createForTicketBai($tbai, $test, $debug);
    }
}
