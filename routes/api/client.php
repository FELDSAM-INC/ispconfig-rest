<?php

use App\Http\Controllers\Api\V1\ClientCircleController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientDomainController;
use App\Http\Controllers\Api\V1\ClientResellerController;
use App\Http\Controllers\Api\V1\ClientTemplateAssignmentController;
use App\Http\Controllers\Api\V1\ClientTemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Ordering rule (constitution Principle IV): the literal segments
| clients/domains, clients/templates and clients/circles MUST be registered
| before clients/{client} so they are never captured as a client id; the
| nested clients/{client}/templates routes also precede clients/{client}.
|
| Module gates (spec 011): the legacy client module is granted only to
| admins and resellers (limit_client != 0 — client_edit.php:328-331), so
| everything under clients/ requires 'scope.reseller'; resellers/ is
| admin-only (client/lib/module.conf.php:28-45, typ == 'admin').
*/

Route::middleware('scope.reseller')->group(function () {
    // Client Domains (domain module) — api/modules/client/domains.yaml
    Route::get('clients/domains', [ClientDomainController::class, 'index']);
    Route::post('clients/domains', [ClientDomainController::class, 'store']);
    Route::get('clients/domains/{clientDomain}', [ClientDomainController::class, 'show'])->whereNumber('clientDomain');
    Route::put('clients/domains/{clientDomain}', [ClientDomainController::class, 'update'])->whereNumber('clientDomain');
    Route::delete('clients/domains/{clientDomain}', [ClientDomainController::class, 'destroy'])->whereNumber('clientDomain');

    // Client Templates — api/modules/client/templates.yaml
    Route::get('clients/templates', [ClientTemplateController::class, 'index']);
    Route::post('clients/templates', [ClientTemplateController::class, 'store']);
    Route::get('clients/templates/{template}', [ClientTemplateController::class, 'show'])->whereNumber('template');
    Route::put('clients/templates/{template}', [ClientTemplateController::class, 'update'])->whereNumber('template');
    Route::delete('clients/templates/{template}', [ClientTemplateController::class, 'destroy'])->whereNumber('template');

    // Client Circles — api/modules/client/circles.yaml
    Route::get('clients/circles', [ClientCircleController::class, 'index']);
    Route::post('clients/circles', [ClientCircleController::class, 'store']);
    Route::get('clients/circles/{circle}', [ClientCircleController::class, 'show'])->whereNumber('circle');
    Route::put('clients/circles/{circle}', [ClientCircleController::class, 'update'])->whereNumber('circle');
    Route::delete('clients/circles/{circle}', [ClientCircleController::class, 'destroy'])->whereNumber('circle');

    // Client Template Assignments — api/modules/client/template_assignments.yaml
    Route::get('clients/{client}/templates', [ClientTemplateAssignmentController::class, 'index'])->whereNumber('client');
    Route::post('clients/{client}/templates', [ClientTemplateAssignmentController::class, 'store'])->whereNumber('client');
    Route::get('clients/{client}/templates/{templateId}', [ClientTemplateAssignmentController::class, 'show'])->whereNumber('client')->whereNumber('templateId');
    Route::delete('clients/{client}/templates/{templateId}', [ClientTemplateAssignmentController::class, 'destroy'])->whereNumber('client')->whereNumber('templateId');

    // Clients — api/modules/client/clients.yaml (general routes LAST)
    Route::get('clients', [ClientController::class, 'index']);
    Route::post('clients', [ClientController::class, 'store']);
    Route::get('clients/{client}', [ClientController::class, 'show'])->whereNumber('client');
    Route::put('clients/{client}', [ClientController::class, 'update'])->whereNumber('client');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->whereNumber('client');
});

// Resellers — api/modules/client/resellers.yaml (own prefix, no shadowing;
// admin-only — a reseller cannot manage resellers, spec 011 FR-015)
Route::middleware('scope.admin')->group(function () {
    Route::get('resellers', [ClientResellerController::class, 'index']);
    Route::post('resellers', [ClientResellerController::class, 'store']);
    Route::get('resellers/{reseller}', [ClientResellerController::class, 'show'])->whereNumber('reseller');
    Route::put('resellers/{reseller}', [ClientResellerController::class, 'update'])->whereNumber('reseller');
    Route::delete('resellers/{reseller}', [ClientResellerController::class, 'destroy'])->whereNumber('reseller');
});
