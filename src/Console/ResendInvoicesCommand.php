<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Console;

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\ResendInvoice;
use Illuminate\Console\Command;

class ResendInvoicesCommand extends Command
{
    protected $signature = 'ticketbai:resend
                            {--id= : Resend a single invoice by ID}
                            {--all : Resend all invoices with sent=null}
                            {--dry-run : List invoices that would be resent without dispatching jobs}';

    protected $description = 'Resend TicketBAI invoices that have not been sent (sent=null)';

    public function handle(): int
    {
        $id = $this->option('id');
        $all = $this->option('all');
        $dryRun = $this->option('dry-run');

        if (! $id && ! $all) {
            $this->error('Use --id=<invoice_id> to resend one invoice or --all to resend all pending.');

            return self::FAILURE;
        }

        $sentColumn = Invoice::getColumnName('sent') ?? 'sent';
        $territoryColumn = Invoice::getColumnName('territory');

        if ($territoryColumn === null || $territoryColumn === '') {
            $this->error('Territory column is not configured. Set TICKETBAI_COLUMN_TERRITORY in config to use resend.');

            return self::FAILURE;
        }

        $query = Invoice::query()->whereNull($sentColumn);

        if ($id !== null) {
            $query->where(Invoice::query()->getModel()->getKeyName(), $id);
        }

        $invoices = $query->get();

        if ($invoices->isEmpty()) {
            $this->info('No pending invoices found.');

            return self::SUCCESS;
        }

        $numberColumn = Invoice::getColumnName('number') ?? 'number';

        $this->table(
            ['ID', 'Number', 'Territory'],
            $invoices->map(function (Invoice $inv) use ($numberColumn, $territoryColumn): array {
                return [
                    $inv->getKey(),
                    $inv->{$numberColumn},
                    $inv->{$territoryColumn} ?? '-',
                ];
            })->toArray()
        );

        if ($dryRun) {
            $this->info(sprintf('Dry run: %d invoice(s) would be queued for resend.', $invoices->count()));

            return self::SUCCESS;
        }

        foreach ($invoices as $invoice) {
            ResendInvoice::dispatch($invoice);
        }

        $this->info(sprintf('Dispatched %d resend job(s).', $invoices->count()));

        return self::SUCCESS;
    }
}
