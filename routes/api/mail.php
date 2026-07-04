<?php

use App\Http\Controllers\Api\V1\MailDomainController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mail module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Ordering rule (constitution Principle IV): register specific/static
| segments before parameterized ones — e.g. a future mail/domains/search
| would have to be declared ABOVE mail/domains/{mailDomain}.
*/

// Mail Domains — api/modules/mail/domains.yaml
Route::get('mail/domains', [MailDomainController::class, 'index']);
Route::post('mail/domains', [MailDomainController::class, 'store']);
Route::get('mail/domains/{mailDomain}', [MailDomainController::class, 'show'])->whereNumber('mailDomain');
Route::put('mail/domains/{mailDomain}', [MailDomainController::class, 'update'])->whereNumber('mailDomain');
Route::delete('mail/domains/{mailDomain}', [MailDomainController::class, 'destroy'])->whereNumber('mailDomain');
