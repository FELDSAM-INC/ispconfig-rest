<?php

use App\Http\Controllers\Api\V1\Monitor\DataLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Monitor module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Ordering rule (constitution Principle IV): register specific/static
| segments before parameterized ones.
*/

// Data Logs — api/modules/monitor/data-logs.yaml (read-only sys_datalog
// journal: list + show only, no writes — spec 004 FR-008)
Route::get('monitor/data-logs', [DataLogController::class, 'index']);
Route::get('monitor/data-logs/{dataLog}', [DataLogController::class, 'show'])->whereNumber('dataLog');
