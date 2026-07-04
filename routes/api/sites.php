<?php

use App\Http\Controllers\Api\V1\CronJobController;
use App\Http\Controllers\Api\V1\FtpUserController;
use App\Http\Controllers\Api\V1\ShellUserController;
use App\Http\Controllers\Api\V1\WebChildDomainController;
use App\Http\Controllers\Api\V1\WebDatabaseController;
use App\Http\Controllers\Api\V1\WebDatabaseUserController;
use App\Http\Controllers\Api\V1\WebdavUserController;
use App\Http\Controllers\Api\V1\WebDomainController;
use App\Http\Controllers\Api\V1\WebDomainSslController;
use App\Http\Controllers\Api\V1\WebFolderController;
use App\Http\Controllers\Api\V1\WebFolderUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sites module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Ordering rule (constitution Principle IV): specific routes before general
| ones — the SSL subresource routes MUST be registered above the bare
| web-domains/{id} routes:
|   sites/web-domains/{id}/ssl/renew  ->  …/{id}/ssl  ->  …/{id}
| All other sites resources use distinct literal prefixes and cannot shadow
| each other.
*/

// Web Domain SSL subresource — api/modules/sites/web-domains.yaml (most specific first)
Route::post('sites/web-domains/{webDomain}/ssl/renew', [WebDomainSslController::class, 'renew'])->whereNumber('webDomain');
Route::get('sites/web-domains/{webDomain}/ssl', [WebDomainSslController::class, 'show'])->whereNumber('webDomain');
Route::post('sites/web-domains/{webDomain}/ssl', [WebDomainSslController::class, 'store'])->whereNumber('webDomain');
Route::delete('sites/web-domains/{webDomain}/ssl', [WebDomainSslController::class, 'destroy'])->whereNumber('webDomain');

// Web Domains — api/modules/sites/web-domains.yaml
Route::get('sites/web-domains', [WebDomainController::class, 'index']);
Route::post('sites/web-domains', [WebDomainController::class, 'store']);
Route::get('sites/web-domains/{webDomain}', [WebDomainController::class, 'show'])->whereNumber('webDomain');
Route::put('sites/web-domains/{webDomain}', [WebDomainController::class, 'update'])->whereNumber('webDomain');
Route::delete('sites/web-domains/{webDomain}', [WebDomainController::class, 'destroy'])->whereNumber('webDomain');

// Web Child Domains — api/modules/sites/web-child-domains.yaml
Route::get('sites/web-child-domains', [WebChildDomainController::class, 'index']);
Route::post('sites/web-child-domains', [WebChildDomainController::class, 'store']);
Route::get('sites/web-child-domains/{webChildDomain}', [WebChildDomainController::class, 'show'])->whereNumber('webChildDomain');
Route::put('sites/web-child-domains/{webChildDomain}', [WebChildDomainController::class, 'update'])->whereNumber('webChildDomain');
Route::delete('sites/web-child-domains/{webChildDomain}', [WebChildDomainController::class, 'destroy'])->whereNumber('webChildDomain');

// FTP Users — api/modules/sites/ftp-users.yaml
Route::get('sites/ftp-users', [FtpUserController::class, 'index']);
Route::post('sites/ftp-users', [FtpUserController::class, 'store']);
Route::get('sites/ftp-users/{ftpUser}', [FtpUserController::class, 'show'])->whereNumber('ftpUser');
Route::put('sites/ftp-users/{ftpUser}', [FtpUserController::class, 'update'])->whereNumber('ftpUser');
Route::delete('sites/ftp-users/{ftpUser}', [FtpUserController::class, 'destroy'])->whereNumber('ftpUser');

// Shell Users — api/modules/sites/shell-users.yaml
Route::get('sites/shell-users', [ShellUserController::class, 'index']);
Route::post('sites/shell-users', [ShellUserController::class, 'store']);
Route::get('sites/shell-users/{shellUser}', [ShellUserController::class, 'show'])->whereNumber('shellUser');
Route::put('sites/shell-users/{shellUser}', [ShellUserController::class, 'update'])->whereNumber('shellUser');
Route::delete('sites/shell-users/{shellUser}', [ShellUserController::class, 'destroy'])->whereNumber('shellUser');

