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
            if ($model !== null) {
                $pathColumn = Invoice::getColumnName('path') ?? 'path';
                $diskName = $this->disk ?? $ticketbai->getDisk();
                $xmlContent = Storage::disk($diskName)->get($model->{$pathColumn});
                Log::error('TicketBAI invoice send failed. XML content logged.', [
                    'invoice_number' => $model->{Invoice::getColumnName('number') ?? 'number'},
                    'exception' => $e->getMessage(),
                    'xml_length' => strlen($xmlContent),
                ]);
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

        if ($result->isCorrect() && $model !== null) {
            $sentColumn = Invoice::getColumnName('sent') ?? 'sent';
            $model->{$sentColumn} = date('Y-m-d H:i:s');
            $ticketbai->clearFile();
            $model->save();
        } else {
            $info = $result->content();
            Log::error($info);
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
