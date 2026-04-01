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

    /**
     * Missing required columns in configuration
     *
     * @param array $columnNames Names of missing columns
     */
    public static function missingColumns(array $columnNames): self
    {
        return new self(
            sprintf(
                'Required TicketBAI columns are not configured: %s. Define them in config/ticketbai.php or via environment variables.',
                implode(', ', $columnNames)
            )
        );
    }

    /**
     * Missing configuration file or setting
     *
     * @param string $key Configuration key that's missing
     */
    public static function missing(string $key): self
    {
        return new self(
            sprintf('Required configuration key "%s" is not set. Check your config/ticketbai.php or environment variables.', $key)
        );
    }
}