// Databases — api/modules/sites/databases.yaml
Route::get('sites/databases', [WebDatabaseController::class, 'index']);
Route::post('sites/databases', [WebDatabaseController::class, 'store']);
Route::get('sites/databases/{webDatabase}', [WebDatabaseController::class, 'show'])->whereNumber('webDatabase');
Route::put('sites/databases/{webDatabase}', [WebDatabaseController::class, 'update'])->whereNumber('webDatabase');
Route::delete('sites/databases/{webDatabase}', [WebDatabaseController::class, 'destroy'])->whereNumber('webDatabase');

// Database Users — api/modules/sites/database-users.yaml
Route::get('sites/database-users', [WebDatabaseUserController::class, 'index']);
Route::post('sites/database-users', [WebDatabaseUserController::class, 'store']);
Route::get('sites/database-users/{webDatabaseUser}', [WebDatabaseUserController::class, 'show'])->whereNumber('webDatabaseUser');
Route::put('sites/database-users/{webDatabaseUser}', [WebDatabaseUserController::class, 'update'])->whereNumber('webDatabaseUser');
Route::delete('sites/database-users/{webDatabaseUser}', [WebDatabaseUserController::class, 'destroy'])->whereNumber('webDatabaseUser');

// Cron Jobs — api/modules/sites/cron-jobs.yaml
Route::get('sites/cron-jobs', [CronJobController::class, 'index']);
Route::post('sites/cron-jobs', [CronJobController::class, 'store']);
Route::get('sites/cron-jobs/{cronJob}', [CronJobController::class, 'show'])->whereNumber('cronJob');
Route::put('sites/cron-jobs/{cronJob}', [CronJobController::class, 'update'])->whereNumber('cronJob');
Route::delete('sites/cron-jobs/{cronJob}', [CronJobController::class, 'destroy'])->whereNumber('cronJob');

// Web Folders — api/modules/sites/web-folders.yaml
Route::get('sites/web-folders', [WebFolderController::class, 'index']);
Route::post('sites/web-folders', [WebFolderController::class, 'store']);
Route::get('sites/web-folders/{webFolder}', [WebFolderController::class, 'show'])->whereNumber('webFolder');
Route::put('sites/web-folders/{webFolder}', [WebFolderController::class, 'update'])->whereNumber('webFolder');
Route::delete('sites/web-folders/{webFolder}', [WebFolderController::class, 'destroy'])->whereNumber('webFolder');

// Web Folder Users — api/modules/sites/web-folder-users.yaml
Route::get('sites/web-folder-users', [WebFolderUserController::class, 'index']);
Route::post('sites/web-folder-users', [WebFolderUserController::class, 'store']);
Route::get('sites/web-folder-users/{webFolderUser}', [WebFolderUserController::class, 'show'])->whereNumber('webFolderUser');
Route::put('sites/web-folder-users/{webFolderUser}', [WebFolderUserController::class, 'update'])->whereNumber('webFolderUser');
Route::delete('sites/web-folder-users/{webFolderUser}', [WebFolderUserController::class, 'destroy'])->whereNumber('webFolderUser');

// WebDAV Users — api/modules/sites/webdav-users.yaml
Route::get('sites/webdav-users', [WebdavUserController::class, 'index']);
Route::post('sites/webdav-users', [WebdavUserController::class, 'store']);
Route::get('sites/webdav-users/{webdavUser}', [WebdavUserController::class, 'show'])->whereNumber('webdavUser');
Route::put('sites/webdav-users/{webdavUser}', [WebdavUserController::class, 'update'])->whereNumber('webdavUser');
Route::delete('sites/webdav-users/{webdavUser}', [WebdavUserController::class, 'destroy'])->whereNumber('webdavUser');
