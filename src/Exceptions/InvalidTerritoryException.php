<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Exceptions;

use InvalidArgumentException;

class InvalidTerritoryException extends InvalidArgumentException
{
    public static function for(string $territory): self
    {
        return new self(
            sprintf(
                'Territory "%s" is invalid. Must be one of: ARABA, BIZKAIA, GIPUZKOA.',
                $territory
            )
        );
    }
}
