<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\Invoice;
use EBethus\LaravelTicketBAI\Job\InvoiceSend;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('local');
});

test('invoice can be created and stored', function () {
    // Create an invoice directly
    $xml = '<?xml version="1.0"?><T:TicketBai xmlns:T="urn:ticketbai:emision"/>';
    Storage::disk('local')->put('ticketbai/test.xml', $xml);

    $invoice = new Invoice();
    $invoice->issuer = 1;
    $invoice->provider_reference = 'TEST-INV-001';
    $invoice->path = 'ticketbai/test.xml';
    $invoice->data = [
        'ticketbai' => [
            'territory' => '01',
            'signature' => 'test-signature-value',
        ],
    ];
    $invoice->save();

    // Verify invoice was stored
    $this->assertDatabaseHas('invoices', [
        'issuer' => 1,
        'provider_reference' => 'TEST-INV-001',
    ]);

    $retrieved = Invoice::query()->where('provider_reference', 'TEST-INV-001')->first();
    expect($retrieved)->not->toBeNull();
    expect($retrieved->data)->toBeArray();
    expect($retrieved->data)->toHaveKey('ticketbai');
    expect($retrieved->data['ticketbai']['signature'])->toBe('test-signature-value');
    expect($retrieved->data['ticketbai']['territory'])->toBe('01');
    expect($retrieved->path)->not->toBeEmpty();
    expect(Storage::disk('local')->exists($retrieved->path))->toBeTrue();
});

test('invoice send job accepts invoice model', function () {
    // Verify that InvoiceSend job constructor accepts Invoice model
    $invoice = new Invoice();
    $invoice->issuer = 1;
    $invoice->provider_reference = 'TEST-INV-002';
    $invoice->path = 'ticketbai/test.xml';
    $invoice->data = ['ticketbai' => ['territory' => '01']];
    $invoice->save();

    // Should not throw exception when creating job with Invoice
    $job = new InvoiceSend($invoice);
    expect($job)->not->toBeNull();
});

test('invoice send job can be queued', function () {
    Queue::fake();

    $invoice = new Invoice();
    $invoice->issuer = 1;
    $invoice->provider_reference = 'TEST-INV-003';
    $invoice->path = 'ticketbai/test.xml';
    $invoice->data = ['ticketbai' => ['territory' => '01']];
    $invoice->save();

    // Queue the job
    InvoiceSend::dispatch($invoice);

    // Verify job was queued
    Queue::assertPushed(InvoiceSend::class);
});

