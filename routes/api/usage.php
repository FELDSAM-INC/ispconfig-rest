<?php

use App\Http\Controllers\Api\V1\Usage\DatabaseUsageController;
use App\Http\Controllers\Api\V1\Usage\MailUserUsageController;
use App\Http\Controllers\Api\V1\Usage\UsageSummaryController;
use App\Http\Controllers\Api\V1\Usage\WebDomainUsageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Usage module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Read-only usage statistics (spec 017), available to every valid API key
| and scoped by the spec 011 read predicate — deliberately NOT inside a
| scope.admin group. Ordering rule (constitution Principle IV): the literal
| `usage/summary` and every `…/{id}/traffic` route are registered before the
| matching `…/{id}` route; ids are numeric.
*/

// Usage summary — api/modules/usage/summary.yaml (literal, before any {id} route)
Route::get('usage/summary', [UsageSummaryController::class, 'show']);

// Website usage — api/modules/usage/web-domains.yaml
Route::get('usage/web-domains', [WebDomainUsageController::class, 'index']);
Route::get('usage/web-domains/{id}', [WebDomainUsageController::class, 'show'])->whereNumber('id');

// Mailbox usage — api/modules/usage/mail-users.yaml
Route::get('usage/mail-users', [MailUserUsageController::class, 'index']);
Route::get('usage/mail-users/{id}', [MailUserUsageController::class, 'show'])->whereNumber('id');

// Database usage — api/modules/usage/databases.yaml
Route::get('usage/databases', [DatabaseUsageController::class, 'index']);
Route::get('usage/databases/{id}', [DatabaseUsageController::class, 'show'])->whereNumber('id');
