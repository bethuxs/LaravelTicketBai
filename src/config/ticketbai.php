<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Certificate Configuration
    |--------------------------------------------------------------------------
    |
    | Path to your X.509 certificate (.p12 or .pem format).
    | Can be:
    | - Relative path: resolved from storage_path() (e.g., 'certificado.p12')
    | - Absolute path: used as-is (e.g., '/etc/certs/ticketbai.p12' or 'C:\certs\cert.p12')
    |
    | Set via environment variable: TICKETBAI_CERT_PATH
    |
    */
    'cert_path' => env('TICKETBAI_CERT_PATH', 'certificado.p12'),

    /*
    |--------------------------------------------------------------------------
    | TicketBAI Payload Key in JSON "data" Column
    |--------------------------------------------------------------------------
    |
    | The TicketBAI signature and territory are always stored in the generic
    | `data` JSON column under this key (for signature chaining/"encadenamiento").
    | The file path is always stored in the `path` column separately.
    |
    | Default: 'ticketbai'
    | WARNING: Must not be empty. Do NOT set TICKETBAI_DATA_KEY="" in .env
    |
    | Example with custom key:
    |   'data_key' => 'my_invoices'
    |   → Signature stored at: data->my_invoices->signature
    |   → Territory stored at: data->my_invoices->territory
    |
    */
    'data_key' => env('TICKETBAI_DATA_KEY', 'ticketbai'),

    /*
    |--------------------------------------------------------------------------
    | Database Table Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your invoices table name and column mappings.
    | This allows flexible integration with existing table structures.
    |
    | Supported internal columns:
    | ├─ issuer       (required) - Who issued the invoice (user/seller ID)
    | ├─ number       (required) - Invoice number/reference code
    | ├─ path         (required) - File path where signed XML is stored
    | ├─ data         (required) - JSON column for TicketBAI data
    | ├─ territory    (optional) - Invoice territory code (Araba/Bizkaia/Gipuzkoa)
    | ├─ signature    (optional) - XML digital signature
    | ├─ sent         (optional) - Timestamp when sent to TicketBAI API
    | ├─ created_at   (optional) - Creation timestamp
    | └─ updated_at   (optional) - Update timestamp
    |
    | Set to NULL to skip storing that value (e.g., 'territory' => NULL)
    | meaning the data will only be stored in the JSON 'data' column.
    |
    */
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

