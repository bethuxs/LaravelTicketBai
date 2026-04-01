<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Reset config to default state before each test
    app('config')->set('ticketbai.table.columns', [
        'issuer' => 'issuer',
        'number' => 'provider_reference',
        'path' => 'path',
        'data' => 'data',
        'sent' => 'sent',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ]);
});

test('status column is configured', function () {
    app('config')->set('ticketbai.table.columns', [
        'issuer' => 'issuer',
        'number' => 'provider_reference',
        'status' => 'status',
    ]);
    
    $statusColumn = Invoice::getColumnName('status');
    expect($statusColumn)->not()->toBeNull();
    expect($statusColumn)->toBe('status');
});

test('status column can be disabled', function () {
    app('config')->set('ticketbai.table.columns', [
        'issuer' => 'issuer',
        'number' => 'provider_reference',
        'status' => null,
    ]);
    
    $statusColumn = Invoice::getColumnName('status');
    expect($statusColumn)->toBeNull();
});

test('status column can have custom name', function () {
    app('config')->set('ticketbai.table.columns', [
        'issuer' => 'issuer',
        'number' => 'provider_reference',
        'status' => 'invoice_status',
    ]);
    
    $statusColumn = Invoice::getColumnName('status');
    expect($statusColumn)->toBe('invoice_status');
});
