<?php

use App\Http\Controllers\Api\V1\DirectiveSnippetController;
use App\Http\Controllers\Api\V1\DnsCaController;
use App\Http\Controllers\Api\V1\ResyncController;
use App\Http\Controllers\Api\V1\SystemConfigController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Ordering rule (constitution Principle IV): specific/static segments before
| parameterized ones — the literal section paths precede the composite
| system/config, and the literal system/resync/servers precedes the
| system/resync action path.
*/

// Global configuration panels — api/modules/system/{sites,mail,dns,domains,misc}-config.yaml
foreach (['sites', 'mail', 'dns', 'domains', 'misc'] as $section) {
    Route::get('system/config/'.$section, [SystemConfigController::class, 'showSection'])->defaults('section', $section);
    Route::put('system/config/'.$section, [SystemConfigController::class, 'updateSection'])->defaults('section', $section);
}

// Composite configuration — api/modules/system/system-config.yaml
Route::get('system/config', [SystemConfigController::class, 'show']);
Route::put('system/config', [SystemConfigController::class, 'update']);

// DNS Certification Authorities — api/modules/system/dns-cas.yaml
Route::get('system/dns-cas', [DnsCaController::class, 'index']);
Route::post('system/dns-cas', [DnsCaController::class, 'store']);
Route::get('system/dns-cas/{dnsCa}', [DnsCaController::class, 'show'])->whereNumber('dnsCa');
Route::put('system/dns-cas/{dnsCa}', [DnsCaController::class, 'update'])->whereNumber('dnsCa');
Route::delete('system/dns-cas/{dnsCa}', [DnsCaController::class, 'destroy'])->whereNumber('dnsCa');

// Directive Snippets — api/modules/system/directive-snippets.yaml
Route::get('system/directive-snippets', [DirectiveSnippetController::class, 'index']);
Route::post('system/directive-snippets', [DirectiveSnippetController::class, 'store']);
Route::get('system/directive-snippets/{directiveSnippet}', [DirectiveSnippetController::class, 'show'])->whereNumber('directiveSnippet');
Route::put('system/directive-snippets/{directiveSnippet}', [DirectiveSnippetController::class, 'update'])->whereNumber('directiveSnippet');
Route::delete('system/directive-snippets/{directiveSnippet}', [DirectiveSnippetController::class, 'destroy'])->whereNumber('directiveSnippet');

// Resync — api/modules/system/resync.yaml ('servers' before the action path)
Route::get('system/resync/servers', [ResyncController::class, 'servers']);
Route::post('system/resync', [ResyncController::class, 'store']);
