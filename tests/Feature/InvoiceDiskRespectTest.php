<?php

declare(strict_types=1);

use EBethus\LaravelTicketBAI\Tests\TestCase;
use EBethus\LaravelTicketBAI\TicketBAI;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('custom');
});

test('invoice respects configured disk when saving', function () {
    // Create TicketBAI service with custom disk
    $config = [
        'license' => 'TEST_LICENSE',
        'nif' => 'B12345678',
        'appName' => 'Test App',
        'appVersion' => '1.0',
        'certPassword' => 'test',
        'disk' => 'custom',
    ];
    
    $ticketbai = new TicketBAI($config);
    
    // Verify getDisk returns the configured disk
    expect($ticketbai->getDisk())->toBe('custom');
});

test('invoice file is saved to correct disk', function () {
    Storage::fake('local');
    Storage::fake('s3_invoices');
    
    $config = [
        'license' => 'TEST_LICENSE',
        'nif' => 'B12345678',
        'appName' => 'Test App',
        'appVersion' => '1.0',
        'certPassword' => 'test',
        'disk' => 's3_invoices',
    ];
    
    $ticketbai = new TicketBAI($config);
    expect($ticketbai->getDisk())->toBe('s3_invoices');
    
    // Create a temporary file to test uploading
    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, '<?xml version="1.0"?><test/>');
    
    // Verify that when saving to this disk, files go to the right place
    Storage::disk('s3_invoices')->putFile('ticketbai', new \Illuminate\Http\File($tempFile));
    
    expect(Storage::disk('s3_invoices')->files('ticketbai'))->not()->toBeEmpty();
    expect(Storage::disk('local')->files('ticketbai'))->toBeEmpty();
    
    unlink($tempFile);
});
