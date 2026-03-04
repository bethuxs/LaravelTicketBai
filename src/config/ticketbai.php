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

    'table' => [
        'name' => env('TICKETBAI_TABLE_NAME', 'invoices'),
        
        /*
        |--------------------------------------------------------------------------
        | Column Mappings
        |--------------------------------------------------------------------------
        |
        | Map the internal column names to your actual database columns.
        | The library uses these internal names:
        | - issuer: The ID of the issuer (transaction_id in your case)
        | - number: The invoice number
        | - signature: The TicketBAI signature (OPTIONAL - set to null to disable)
        | - path: The file path where the signed XML is stored
        | - data: Additional JSON data
        | - sent: Timestamp when the invoice was sent
        | - created_at: Creation timestamp
        | - updated_at: Update timestamp
        |
        */
        'columns' => [
            // Map to your transaction_id column
            'issuer' => env('TICKETBAI_COLUMN_ISSUER', 'transaction_id'),
            
            // Map to your provider_reference column
            'number' => env('TICKETBAI_COLUMN_NUMBER', 'provider_reference'),
            
            // Signature column - OPTIONAL, can be null or omitted if you don't need to store it
            // Set to null or empty string to disable signature storage
            'signature' => env('TICKETBAI_COLUMN_SIGNATURE', null),
            
            // Path column - you may need to add this column if it doesn't exist
            // This stores the file path of the signed XML
            'path' => env('TICKETBAI_COLUMN_PATH', 'path'),
            
            // Data column - you may map this to 'message' or add a 'data' JSON column
            'data' => env('TICKETBAI_COLUMN_DATA', 'data'),
            
            // Sent timestamp - you may map this to 'attempted_at' or add a 'sent' column
            'sent' => env('TICKETBAI_COLUMN_SENT', 'attempted_at'),
            
            // Standard Laravel timestamps
            'created_at' => env('TICKETBAI_COLUMN_CREATED_AT', 'created_at'),
            'updated_at' => env('TICKETBAI_COLUMN_UPDATED_AT', 'updated_at'),
        ],
    ],
];
