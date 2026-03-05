<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Invoice Table Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the table name and column mappings for the invoice storage.
    | This allows you to use your own table structure while maintaining
    | compatibility with the TicketBAI library.
    |
    */

    /*
    | Certificate path: relative to storage_path() or absolute. E.g. 'certificado.p12' or '/etc/certs/ticketbai.p12'
    */
    'cert_path' => env('TICKETBAI_CERT_PATH', 'certificado.p12'),

    'table' => [
        'name' => env('TICKETBAI_TABLE_NAME', 'invoices'),

        /*
        |--------------------------------------------------------------------------
        | Column Mappings
        |--------------------------------------------------------------------------
        |
        | Map the internal column names to your actual database columns.
        | The library uses these internal names:
        | - signature: The TicketBAI signature (OPTIONAL - set to null to disable)
        | - path: The file path where the signed XML is stored
        | - data: Additional JSON data (OPTIONAL - set to null to disable)
        | - sent: Timestamp when the invoice was sent
        | - created_at: Creation timestamp
        | - updated_at: Update timestamp
        |
        */
        'columns' => [
            // Defaults match the package migration (issuer, number, ...).
            // If your table has different column names, set these via .env or replace the defaults below.
            // Example for custom table: issuer -> transaction_id, number -> provider_reference
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

    /*
    |--------------------------------------------------------------------------
    | Example: Custom table (e.g. Vivetix)
    |--------------------------------------------------------------------------
    | If your `invoices` table uses different column names, copy this block
    | into your app's config/ticketbai.php (after publishing) or set .env:
    |
    | TICKETBAI_COLUMN_ISSUER=transaction_id
    | TICKETBAI_COLUMN_NUMBER=provider_reference
    | TICKETBAI_COLUMN_TERRITORY=territory
    | TICKETBAI_COLUMN_PATH=path
    | ... etc (use your actual column names)
    |
    | Or in config/ticketbai.php replace defaults, e.g.:
    |   'number' => env('TICKETBAI_COLUMN_NUMBER', 'provider_reference'),
    */
];
