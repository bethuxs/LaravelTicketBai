<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Invoices;

/**
 * Invoice Status Constants
 *
 * Defines all possible invoice statuses throughout the TicketBAI workflow.
 *
 * - SENT: Invoice successfully submitted to TicketBAI API
 * - FAILED: Invoice submission to TicketBAI API failed (retryable)
 * - PENDING: Invoice signed but not yet submitted (default)
 */
final class InvoiceStatus
{
    /**
     * Invoice successfully submitted to TicketBAI API
     */
    public const SENT = 'sent';

    /**
     * Invoice submission failed (can be retried)
     */
    public const FAILED = 'failed';

    /**
     * Invoice pending submission (default state)
     */
    public const PENDING = null;

    /**
     * Get all valid statuses
     */
    public static function all(): array
    {
        return [
            self::SENT,
            self::FAILED,
        ];
    }

    /**
     * Check if a status is valid
     */
    public static function isValid(?string $status): bool
    {
        return $status === null || in_array($status, self::all(), true);
    }
}
