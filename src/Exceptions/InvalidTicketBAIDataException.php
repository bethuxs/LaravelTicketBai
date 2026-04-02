<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Exceptions;

use RuntimeException;

/**
 * Invalid TicketBAI Data Exception
 *
 * Thrown when TicketBAI invoice data is incomplete or invalid.
 * Examples: missing vendor, no items, VAT not configured.
 */
final class InvalidTicketBAIDataException extends RuntimeException
{
    public static function vendorNotSet(): self
    {
        return new self('TicketBAI vendor must be set before submission', code: 10);
    }

    public static function noItemsPresent(): self
    {
        return new self('TicketBAI invoice must have at least one item', code: 11);
    }

    public static function vatPercentageNotSet(): self
    {
        return new self('TicketBAI VAT percentage must be set before submission', code: 12);
    }

    public static function missingSignedFile(): self
    {
        return new self('Signed XML file could not be created or is not readable', code: 13);
    }
}
