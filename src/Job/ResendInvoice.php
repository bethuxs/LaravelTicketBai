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
        $pathColumn = Invoice::getColumnName('path') ?? 'path';
        $territoryColumn = Invoice::getColumnName('territory');
        $sentColumn = Invoice::getColumnName('sent') ?? 'sent';

        if ($territoryColumn === null || $territoryColumn === '') {
            throw new \RuntimeException(
                'Cannot resend invoice: territory column is not configured. Set TICKETBAI_COLUMN_TERRITORY in config.'
            );
        }

        $path = $invoice->{$pathColumn};
        $territory = $invoice->{$territoryColumn};

        if (empty($territory)) {
            throw new \RuntimeException(
                sprintf('Cannot resend invoice id [%s]: territory is empty.', $invoice->getKey())
            );
        }

        $diskName = $this->disk ?? $ticketbaiService->getDisk();
        $xml = Storage::disk($diskName)->get($path);

        $tbai = TicketBai::createFromXml($xml, $territory, false);
        $privateKey = $ticketbaiService->getCertificate();
        $certPassword = $ticketbaiService->getCertPassword() ?? '';
        $test = ! App::environment('production');
        $debug = config('app.debug');
        $api = \Barnetik\Tbai\Api::createForTicketBai($tbai, $test, $debug);

        try {
            $result = $api->submitInvoice($tbai, $privateKey, $certPassword);
        } catch (\Throwable $e) {
            Log::error('TicketBAI resend failed', [
                'invoice_id' => $invoice->getKey(),
                'exception' => $e->getMessage(),
            ]);
            $this->fail($e);

            return;
        }

        if ($result->isCorrect()) {
            $invoice->{$sentColumn} = date('Y-m-d H:i:s');
            $invoice->save();
        } else {
            Log::error('TicketBAI resend API error', ['content' => $result->content()]);
        }
    }
}
