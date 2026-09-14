<?php

use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Me module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Caller identity for every valid key — deliberately outside the scope.admin
| gate. Module owned by spec 014; spec 016 appends GET me/servers here.
*/

// Caller identity — api/modules/me/me.yaml
Route::get('me', [MeController::class, 'show']);
