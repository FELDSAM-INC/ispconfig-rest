<?php

use App\Http\Controllers\Api\V1\ServerConfigController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\ServerFirewallController;
use App\Http\Controllers\Api\V1\ServerIpController;
use App\Http\Controllers\Api\V1\ServerIpMapController;
use App\Http\Controllers\Api\V1\ServerPhpController;
use App\Services\ServerConfigService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Server module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Ordering rule (constitution Principle IV): all nested servers/{server}/...
| routes are registered ABOVE the bare servers/{server} block. {server}
| resolves App\Models\Server via implicit binding (404 problem+json when the
| parent does not exist); child ids stay plain numeric parameters resolved
| inside the controllers, scoped by server_id.
*/

// Server Configuration (server.config INI blob) — api/modules/server/server-config.yaml
// GET whole config + GET/PUT per section; {section} is whitelisted to the
// contract's eleven sections — anything else 404s.
Route::get('servers/{server}/configs', [ServerConfigController::class, 'show'])
    ->whereNumber('server');
Route::get('servers/{server}/configs/{section}', [ServerConfigController::class, 'showSection'])
    ->whereNumber('server')->whereIn('section', ServerConfigService::SECTIONS);
Route::put('servers/{server}/configs/{section}', [ServerConfigController::class, 'updateSection'])
    ->whereNumber('server')->whereIn('section', ServerConfigService::SECTIONS);

// Server IP Addresses — api/modules/server/ip-addresses.yaml
Route::get('servers/{server}/ip-addresses', [ServerIpController::class, 'index'])
    ->whereNumber('server');
Route::post('servers/{server}/ip-addresses', [ServerIpController::class, 'store'])
    ->whereNumber('server');
Route::get('servers/{server}/ip-addresses/{ipAddress}', [ServerIpController::class, 'show'])
    ->whereNumber('server')->whereNumber('ipAddress');
Route::put('servers/{server}/ip-addresses/{ipAddress}', [ServerIpController::class, 'update'])
    ->whereNumber('server')->whereNumber('ipAddress');
Route::delete('servers/{server}/ip-addresses/{ipAddress}', [ServerIpController::class, 'destroy'])
    ->whereNumber('server')->whereNumber('ipAddress');

// Server IP Mappings — api/modules/server/ip-mappings.yaml
Route::get('servers/{server}/ip-mappings', [ServerIpMapController::class, 'index'])
    ->whereNumber('server');
Route::post('servers/{server}/ip-mappings', [ServerIpMapController::class, 'store'])
    ->whereNumber('server');
Route::get('servers/{server}/ip-mappings/{mapping}', [ServerIpMapController::class, 'show'])
    ->whereNumber('server')->whereNumber('mapping');
Route::put('servers/{server}/ip-mappings/{mapping}', [ServerIpMapController::class, 'update'])
    ->whereNumber('server')->whereNumber('mapping');
Route::delete('servers/{server}/ip-mappings/{mapping}', [ServerIpMapController::class, 'destroy'])
    ->whereNumber('server')->whereNumber('mapping');

// Server Firewall — api/modules/server/firewall.yaml
// SINGLETON (UNIQUE firewall.server_id): GET / PUT-upsert (201 create,
// 200 update) / DELETE — no child id segment.
Route::get('servers/{server}/firewall', [ServerFirewallController::class, 'show'])
    ->whereNumber('server');
Route::put('servers/{server}/firewall', [ServerFirewallController::class, 'put'])
    ->whereNumber('server');
Route::delete('servers/{server}/firewall', [ServerFirewallController::class, 'destroy'])
    ->whereNumber('server');

// Server PHP Versions — api/modules/server/php-versions.yaml
Route::get('servers/{server}/php-versions', [ServerPhpController::class, 'index'])
    ->whereNumber('server');
Route::post('servers/{server}/php-versions', [ServerPhpController::class, 'store'])
    ->whereNumber('server');
Route::get('servers/{server}/php-versions/{phpVersion}', [ServerPhpController::class, 'show'])
    ->whereNumber('server')->whereNumber('phpVersion');
Route::put('servers/{server}/php-versions/{phpVersion}', [ServerPhpController::class, 'update'])
    ->whereNumber('server')->whereNumber('phpVersion');
Route::delete('servers/{server}/php-versions/{phpVersion}', [ServerPhpController::class, 'destroy'])
    ->whereNumber('server')->whereNumber('phpVersion');

// Servers — api/modules/server/servers.yaml (bare {server} routes LAST)
Route::get('servers', [ServerController::class, 'index']);
Route::post('servers', [ServerController::class, 'store']);
Route::get('servers/{server}', [ServerController::class, 'show'])->whereNumber('server');
Route::put('servers/{server}', [ServerController::class, 'update'])->whereNumber('server');
Route::delete('servers/{server}', [ServerController::class, 'destroy'])->whereNumber('server');
