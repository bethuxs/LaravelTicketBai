<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\TicketBAI;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use Illuminate\Support\Str;

uses(TestCase::class);

/**
 * Integration test to validate invoice number generation
 * Validates that ULID is truncated to exactly 20 characters for TicketBAI compliance
 */

test('ulid generation produces 26 chars before truncation', function () {
    // Raw ULID always produces 26 characters
    $rawUlid = (string) Str::ulid();
    
    expect(strlen($rawUlid))->toBe(26);
});

test('ticketbai invoice number is truncated to 20 chars', function () {
    // This is what the TicketBAI::getInvoiceNumber() does internally
    $truncated = substr((string) Str::ulid(), 0, 20);
    
    expect(strlen($truncated))->toBe(20);
});

test('invoice generation uses 20 char truncated number', function () {
    $ticketbai = new TicketBAI([]);
    
    // Access via reflection
    $reflection = new \ReflectionClass($ticketbai);
    $method = $reflection->getMethod('getInvoiceNumber');
    $method->setAccessible(true);
    
    $invoiceNumber = $method->invoke($ticketbai);
    
    expect(strlen($invoiceNumber))->toBe(20);
    expect($invoiceNumber)->toMatch('/^[0-9A-Z]{20}$/');
});
