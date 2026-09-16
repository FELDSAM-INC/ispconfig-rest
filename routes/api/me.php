<?php

use App\Http\Controllers\Api\V1\MeBackupsController;
use App\Http\Controllers\Api\V1\MeCapabilitiesController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MeHostingAddressesController;
use App\Http\Controllers\Api\V1\MeHostingLinksController;
use App\Http\Controllers\Api\V1\MeMailSettingsController;
use App\Http\Controllers\Api\V1\MePhpVersionsController;
use App\Http\Controllers\Api\V1\MeServersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Me module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Caller identity for every valid key — deliberately outside the scope.admin
| gate. Module owned by spec 014; spec 016 appends GET me/servers here, spec
| 021 GET me/capabilities and me/php-versions, spec 025 GET me/mail-settings,
| spec 031 GET me/hosting-addresses.
*/

// Caller identity — api/modules/me/me.yaml
Route::get('me', [MeController::class, 'show']);

// Servers the calling key may use — api/modules/me/servers.yaml (spec 016)
Route::get('me/servers', MeServersController::class);

// Website capabilities of the account — api/modules/me/capabilities.yaml (spec 021)
Route::get('me/capabilities', MeCapabilitiesController::class);

// PHP versions the account's websites may use — api/modules/me/php-versions.yaml (spec 021)
Route::get('me/php-versions', MePhpVersionsController::class);

// Email program settings of the account — api/modules/me/mail-settings.yaml (spec 025)
Route::get('me/mail-settings', MeMailSettingsController::class);

// Hosting addresses and name servers of the account — api/modules/me/hosting-addresses.yaml (spec 031)
Route::get('me/hosting-addresses', MeHostingAddressesController::class);

// Administration and file-transfer links of the account — api/modules/me/hosting-links.yaml (spec 036)
Route::get('me/hosting-links', MeHostingLinksController::class);

// Backup overview of the account's websites — api/modules/me/backups.yaml (spec 041)
Route::get('me/backups', MeBackupsController::class);
