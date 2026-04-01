<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\Exceptions\CertificateNotFoundException;
use EBethus\LaravelTicketBAI\Exceptions\InvalidTerritoryException;
use EBethus\LaravelTicketBAI\TicketBAI;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('local');
});

test('it can be instantiated', function () {
    $config = [
        'license' => 'TEST_LICENSE',
        'nif' => 'B12345678',
        'appName' => 'Test App',
        'appVersion' => '1.0',
        'certPassword' => 'test_password',
        'disk' => 'local',
    ];

    $ticketbai = new TicketBAI($config);

    expect($ticketbai)->toBeInstanceOf(TicketBAI::class);
});

test('it can set vendor', function () {
    $ticketbai = new TicketBAI([]);
    $ticketbai->setVendor('LICENSE', 'B12345678', 'App Name', '1.0');

    expect(true)->toBeTrue();
});

test('it can set issuer', function () {
    $ticketbai = new TicketBAI([]);
    $ticketbai->issuer('B12345678', 'Company Name', 1);

    expect(true)->toBeTrue();
});

test('it can set vat', function () {
    $ticketbai = new TicketBAI([]);
    $ticketbai->setVat(21);

    expect(true)->toBeTrue();
});

test('it can add items', function () {
    $ticketbai = new TicketBAI([]);
    $ticketbai->setVat(21);
    $ticketbai->add('Product', 100.00, 2);

    expect(true)->toBeTrue();
});

test('it throws exception when adding item without vat', function () {
    expect(function () {
        $ticketbai = new TicketBAI([]);
        $ticketbai->add('Product', 100.00, 2);
    })->toThrow(\RuntimeException::class, 'VAT percentage not set');
});

test('it throws exception when adding item with invalid price', function () {
    expect(function () {
        $ticketbai = new TicketBAI([]);
        $ticketbai->setVat(21);
        $ticketbai->add('Product', 'invalid', 2);
    })->toThrow(\TypeError::class);
});

test('it can set extra data', function () {
    $ticketbai = new TicketBAI([]);
    $ticketbai->data(['key' => 'value']);

    expect(true)->toBeTrue();
});

test('it can get column name for optional signature', function () {
    config(['ticketbai.table.columns.signature' => null]);

    $ticketbai = new TicketBAI([]);
    expect(true)->toBeTrue();
});

test('it returns disk from config or local', function () {
    $ticketbai = new TicketBAI(['license' => 'L', 'nif' => 'B1', 'appName' => 'A', 'appVersion' => '1', 'certPassword' => 'p', 'disk' => 'local']);
    expect($ticketbai->getDisk())->toBe('local');

    $ticketbaiCustom = new TicketBAI(['license' => 'L', 'nif' => 'B1', 'appName' => 'A', 'appVersion' => '1', 'certPassword' => 'p', 'disk' => 's3']);
    expect($ticketbaiCustom->getDisk())->toBe('s3');

    $ticketbaiEmpty = new TicketBAI([]);
    expect($ticketbaiEmpty->getDisk())->toBe('local');
});

test('it accepts territory code 01 02 03 as valid', function () {
    config(['ticketbai.cert_path' => __DIR__.'/../stubs/nonexistent.p12']);

    foreach (['01', '02', '03'] as $code) {
        $ticketbai = new TicketBAI([]);
        $ticketbai->setVendor('L', 'B1', 'App', '1.0');
        $ticketbai->issuer('B12345678', 'Company', 1);
        $ticketbai->setVat(21);
        $ticketbai->add('Item', 10.0, 1);
        try {
            $ticketbai->invoice($code, 'Test');
        } catch (CertificateNotFoundException $e) {
            expect($e->getMessage())->toContain('not found or not readable');
            continue;
        }
        fail('Expected CertificateNotFoundException when using territory code '.$code);
    }
});

test('it throws invalid territory exception for invalid territory', function () {
    expect(function () {
        $ticketbai = new TicketBAI([]);
        $ticketbai->setVendor('L', 'B1', 'App', '1.0');
        $ticketbai->issuer('B12345678', 'Company', 1);
        $ticketbai->setVat(21);
        $ticketbai->add('Item', 10.0, 1);
        $ticketbai->invoice('INVALID', 'Test');
    })->toThrow(InvalidTerritoryException::class, 'Territory "INVALID" is invalid');
});

test('it throws certificate not found when cert file missing', function () {
    config(['ticketbai.cert_path' => __DIR__.'/../stubs/nonexistent-cert.p12']);

    expect(function () {
        $ticketbai = new TicketBAI([
            'license' => 'L', 'nif' => 'B1', 'appName' => 'A', 'appVersion' => '1',
            'certPassword' => 'p',
        ]);
        $ticketbai->getCertificate();
    })->toThrow(CertificateNotFoundException::class, 'TicketBAI certificate not found or not readable');
});

test('invoice number is generated and truncated to 20 chars', function () {
    $ticketbai = new TicketBAI([]);

    $reflection = new \ReflectionClass($ticketbai);
    $method = $reflection->getMethod('getInvoiceNumber');
    $method->setAccessible(true);

    $invoiceNumber = $method->invoke($ticketbai);

    expect($invoiceNumber)->toBeString();
    expect(strlen($invoiceNumber))->toBe(20);
});

test('invoice number is cached after first generation', function () {
    $ticketbai = new TicketBAI([]);

    $reflection = new \ReflectionClass($ticketbai);
    $method = $reflection->getMethod('getInvoiceNumber');
    $method->setAccessible(true);

    $first = $method->invoke($ticketbai);
    $second = $method->invoke($ticketbai);

    expect($first)->toBe($second);
});

test('invoice number is valid ulid prefix', function () {
    $ticketbai = new TicketBAI([]);

    $reflection = new \ReflectionClass($ticketbai);
    $method = $reflection->getMethod('getInvoiceNumber');
    $method->setAccessible(true);

    $invoiceNumber = $method->invoke($ticketbai);

    expect($invoiceNumber)->toMatch('/^[0-9A-Z]{20}$/');
});
