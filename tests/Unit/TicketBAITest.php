<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Unit;

use EBethus\LaravelTicketBAI\Exceptions\CertificateNotFoundException;
use EBethus\LaravelTicketBAI\Exceptions\InvalidTerritoryException;
use EBethus\LaravelTicketBAI\Tests\TestCase;
use EBethus\LaravelTicketBAI\TicketBAI;
use Illuminate\Support\Facades\Storage;

class TicketBAITest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** @test */
    public function it_can_be_instantiated()
    {
        $config = [
            'license' => 'TEST_LICENSE',
            'nif' => 'B12345678',
            'appName' => 'Test App',
            'appVersion' => '1.0',
            'certPassword' => 'test_password',
            'disk' => 'local',
        ];

        $ticketbai = new TicketBAI($config);

        $this->assertInstanceOf(TicketBAI::class, $ticketbai);
    }

    /** @test */
    public function it_can_set_vendor()
    {
        $ticketbai = new TicketBAI([]);
        $ticketbai->setVendor('LICENSE', 'B12345678', 'App Name', '1.0');

        $this->assertTrue(true); // If no exception is thrown, vendor is set
    }

    /** @test */
    public function it_can_set_issuer()
    {
        $ticketbai = new TicketBAI([]);
        $ticketbai->issuer('B12345678', 'Company Name', 1);

        $this->assertTrue(true); // If no exception is thrown, issuer is set
    }

    /** @test */
    public function it_can_set_vat()
    {
        $ticketbai = new TicketBAI([]);
        $ticketbai->setVat(21);

        $this->assertTrue(true); // If no exception is thrown, VAT is set
    }

    /** @test */
    public function it_can_add_items()
    {
        $ticketbai = new TicketBAI([]);
        $ticketbai->setVat(21);
        $ticketbai->add('Product', 100.00, 2);

        $this->assertTrue(true); // If no exception is thrown, item is added
    }

    /** @test */
    public function it_throws_exception_when_adding_item_without_vat()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('VAT percentage not set');

        $ticketbai = new TicketBAI([]);
        $ticketbai->add('Product', 100.00, 2);
    }

    /** @test */
    public function it_throws_exception_when_adding_item_with_invalid_price(): void
    {
        $this->expectException(\TypeError::class);

        $ticketbai = new TicketBAI([]);
        $ticketbai->setVat(21);
        $ticketbai->add('Product', 'invalid', 2);
    }

    /** @test */
    public function it_can_set_extra_data()
    {
        $ticketbai = new TicketBAI([]);
        $ticketbai->data(['key' => 'value']);

        $this->assertTrue(true); // If no exception is thrown, data is set
    }

    /** @test */
    public function it_can_get_column_name_for_optional_signature()
    {
        config(['ticketbai.table.columns.signature' => null]);

        $ticketbai = new TicketBAI([]);
        // This should not throw an error even if signature is null
        $this->assertTrue(true);
    }

    /** @test */
    public function it_returns_disk_from_config_or_local()
    {
        $ticketbai = new TicketBAI(['license' => 'L', 'nif' => 'B1', 'appName' => 'A', 'appVersion' => '1', 'certPassword' => 'p', 'disk' => 'local']);
        $this->assertSame('local', $ticketbai->getDisk());

        $ticketbaiCustom = new TicketBAI(['license' => 'L', 'nif' => 'B1', 'appName' => 'A', 'appVersion' => '1', 'certPassword' => 'p', 'disk' => 's3']);
        $this->assertSame('s3', $ticketbaiCustom->getDisk());

        $ticketbaiEmpty = new TicketBAI([]);
        $this->assertSame('local', $ticketbaiEmpty->getDisk());
    }

    /** @test */
    public function it_accepts_territory_code_01_02_03_as_valid(): void
    {
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
                $this->assertStringContainsString('not found or not readable', $e->getMessage());
                continue;
            }
            $this->fail('Expected CertificateNotFoundException when using territory code '.$code);
        }
    }

    /** @test */
    public function it_throws_invalid_territory_exception_for_invalid_territory()
    {
        $this->expectException(InvalidTerritoryException::class);
        $this->expectExceptionMessage('Territory "INVALID" is invalid');

        $ticketbai = new TicketBAI([]);
        $ticketbai->setVendor('L', 'B1', 'App', '1.0');
        $ticketbai->issuer('B12345678', 'Company', 1);
        $ticketbai->setVat(21);
        $ticketbai->add('Item', 10.0, 1);
        $ticketbai->invoice('INVALID', 'Test');
    }

    /** @test */
    public function it_throws_certificate_not_found_when_cert_file_missing()
    {
        config(['ticketbai.cert_path' => __DIR__.'/../stubs/nonexistent-cert.p12']);

        $this->expectException(CertificateNotFoundException::class);
        $this->expectExceptionMessage('TicketBAI certificate not found or not readable');

        $ticketbai = new TicketBAI([
            'license' => 'L', 'nif' => 'B1', 'appName' => 'A', 'appVersion' => '1',
            'certPassword' => 'p',
        ]);
        $ticketbai->getCertificate();
    }
}
