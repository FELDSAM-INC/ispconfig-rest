<?php

use App\Http\Controllers\Api\V1\Monitor\DataLogController;
use App\Http\Controllers\Api\V1\Monitor\ServerStatusController;
use App\Http\Controllers\Api\V1\Monitor\SystemLogController;
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

// Server Status — api/modules/monitor/server-status.yaml (computed
// projection over monitor_data, read-only — spec 009). The literal
// `servers/status` MUST stay before `servers/{id}/status` so the `{id}`
// segment can never capture the string "status".
Route::get('monitor/servers/status', [ServerStatusController::class, 'index']);
Route::get('monitor/servers/{id}/status', [ServerStatusController::class, 'show'])->whereNumber('id');

// System Logs — api/modules/monitor/system-logs.yaml (read-only sys_log
// processing log: list only, no writes — spec 009)
Route::get('monitor/system-logs', [SystemLogController::class, 'index']);
