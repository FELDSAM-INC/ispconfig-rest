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

        /*
        |----------------------------------------------------------------------
        | Collector cadence (spec 043)
        |----------------------------------------------------------------------
        | How often ISPConfig's collectors run, in seconds: every 5 minutes for
        | disk quota and database sizes, every 15 for mailbox quota. The values
        | are the cron schedules compiled into ISPConfig
        | (server/lib/classes/cron.d/100-monitor_hd_quota.inc.php:34,
        | 100-monitor_database_size.inc.php:36, 100-monitor_email_quota.inc.php:34)
        | and are reported as `freshness.<metric>.interval_seconds`. A remote
        | server's crontab cannot be read, and monitor_data keeps only the newest
        | row per server and type, so the cadence cannot be observed - an
        | installation that changed it corrects these values here.
        */

        'interval' => [
            'harddisk_quota' => 300,
            'database_size' => 300,
            'email_quota' => 900,
        ],
    ],

];
