<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (prefixed api/v1 via bootstrap/app.php)
|--------------------------------------------------------------------------
| All resource routes live inside the api.key group. Ordering rule
| (constitution Principle IV): specific routes before general ones.
*/

Route::middleware('api.key')->group(function () {
    Route::get('/ping', fn () => response()->json([
        'data' => ['pong' => true],
    ]));

    /*
    | Module route files — one per module so parallel feature work never
    | edits a shared file. Each module adds exactly one require line here
    | and owns its routes/api/<module>.php.
    */
    require __DIR__.'/api/client.php';
    require __DIR__.'/api/dns.php';
    require __DIR__.'/api/mail.php';
    require __DIR__.'/api/monitor.php';
    require __DIR__.'/api/server.php';
    require __DIR__.'/api/sites.php';
    require __DIR__.'/api/system.php';
});
