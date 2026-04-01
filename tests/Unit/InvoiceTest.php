<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Tests\TestCase;

uses(TestCase::class);

test('it uses configured table name', function () {
    config(['ticketbai.table.name' => 'custom_invoices']);

    $invoice = new Invoice;
    expect($invoice->getTable())->toBe('custom_invoices');
});

test('it returns default table name when not configured', function () {
    config(['ticketbai.table.name' => null]);

    $invoice = new Invoice;
    expect($invoice->getTable())->toBe('invoices');
});

test('it returns configured column name', function () {
    config(['ticketbai.table.columns.issuer' => 'transaction_id']);

    expect(Invoice::getColumnName('issuer'))->toBe('transaction_id');
});

test('it returns default column name when not configured', function () {
    config(['ticketbai.table.columns' => []]);

    expect(Invoice::getColumnName('issuer'))->toBe('issuer');
});

test('it returns null for optional data column when not configured', function () {
    config(['ticketbai.table.columns' => []]);

    expect(Invoice::getColumnName('data'))->toBeNull();
});

test('it can get all column mappings', function () {
    $columns = [
        'issuer' => 'transaction_id',
        'number' => 'invoice_number',
    ];

    config(['ticketbai.table.columns' => $columns]);

    expect(Invoice::getColumnMappings())->toBe($columns);
});

test('get ticketbai payload returns nulls when data has no key', function () {
    $invoice = new Invoice;
    $invoice->path = 'ticketbai/foo.xml';
    $invoice->data = null;

    $payload = Invoice::getTicketBaiPayload($invoice);

    expect($payload['path'])->toBe('ticketbai/foo.xml');
    expect($payload['signature'])->toBeNull();
    expect($payload['territory'])->toBeNull();
});

test('get ticketbai data key throws when empty', function () {
    config(['ticketbai.data_key' => '']);

    expect(fn () => Invoice::getTicketBaiDataKey())
        ->toThrow(\EBethus\LaravelTicketBAI\Exceptions\InvalidConfigurationException::class);
});

test('get ticketbai data key throws when null', function () {
    config(['ticketbai.data_key' => null]);

    expect(fn () => Invoice::getTicketBaiDataKey())
        ->toThrow(\EBethus\LaravelTicketBAI\Exceptions\InvalidConfigurationException::class);
});

test('get ticketbai payload reads from data key', function () {
    config(['ticketbai.data_key' => 'ticketbai']);
    $invoice = new Invoice;
    $invoice->path = 'ticketbai/bar.xml';
    $invoice->data = [
        'ticketbai' => [
            'signature' => 'chain-sig',
            'territory' => '01',
        ],
        'order_id' => 42,
    ];

    $payload = Invoice::getTicketBaiPayload($invoice);

    expect($payload['path'])->toBe('ticketbai/bar.xml');
    expect($payload['signature'])->toBe('chain-sig');
    expect($payload['territory'])->toBe('01');
});
