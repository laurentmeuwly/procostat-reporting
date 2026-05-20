<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage path
    |--------------------------------------------------------------------------
    | Absolute path where generated files will be written.
    | Defaults to storage/app/procostat-reports.
    */
    'storage_path' => env('PROCOSTAT_REPORTING_STORAGE_PATH', storage_path('app/procostat/reports')),

    /*
    |--------------------------------------------------------------------------
    | Enabled output formats
    |--------------------------------------------------------------------------
    | Comment out any format you do not need. Removing 'pdf' avoids the
    | LibreOffice dependency entirely.
    */
    'enabled_formats' => ['xlsx', 'docx', 'pptx'],

    /*
    |--------------------------------------------------------------------------
    | Node.js binary
    |--------------------------------------------------------------------------
    | Path to the node binary. On most servers 'node' is on PATH.
    | Override if using nvm or a non-standard install path.
    */
    'node_binary' => env('PROCOSTAT_NODE_BINARY', 'node'),

    /*
    |--------------------------------------------------------------------------
    | Node.js process timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'node_timeout' => (int) env('PROCOSTAT_NODE_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | LibreOffice binary (PDF generation)
    |--------------------------------------------------------------------------
    */
    'libreoffice_binary' => env('PROCOSTAT_LIBREOFFICE_BINARY', 'libreoffice'),

    /*
    |--------------------------------------------------------------------------
    | LibreOffice process timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'libreoffice_timeout' => (int) env('PROCOSTAT_LIBREOFFICE_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Stop on first error
    |--------------------------------------------------------------------------
    | When true, ReportManager::generateAll() throws on the first failed format.
    | When false (default), it continues and aggregates errors in ReportResult.
    */
    'stop_on_first_error' => (bool) env('PROCOSTAT_STOP_ON_FIRST_ERROR', false),

];
