<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Exceptions;

use RuntimeException;

/**
 * Missing Territory Exception
 *
 * Thrown when territory code is not found in the invoice data.
 * Territory is required for TicketBAI API submission.
 */
final class MissingTerritoryException extends RuntimeException
{
    public static function inInvoiceData(): self
    {
        return new self(
            'Territory is required in invoice data for TicketBAI submission',
            code: 2
        );
    }
}
