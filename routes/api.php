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

    // Module resource routes are added here per feature (Phase 3+).
});
