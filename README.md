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
- ✅ Optional columns (signature, data, territory) for maximum flexibility
- ✅ **Encadenamiento**: firma y territorio siempre en columna JSON `data` bajo clave configurable (por defecto `ticketbai`)
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

### Configure Environment Variables

After installation, add your TicketBAI credentials and certificate path to your `.env` file (see [Configuration](#configuration) section).

### Run Migrations

If you want to use the default invoice table structure:

```bash
php artisan migrate
```

**Note:** You can also use your own table structure by configuring column mappings via environment variables.

## Usage

### Quick Start: Environment Variables Only

The simplest approach is to **use environment variables only** — no need to publish the config file:

```env
# .env file - TicketBAI Credentials
TICKETBAI_LICENSE=TB12345678
TICKETBAI_NIF=B1111111A
TICKETBAI_APP_NAME=My Application
TICKETBAI_APP_VERSION=1.0.0
TICKETBAI_CERT_PASSWORD=your_p12_password
TICKETBAI_CERT_PATH=certificado.p12

# TicketBAI Data Storage
TICKETBAI_TABLE_NAME=invoices
TICKETBAI_DISK=local
TICKETBAI_DATA_KEY=ticketbai

# Optional: Customize column mappings (defaults work for standard Laravel tables)
# TICKETBAI_COLUMN_ISSUER=issuer
# TICKETBAI_COLUMN_NUMBER=provider_reference
# TICKETBAI_COLUMN_PATH=path
# TICKETBAI_COLUMN_DATA=data
```

In `config/services.php`:

```php
'ticketbai' => [
    'license' => env('TICKETBAI_LICENSE'),
    'nif' => env('TICKETBAI_NIF'),
    'appName' => env('TICKETBAI_APP_NAME'),
    'appVersion' => env('TICKETBAI_APP_VERSION'),
    'certPassword' => env('TICKETBAI_CERT_PASSWORD'),
    'disk' => env('TICKETBAI_DISK', 'local'),
],
```

### Advanced: Custom Table Mapping

If your invoices table has **different column names**, use environment variables to map them:

**Example: Vivetix custom table structure**

```env
# In your .env file
TICKETBAI_TABLE_NAME=invoices
TICKETBAI_COLUMN_ISSUER=user_id
TICKETBAI_COLUMN_NUMBER=provider_reference
TICKETBAI_COLUMN_PATH=path
TICKETBAI_COLUMN_DATA=data
TICKETBAI_COLUMN_TERRITORY=
TICKETBAI_COLUMN_SIGNATURE=
TICKETBAI_COLUMN_SENT=
```

This maps:
- Internal `issuer` → Table column `user_id`
- Internal `number` → Table column `provider_reference`
- `territory` and `signature` → Only stored in JSON `data` column (not as separate DB columns)

### Publishing Configuration (Optional)

If you need **fine-grained control** or want to **document your custom setup**, publish the config file:

```bash
php artisan vendor:publish --tag=ticketbai-config
```

This creates `config/ticketbai.php` which you can customize directly:

```php
// config/ticketbai.php
return [
    'cert_path' => env('TICKETBAI_CERT_PATH', 'certificado.p12'),
    'data_key' => env('TICKETBAI_DATA_KEY', 'ticketbai'),
    
    'table' => [
        'name' => env('TICKETBAI_TABLE_NAME', 'invoices'),
        'columns' => [
            'issuer'     => env('TICKETBAI_COLUMN_ISSUER', 'issuer'),
            'number'     => env('TICKETBAI_COLUMN_NUMBER', 'provider_reference'),
            'territory'  => env('TICKETBAI_COLUMN_TERRITORY', null),
            'signature'  => env('TICKETBAI_COLUMN_SIGNATURE', null),
            'path'       => env('TICKETBAI_COLUMN_PATH', 'path'),
            'data'       => env('TICKETBAI_COLUMN_DATA', 'data'),
            'sent'       => env('TICKETBAI_COLUMN_SENT', null),
            'created_at' => env('TICKETBAI_COLUMN_CREATED_AT', 'created_at'),
            'updated_at' => env('TICKETBAI_COLUMN_UPDATED_AT', 'updated_at'),
        ],
    ],
];
```

### Certificate Path

The certificate path can be:
- **Relative**: `certificado.p12` → Resolved to `storage/certificado.p12`
- **Absolute Linux**: `/etc/certs/ticketbai.p12`
- **Absolute Windows**: `C:\certs\ticketbai.p12`

Set via: `TICKETBAI_CERT_PATH` environment variable

**Validation:** The package automatically:
- Strips leading/trailing whitespace from the path
- Checks that the file exists
- Verifies it's readable
- Throws `CertificateNotFoundException` if invalid

### Certificate Signature Prevention (barnetik/ticketbai Patch)

This package automatically applies a security patch to `barnetik/ticketbai` that validates certificates before signing. The patch:

- ✅ Validates P12 file existence and content before parsing
- ✅ Checks `openssl_pkcs12_read()` return value and throws descriptive exceptions
- ✅ Detects incorrect passwords and corrupt P12 files
- ✅ Includes OpenSSL error messages for debugging
- ✅ Prevents "Trying to access array offset on null" errors in PHP 8+

**No additional configuration needed** — the patch applies automatically during `composer install`.

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

The default migration creates an `invoices` table with columns `issuer`, `provider_reference`, `path`, `data`, `sent`, and timestamps. TicketBAI stores signature and territory in the `data` JSON column under the key `ticketbai`.

### Custom Table Configuration

If you use **your own table** with different column names (e.g. `transaction_id` instead of `issuer`), override the mappings via environment variables. Example: `TICKETBAI_COLUMN_ISSUER=transaction_id`, `TICKETBAI_COLUMN_NUMBER=invoice_number`, `TICKETBAI_COLUMN_SENT=attempted_at`.

Configure column mappings in `config/ticketbai.php`:

```php
'table' => [
    'name' => env('TICKETBAI_TABLE_NAME', 'invoices'),
    'columns' => [
        // Defaults match the default migration. For your own table use env, e.g.:
        // TICKETBAI_COLUMN_ISSUER=transaction_id, TICKETBAI_COLUMN_NUMBER=invoice_number
        'issuer' => env('TICKETBAI_COLUMN_ISSUER', 'issuer'),
        'number' => env('TICKETBAI_COLUMN_NUMBER', 'provider_reference'),
        'territory' => env('TICKETBAI_COLUMN_TERRITORY', 'territory'),
        'signature' => env('TICKETBAI_COLUMN_SIGNATURE', 'signature'),
        'path' => env('TICKETBAI_COLUMN_PATH', 'path'),
        'data' => env('TICKETBAI_COLUMN_DATA', 'data'),
        'sent' => env('TICKETBAI_COLUMN_SENT', 'sent'),
        'created_at' => env('TICKETBAI_COLUMN_CREATED_AT', 'created_at'),
        'updated_at' => env('TICKETBAI_COLUMN_UPDATED_AT', 'updated_at'),
    ],
],
```

### Environment Variables for Column Mapping

Use these only when you have a **custom table** with different column names:

```env
TICKETBAI_TABLE_NAME=invoices
TICKETBAI_COLUMN_ISSUER=transaction_id
TICKETBAI_COLUMN_NUMBER=provider_reference
TICKETBAI_COLUMN_TERRITORY=territory
TICKETBAI_COLUMN_SIGNATURE=signature
TICKETBAI_COLUMN_PATH=path
TICKETBAI_COLUMN_DATA=data
TICKETBAI_COLUMN_SENT=attempted_at
TICKETBAI_COLUMN_CREATED_AT=created_at
TICKETBAI_COLUMN_UPDATED_AT=updated_at
```

### TicketBAI payload in `data` (required for chaining)

Signature and territory are **always** stored in the JSON `data` column under a configurable key so that encadenamiento (signature chaining) works. Default key: `ticketbai`. Set in `.env` or config:

```env
TICKETBAI_DATA_KEY=ticketbai
```

The package stores and reads `signature` (first 100 chars) and `territory` under `data->ticketbai`. Path stays in the `path` column. Example:

```json
{
  "ticketbai": {
    "signature": "first 100 chars of chain signature",
    "territory": "02"
  },
  "order_id": 12345
}
```

Your table needs: `id`, `issuer`, `number`, **`path`** (file path), **`data`** (JSON), `sent`, `created_at`, `updated_at`. Other providers can use other keys in `data` (e.g. `data->other_provider`).

### Optional column name override

- **`data`**: Set to `null` or empty to use the default column name `'data'`.

### Required Columns

- **`path`**: Required - stores the signed XML file path (filesystem).
- **`data`**: Required - JSON column where TicketBAI stores signature and territory under `TICKETBAI_DATA_KEY`.

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

- `$territory`: Territory code: `'ARABA'`, `'BIZKAIA'`, or `'GIPUZKOA'` (or numeric codes `'01'`, `'02'`, `'03'`)
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
echo $model->provider_reference; // Invoice number (default column name)
echo $model->path;               // XML file path
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

Resend requires **territory** and **path**: territory is read from `data[data_key]` (default `data->ticketbai`), path from the path column.

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
