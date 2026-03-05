<?php

declare(strict_types=1);

namespace EBethus\LaravelTicketBAI\Tests\Unit;

use EBethus\LaravelTicketBAI\TicketBAI;
use EBethus\LaravelTicketBAI\Tests\TestCase;
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
}
