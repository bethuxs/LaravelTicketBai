<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Exceptions;

use RuntimeException;

/**
 * Missing Invoice Path Exception
 *
 * Thrown when an invoice path is not found or is empty in the data column.
 * This indicates a configuration or data integrity issue.
 */
final class MissingInvoicePathException extends RuntimeException
{
    public static function forInvoice(mixed $invoiceId): self
    {
        return new self(
            sprintf('TicketBAI invoice [%s]: path is empty or not found', $invoiceId),
            code: 1
        );
    }
}
