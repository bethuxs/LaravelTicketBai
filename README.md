# Laravel TicketBAI

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://www.php.net/)

A Laravel package for generating and submitting TicketBAI (Ticket BAI) invoices for the Basque Country (Euskadi), Spain. This package provides a flexible and configurable solution for integrating TicketBAI compliance into your Laravel application.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Database Configuration](#database-configuration)
- [API Reference](#api-reference)
- [Examples](#examples)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Features

- ✅ Generate TicketBAI-compliant invoices
- ✅ Automatic invoice signing with X.509 certificates
- ✅ Queue-based invoice submission to TicketBAI API
- ✅ Flexible database table and column configuration
- ✅ Support for custom table structures
- ✅ Optional columns (signature, data) for maximum flexibility
- ✅ QR code generation for invoices
- ✅ Support for multiple territories (Araba, Bizkaia, Gipuzkoa)
- ✅ Automatic fingerprint calculation from previous invoices
- ✅ Artisan command to resend failed/pending invoices (`ticketbai:resend`)

## Requirements

- PHP >= 8.2
- Laravel >= 8.0 (tested with Laravel 10.x and 11.x)
- [barnetik/ticketbai](https://github.com/barnetik/ticketbai) package
- X.509 certificate (.p12 file) for signing invoices
- TicketBAI license and credentials

## Installation

Install the package via Composer:

```bash
composer require ebethus/laravel-ticketbai
```

### Publish Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=ticketbai-config
```

This will create `config/ticketbai.php` where you can configure table and column mappings.

### Run Migrations

If you want to use the default invoice table structure:

```bash
php artisan migrate
```

**Note:** You can also use your own table structure by configuring column mappings (see [Database Configuration](#database-configuration)).

## Configuration

### Service Configuration

Add your TicketBAI credentials to `config/services.php`:

```php
'ticketbai' => [
    'license' => env('TICKETBAI_LICENSE', ''),
    'nif' => env('TICKETBAI_NIF', ''),
    'appName' => env('TICKETBAI_APP_NAME', ''),
    'appVersion' => env('TICKETBAI_APP_VERSION', ''),
    'certPassword' => env('TICKETBAI_CERT_PASSWORD', ''),
    'disk' => env('TICKETBAI_DISK', 'local'),
],
```

### Environment Variables

Add these to your `.env` file:

```env
TICKETBAI_LICENSE=TB12345678
TICKETBAI_NIF=B1111111
TICKETBAI_APP_NAME=My Application
TICKETBAI_APP_VERSION=1.0
TICKETBAI_CERT_PASSWORD=your_certificate_password
TICKETBAI_DISK=local
TICKETBAI_CERT_PATH=certificado.p12
```

Use `TICKETBAI_CERT_PATH` to override the certificate path. It can be a path relative to `storage_path()` (e.g. `certificado.p12` for `storage/certificado.p12`) or an absolute path (e.g. `/etc/certs/ticketbai.p12` on Linux).

### Certificate Setup

By default the package looks for the X.509 certificate (`.p12`) at `storage/certificado.p12`. Set `TICKETBAI_CERT_PATH` in your `.env` to use a different path (relative to `storage_path()` or absolute). The certificate is used to sign invoices before submission.

## Usage

### Basic Example

```php
use EBethus\LaravelTicketBAI\TicketBAI;

// Get TicketBAI instance (configured via service provider)
$ticketbai = app('ticketbai');

// Or use the facade
use TicketBAI;

// Set issuer information
$ticketbai->issuer(
    nif: 'B12345678',
    name: 'Company Name',
    idIssuer: 1,
    serie: '' // Optional
);

// Set VAT percentage
$ticketbai->setVat(21); // 21% VAT

// Add invoice items
$ticketbai->add(
    desc: 'Product description',
    unitPrice: 100.00,
    q: 2,
    discount: 0 // Optional
);

// Generate and sign invoice
$qrUrl = $ticketbai->invoice(
    territory: 'BIZKAIA', // or 'ARABA', 'GIPUZKOA'
    description: 'Invoice description'
);

// The invoice is automatically saved and queued for submission
// $qrUrl contains the QR code URL for the invoice
```

### Using Dependency Injection

```php
use EBethus\LaravelTicketBAI\TicketBAI;

class InvoiceController extends Controller
{
    public function __construct(
        protected TicketBAI $ticketbai
    ) {}

    public function create(Request $request)
    {
        $this->ticketbai->issuer(
            nif: $request->nif,
            name: $request->company_name,
            idIssuer: $request->issuer_id
        );

        $this->ticketbai->setVat(21);

        foreach ($request->items as $item) {
            $this->ticketbai->add(
                desc: $item['description'],
                unitPrice: $item['price'],
                q: $item['quantity'],
                discount: $item['discount'] ?? 0
            );
        }

        $qrUrl = $this->ticketbai->invoice(
            territory: 'BIZKAIA',
            description: $request->description
        );

        return response()->json(['qr_url' => $qrUrl]);
    }
}
```

### Adding Extra Data

You can attach additional JSON data to invoices:

```php
$ticketbai->data([
    'order_id' => 12345,
    'customer_id' => 67890,
    'custom_field' => 'value'
]);
```

This data will be stored in the `data` column if configured (see [Database Configuration](#database-configuration)).

## Database Configuration

The library supports flexible table and column configuration, allowing you to use your existing database structure.

### Default Table Structure

The default migration creates an `invoices` table with:

- `id` - Primary key
- `path` - File path of signed XML (required)
- `issuer` - Issuer ID (required)
- `number` - Invoice number (required)
- `signature` - TicketBAI signature (optional)
- `data` - Additional JSON data (optional)
- `status` - Status field
- `sent` - Timestamp when invoice was sent
- `created_at`, `updated_at` - Timestamps

### Custom Table Configuration

If you have a different table structure, configure column mappings in `config/ticketbai.php`:

```php
'table' => [
    'name' => env('TICKETBAI_TABLE_NAME', 'invoices'),
    
    'columns' => [
        'issuer' => env('TICKETBAI_COLUMN_ISSUER', 'transaction_id'),
        'number' => env('TICKETBAI_COLUMN_NUMBER', 'provider_reference'),
        'signature' => env('TICKETBAI_COLUMN_SIGNATURE', null), // Optional
        'path' => env('TICKETBAI_COLUMN_PATH', 'path'),
        'data' => env('TICKETBAI_COLUMN_DATA', null), // Optional
        'sent' => env('TICKETBAI_COLUMN_SENT', 'attempted_at'),
        'created_at' => env('TICKETBAI_COLUMN_CREATED_AT', 'created_at'),
        'updated_at' => env('TICKETBAI_COLUMN_UPDATED_AT', 'updated_at'),
    ],
],
```

### Environment Variables for Column Mapping

```env
TICKETBAI_TABLE_NAME=invoices
TICKETBAI_COLUMN_ISSUER=transaction_id
TICKETBAI_COLUMN_NUMBER=provider_reference
TICKETBAI_COLUMN_SIGNATURE=  # Leave empty to disable
TICKETBAI_COLUMN_PATH=path
TICKETBAI_COLUMN_DATA=  # Leave empty to disable
TICKETBAI_COLUMN_SENT=attempted_at
TICKETBAI_COLUMN_CREATED_AT=created_at
TICKETBAI_COLUMN_UPDATED_AT=updated_at
```

### Optional Columns

- **`signature`**: Set to `null` or empty string to disable. The library will work without storing the signature.
- **`data`**: Set to `null` or empty string to disable. Only stores data if you call the `data()` method and the column is configured.

### Required Columns

- **`path`**: Required - stores the signed XML file path
- **`issuer`**: Required - stores the issuer ID
- **`number`**: Required - stores the invoice number
- **`created_at`**, **`updated_at`**: Required - standard Laravel timestamps

## API Reference

### TicketBAI Class

#### `issuer(string $nif, string $name, int $idIssuer, string $serie = '')`

Set the issuer information for the invoice.

- `$nif`: Tax identification number (NIF/CIF)
- `$name`: Company name
- `$idIssuer`: Internal issuer ID (used for database storage)
- `$serie`: Optional invoice series

#### `setVat(float $vatPerc)`

Set the VAT percentage for invoice items.

- `$vatPerc`: VAT percentage (e.g., 21 for 21%)

#### `add(string $desc, float $unitPrice, float $q, float $discount = null)`

Add an item to the invoice.

- `$desc`: Item description
- `$unitPrice`: Unit price (including VAT)
- `$q`: Quantity
- `$discount`: Optional discount amount

#### `data(mixed $data)`

Attach additional JSON data to the invoice.

- `$data`: Array or object to be stored as JSON

#### `invoice(string $territory, string $description)`

Generate, sign, and save the invoice. Returns the QR code URL.

- `$territory`: Territory code (`'ARABA'`, `'BIZKAIA'`, or `'GIPUZKOA'`)
- `$description`: Invoice description

**Returns:** `string` - QR code URL

#### `getModel()`

Get the Eloquent model instance for the saved invoice.

**Returns:** `Invoice`

#### `getTBAI()`

Get the underlying TicketBAI object from the barnetik/ticketbai package.

**Returns:** `\Barnetik\Tbai\TicketBai`

## Examples

### Complete Invoice Example

```php
use EBethus\LaravelTicketBAI\TicketBAI;

$ticketbai = app('ticketbai');

// Configure issuer
$ticketbai->issuer(
    nif: 'B12345678',
    name: 'My Company S.L.',
    idIssuer: 1
);

// Set VAT
$ticketbai->setVat(21);

// Add items
$ticketbai->add('Product A', 50.00, 2, 0);
$ticketbai->add('Product B', 30.00, 1, 5.00);

// Add extra data
$ticketbai->data([
    'order_id' => 12345,
    'customer_email' => 'customer@example.com'
]);

// Generate invoice
$qrUrl = $ticketbai->invoice(
    territory: 'BIZKAIA',
    description: 'Order #12345'
);

echo "QR Code: $qrUrl";
```

### Using with Custom Table

```php
// In config/ticketbai.php or .env
// TICKETBAI_TABLE_NAME=my_invoices
// TICKETBAI_COLUMN_ISSUER=user_id
// TICKETBAI_COLUMN_NUMBER=invoice_ref
// TICKETBAI_COLUMN_SIGNATURE=  (empty, disabled)
// TICKETBAI_COLUMN_DATA=  (empty, disabled)

$ticketbai = app('ticketbai');
$ticketbai->issuer('B12345678', 'Company', 1);
$ticketbai->setVat(21);
$ticketbai->add('Item', 100, 1);
$qrUrl = $ticketbai->invoice('BIZKAIA', 'Invoice');
```

### Accessing Saved Invoice

```php
$ticketbai = app('ticketbai');
// ... configure and generate invoice ...

$model = $ticketbai->getModel();
echo $model->number; // Invoice number
echo $model->path;   // XML file path
```

## Testing

Run the test suite:

```bash
composer test
```

Or with PHPUnit directly:

```bash
./vendor/bin/phpunit
```

Optional: run static analysis (PHPStan) and code style (Laravel Pint):

```bash
composer analyse   # PHPStan
composer format   # Pint (fixes style)
```

## Queue Configuration

Invoice submission is **asynchronous**: after generating and signing an invoice, the package dispatches an `InvoiceSend` job to the Laravel queue. You must have at least one queue worker running for invoices to be sent to the TicketBAI API:

```bash
php artisan queue:work
```

- **Production:** Use a process manager (e.g. Supervisor) to keep `queue:work` running.
- **Testing / sync:** If you use `QUEUE_CONNECTION=sync`, jobs run immediately in the same process (no worker needed, but slower and no retries).

The `InvoiceSend` job submits the invoice to the TicketBAI API and updates the `sent` timestamp on success.

## Resending Failed or Pending Invoices

Invoices that were not sent (e.g. API error or worker down) have `sent = null`. To re-queue them for sending:

```bash
# List and resend all pending invoices
php artisan ticketbai:resend --all

# Resend a single invoice by ID
php artisan ticketbai:resend --id=123

# Dry run: only list what would be resent
php artisan ticketbai:resend --all --dry-run
```

Resend requires the `territory` column to be present and configured (it is added by the package migration and filled when generating invoices). If you use a custom table, add a `territory` column and set `TICKETBAI_COLUMN_TERRITORY` in config.

## Troubleshooting

### Certificate Errors

- Ensure `storage/certificado.p12` exists and is readable
- Verify the certificate password is correct
- Check file permissions

### Database Column Errors

- Verify column mappings in `config/ticketbai.php`
- Ensure required columns exist in your table
- Set optional columns to `null` if not needed

### Queue Issues

- Ensure queue worker is running
- Check queue connection configuration
- Review failed jobs: `php artisan queue:failed`

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Credits

- Built on top of [barnetik/ticketbai](https://github.com/barnetik/ticketbai)
- Developed by [EBethus](https://github.com/ebethus)

## Support

For issues and feature requests, please use the [GitHub issue tracker](https://github.com/ebethus/laravel-ticketbai/issues).
