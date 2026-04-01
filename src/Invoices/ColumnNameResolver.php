<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Invoices;

use EBethus\LaravelTicketBAI\Invoice;

/**
 * Column Name Resolver
 *
 * Centralizes the logic for resolving dynamically configured column names.
 * This avoids repeating the same checks throughout the codebase.
 *
 * Usage:
 *      $sentColumn = ColumnNameResolver::resolve('sent');
 *      if ($sentColumn) {
 *          $invoice->{$sentColumn} = date('Y-m-d H:i:s');
 *      }
 */
final class ColumnNameResolver
{
    /**
     * Resolve a column name from configuration
     *
     * Returns the configured column name for a given internal column key.
     * Returns null if the column is not configured or explicitly disabled.
     *
     * @param string $internalColumn The internal column name (e.g., 'sent', 'status', 'data')
     * @return string|null The configured column name, or null if not available
     */
    public static function resolve(string $internalColumn): ?string
    {
        return Invoice::getColumnName($internalColumn);
    }

    /**
     * Get all configured column mappings
     *
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        return Invoice::getColumnMappings();
    }

    /**
     * Check if a column is configured and available
     *
     * @param string $internalColumn The internal column name
     * @return bool True if the column is configured
     */
    public static function exists(string $internalColumn): bool
    {
        return self::resolve($internalColumn) !== null;
    }

    /**
     * Get required columns, throw if missing
     *
     * @param string ...$columns Column names to check
     * @throws \EBethus\LaravelTicketBAI\Exceptions\InvalidConfigurationException
     */
    public static function requireAll(string ...$columns): void
    {
        $missing = [];
        foreach ($columns as $column) {
            if (!self::exists($column)) {
                $missing[] = $column;
            }
        }

        if (!empty($missing)) {
            throw \EBethus\LaravelTicketBAI\Exceptions\InvalidConfigurationException::missingColumns($missing);
        }
    }

    /**
     * Apply a function to each configured column from a list
     *
     * @param callable $callback Function to apply: function(string $internalName, string $columnName)
     * @param string[] $internalColumns Array of internal column names to check
     */
    public static function apply(callable $callback, array $internalColumns): void
    {
        foreach ($internalColumns as $internalName) {
            $columnName = self::resolve($internalName);
            if ($columnName !== null) {
                $callback($internalName, $columnName);
            }
        }
    }
}
