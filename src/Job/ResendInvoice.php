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
            throw new \RuntimeException(
                'Cannot resend invoice: territory is not configured or missing. Ensure data column contains territory under data_key.'
            );
        }

        if (empty($path)) {
            throw new \RuntimeException(
                sprintf('Cannot resend invoice id [%s]: path is empty.', $invoice->getKey())
            );
        }

        $diskName = $this->disk ?? $ticketbaiService->getDisk();
        $xml = Storage::disk($diskName)->get($path);
        $sentColumn = Invoice::getColumnName('sent') ?? 'sent';

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
