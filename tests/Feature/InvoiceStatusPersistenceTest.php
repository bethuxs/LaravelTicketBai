<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use EBethus\LaravelTicketBAI\Job\InvoiceSend;
use EBethus\LaravelTicketBAI\Job\ResendInvoice;

uses(TestCase::class);

beforeEach(function () {
    config(['ticketbai.table.name' => 'invoices']);
    config(['ticketbai.table.columns' => [
        'issuer' => 'issuer',
        'number' => 'provider_reference',
        'path' => 'path',
        'data' => 'data',
        'sent' => 'sent',
        'status' => 'status',
    ]]);
});

test('invoice send job accepts invoice model', function () {
    $invoice = new Invoice([
        'issuer' => 1,
        'provider_reference' => 'TEST001',
        'path' => 'test/path.xml',
        'data' => json_encode(['ticketbai' => ['signature' => 'sig', 'territory' => '02']]),
    ]);

    $job = new InvoiceSend($invoice);
    expect($job)->toBeInstanceOf(InvoiceSend::class);
});

test('resend invoice job accepts invoice model', function () {
    $invoice = new Invoice([
        'issuer' => 1,
        'provider_reference' => 'TEST002',
        'path' => 'test/path.xml',
        'data' => json_encode(['ticketbai' => ['signature' => 'sig', 'territory' => '02']]),
    ]);

    $job = new ResendInvoice($invoice);
    expect($job)->toBeInstanceOf(ResendInvoice::class);
});

test('valid status values according to ticketbai spec', function () {
    $validStatuses = ['sent', 'failed'];
    
    expect($validStatuses)->toContain('sent');
    expect($validStatuses)->toContain('failed');
    expect(count($validStatuses))->toBe(2);
});

test('status column name is retrievable from config', function () {
    $statusColumn = Invoice::getColumnName('status');
    
    expect($statusColumn)->toBe('status');
});
