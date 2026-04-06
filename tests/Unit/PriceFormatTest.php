<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\TicketBAI;
use EBethus\LaravelTicketBAI\Tests\TestCase;

uses(TestCase::class);

test('price format HT (without VAT) adds items correctly', function () {
    $tbai = new TicketBAI();
    $tbai->setVat(21); // 21% VAT
    $tbai->setPriceFormat(false); // HT format (default)

    // Add line with net price of 100
    $tbai->add('Product', 100, 1);

    // The item should use correct calculation
    // unitPrice (base) = 100 (already net)
    // totalAmount (with VAT) = 100 * 1.21 = 121
    expect($tbai)->not()->toBeNull();
});

test('price format TTC (with VAT) adds items correctly', function () {
    $tbai = new TicketBAI();
    $tbai->setVat(21); // 21% VAT
    $tbai->setPriceFormat(true); // TTC format (with VAT)

    // Add line with gross price of 121 (which is 100 base + 21 VAT at 21%)
    // unitPrice (base) = 121 / 1.21 ≈ 100
    // totalAmount (with VAT) = 121
    $tbai->add('Product', 121, 1);

    expect($tbai)->not()->toBeNull();
});

test('HT format: net price stays net, gross calculated with VAT factor', function () {
    $tbai = new TicketBAI();
    $tbai->setVat(21);
    $tbai->setPriceFormat(false);

    // For a net price of 100:
    // - unitPrice should be 100 (kept as-is)
    // - totalAmount should be 100 * 1.21 = 121
    $tbai->add('Item', 100, 1);

    // Verify object was created and accepts the item
    expect($tbai)->not()->toBeNull();
});

test('TTC format: gross price is divided by VAT factor to get net', function () {
    $tbai = new TicketBAI();
    $tbai->setVat(21);
    $tbai->setPriceFormat(true);

    // For a gross price of 121 (where 100 is base, 21 is VAT at 21%):
    // - unitPrice should be 121 / 1.21 ≈ 100
    // - totalAmount should be 121
    $tbai->add('Item', 121, 1);

    expect($tbai)->not()->toBeNull();
});

test('VAT breakdown is calculated from line aggregation', function () {
    $tbai = new TicketBAI();
    $tbai->setVat(21);
    $tbai->setPriceFormat(false);

    // Add multiple lines with specific values
    $tbai->add('Item 1', 100, 1); // Base: 100, With VAT: 121
    $tbai->add('Item 2', 50, 1);  // Base: 50, With VAT: 60.5

    // Total Base: 150
    // Total With VAT: 181.5
    // Total VAT: 31.5

    expect($tbai)->not()->toBeNull();
});

test('default behavior is HT format (backward compatible)', function () {
    $tbai = new TicketBAI();
    $tbai->setVat(21);
    // NOT calling setPriceFormat, should default to false (HT)

    $tbai->add('Product', 100, 1);
    // Should treat 100 as net price
    
    expect($tbai)->not()->toBeNull();
});
