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

];
