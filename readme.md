# Laravel TicketBAI

## Config example

```
[
    'license' => 'TB12345678',
    'nif' => 'B1111111',
    'appName' => 'Laravel Ebethus TicketBAI',
    'appVersion' => '1.0'
]
```

php artisan migrate

---

## Table and Column Configuration

The library now supports flexible table and column configuration for compatibility with different database structures.

### Publish Configuration

```bash
php artisan vendor:publish --tag=ticketbai-config
```

This will create the `config/ticketbai.php` file where you can configure:

- **Table name**: Map to your custom table
- **Column mapping**: Map the library's internal columns to your actual columns

### Configuration Example

For a table with the following structure:
- `transaction_id` (instead of `issuer`)
- `provider_reference` (instead of `number`)
- `attempted_at` (instead of `sent`)

Configure in the `config/ticketbai.php` file or via environment variables:

```php
'columns' => [
    'issuer' => 'transaction_id',
    'number' => 'provider_reference',
    'signature' => null,         // Optional - set to null if you don't need to store signature
    'path' => 'path',            // Add this column if it doesn't exist
    'data' => 'data',            // Add this column if it doesn't exist
    'sent' => 'attempted_at',
    'created_at' => 'created_at',
    'updated_at' => 'updated_at',
],
```

### Environment Variables

You can also configure via `.env`:

```
TICKETBAI_TABLE_NAME=invoices
TICKETBAI_COLUMN_ISSUER=transaction_id
TICKETBAI_COLUMN_NUMBER=provider_reference
TICKETBAI_COLUMN_SIGNATURE=  # Leave empty or set to null to disable signature storage
TICKETBAI_COLUMN_PATH=path
TICKETBAI_COLUMN_DATA=data
TICKETBAI_COLUMN_SENT=attempted_at
TICKETBAI_COLUMN_CREATED_AT=created_at
TICKETBAI_COLUMN_UPDATED_AT=updated_at
```

### Important Notes

- The `path` and `data` columns are required for the library to work correctly
- The `signature` column is **optional** - set it to `null` in the configuration if you don't need to store it
- If the `path` and `data` columns don't exist in your table, you'll need to add them or map them to compatible existing columns
- The `path` column should store the signed XML file path (string)
- The `data` column can be JSON or TEXT, depending on your structure
