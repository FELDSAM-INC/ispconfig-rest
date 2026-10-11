<?php

use App\Http\Controllers\Api\V1\DatabaseOperationController;
use App\Http\Controllers\Api\V1\Usage\ResourceUsageController;
use App\Http\Controllers\Api\V1\WebLogController;
use App\Http\Controllers\Api\V1\WebWafController;
use App\Http\Controllers\Api\V1\WordPressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hosting routes (spec 058), in core's /api/v1 key-authenticated group
|--------------------------------------------------------------------------
| Every path has its own literal segments, so registration order relative to
| the core routes does not matter.
*/

Route::middleware(['api', 'api.key', 'change.set'])->prefix('api/v1')->group(function (): void {
    // Website logs and scheduled-task output logs (web-log worker).
    Route::get('sites/web-domains/{webDomain}/logs/{kind}', [WebLogController::class, 'show'])->whereNumber('webDomain')->whereIn('kind', ['access', 'error']);
    Route::get('sites/cron-jobs/{cronJob}/log', [WebLogController::class, 'cron'])->whereNumber('cronJob');

    // WordPress tools and the website WAF — authenticated, scoped subresources.
    Route::get('sites/web-domains/{webDomain}/wordpress', [WordPressController::class, 'show'])->whereNumber('webDomain');
    Route::post('sites/web-domains/{webDomain}/wordpress/jobs', [WordPressController::class, 'store'])->whereNumber('webDomain');
    Route::get('sites/web-domains/{webDomain}/wordpress/jobs/{job}', [WordPressController::class, 'job'])->whereNumber('webDomain')->whereUuid('job');
    Route::get('sites/web-domains/{webDomain}/waf/events', [WebWafController::class, 'index'])->whereNumber('webDomain');
    Route::get('sites/web-domains/{webDomain}/waf', [WebWafController::class, 'show'])->whereNumber('webDomain');
    Route::put('sites/web-domains/{webDomain}/waf', [WebWafController::class, 'update'])->whereNumber('webDomain');

    // Database operations — scoped through the database route binding.
    Route::post('sites/databases/{webDatabase}/operations', [DatabaseOperationController::class, 'store'])->whereNumber('webDatabase');
    Route::put('sites/databases/{webDatabase}/operations/{operation}/chunks/{sequence}', [DatabaseOperationController::class, 'chunk'])->whereNumber('webDatabase')->whereUuid('operation')->whereNumber('sequence');
    Route::post('sites/databases/{webDatabase}/operations/{operation}/upload-complete', [DatabaseOperationController::class, 'finish'])->whereNumber('webDatabase')->whereUuid('operation');
    Route::delete('sites/databases/{webDatabase}/operations/{operation}', [DatabaseOperationController::class, 'destroy'])->whereNumber('webDatabase')->whereUuid('operation');
    Route::get('sites/databases/{webDatabase}/operations/{operation}/download', [DatabaseOperationController::class, 'download'])->whereNumber('webDatabase')->whereUuid('operation');
    Route::get('sites/databases/{webDatabase}/operations/{operation}', [DatabaseOperationController::class, 'show'])->whereNumber('webDatabase')->whereUuid('operation');

    // PHP-FPM resource limits and cgroup usage (spec 053), every key, scoped rows.
    Route::get('usage/resources', [ResourceUsageController::class, 'show']);
});
