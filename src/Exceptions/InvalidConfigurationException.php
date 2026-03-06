<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Exceptions;

use InvalidArgumentException;

class InvalidConfigurationException extends InvalidArgumentException
{
    public static function emptyDataKey(): self
    {
        return new self(
            'TICKETBAI_DATA_KEY cannot be empty. Set it in .env (e.g. TICKETBAI_DATA_KEY=ticketbai) or in config/ticketbai.php.'
        );
    }
}
