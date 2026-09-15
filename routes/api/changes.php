<?php

use App\Http\Controllers\Api\V1\ChangeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Changes module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Processing status of journaled writes for every valid key — deliberately
| outside the scope.admin gate (spec 015). Specific before general.
*/

// Change list — api/modules/changes/changes.yaml
Route::get('changes', [ChangeController::class, 'index']);

// Change set status — api/modules/changes/changes.yaml
Route::get('changes/{changeSetId}', [ChangeController::class, 'show'])
    ->where('changeSetId', '[A-Za-z0-9,-]{1,64}');
