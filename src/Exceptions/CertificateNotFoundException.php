<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Exceptions;

use RuntimeException;

class CertificateNotFoundException extends RuntimeException
{
    public static function atPath(string $path): self
    {
        return new self(
            sprintf('TicketBAI certificate not found or not readable at: %s', $path)
        );
    }
}
