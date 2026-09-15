<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API version reported by the root endpoint
    |--------------------------------------------------------------------------
    */

    'version' => env('API_VERSION', '1.0'),

    /*
    |--------------------------------------------------------------------------
    | Development API key
    |--------------------------------------------------------------------------
    | Accepted only in local/development/testing environments; authenticates
    | as the ISPConfig admin (sys_userid 1). Leave unset in production.
    */

    'dev_key' => env('API_DEV_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Usage statistics (spec 017)
    |--------------------------------------------------------------------------
    | Collector data older than these ages (seconds) is treated as unknown
    | (FR-011, owner decision 2026-09-14): disk and database sizes are
    | collected every 5 minutes, mailbox storage every 15 minutes.
    */

    'usage' => [
        'stale_after' => [
            'harddisk_quota' => 1800,
            'database_size' => 1800,
            'email_quota' => 3600,
        ],
    ],

];
