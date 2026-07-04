<?php

use App\Http\Controllers\Api\V1\MailAccessController;
use App\Http\Controllers\Api\V1\MailAliasDomainController;
use App\Http\Controllers\Api\V1\MailContentFilterController;
use App\Http\Controllers\Api\V1\MailDomainController;
use App\Http\Controllers\Api\V1\MailForwardingController;
use App\Http\Controllers\Api\V1\MailGetController;
use App\Http\Controllers\Api\V1\MailRelayDomainController;
use App\Http\Controllers\Api\V1\MailRelayRecipientController;
use App\Http\Controllers\Api\V1\MailTransportController;
use App\Http\Controllers\Api\V1\MailUserAutoresponderController;
use App\Http\Controllers\Api\V1\MailUserCCController;
use App\Http\Controllers\Api\V1\MailUserController;
use App\Http\Controllers\Api\V1\MailUserFilterController;
use App\Http\Controllers\Api\V1\MailUserPasswordController;
use App\Http\Controllers\Api\V1\MailUserSpamFilterController;
use App\Http\Controllers\Api\V1\SpamfilterConfigController;
use App\Http\Controllers\Api\V1\SpamfilterPolicyController;
use App\Http\Controllers\Api\V1\SpamfilterUserController;
use App\Http\Controllers\Api\V1\SpamfilterWBListController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mail module routes (required by routes/api.php inside the api.key group)
|--------------------------------------------------------------------------
| Ordering rule (constitution Principle IV): register specific/static
| segments before parameterized ones — e.g. a future mail/domains/search
| would have to be declared ABOVE mail/domains/{mailDomain}, and the nested
| mail/users/{id}/... routes stay ABOVE the general mail/users/{id} routes.
*/

// Mail Domains — api/modules/mail/domains.yaml
Route::get('mail/domains', [MailDomainController::class, 'index']);
Route::post('mail/domains', [MailDomainController::class, 'store']);
Route::get('mail/domains/{mailDomain}', [MailDomainController::class, 'show'])->whereNumber('mailDomain');
Route::put('mail/domains/{mailDomain}', [MailDomainController::class, 'update'])->whereNumber('mailDomain');
Route::delete('mail/domains/{mailDomain}', [MailDomainController::class, 'destroy'])->whereNumber('mailDomain');

// Mail user NESTED sub-resources — MUST precede mail/users/{mailUser}
// api/modules/mail/user-autoresponder.yaml
Route::get('mail/users/{mailUser}/autoresponder', [MailUserAutoresponderController::class, 'show'])->whereNumber('mailUser');
Route::put('mail/users/{mailUser}/autoresponder', [MailUserAutoresponderController::class, 'update'])->whereNumber('mailUser');
Route::delete('mail/users/{mailUser}/autoresponder', [MailUserAutoresponderController::class, 'destroy'])->whereNumber('mailUser');

// api/modules/mail/user-cc.yaml
Route::get('mail/users/{mailUser}/cc', [MailUserCCController::class, 'show'])->whereNumber('mailUser');
Route::put('mail/users/{mailUser}/cc', [MailUserCCController::class, 'update'])->whereNumber('mailUser');

// api/modules/mail/user-password.yaml
Route::put('mail/users/{mailUser}/password', [MailUserPasswordController::class, 'update'])->whereNumber('mailUser');

// api/modules/mail/user-spamfilter.yaml
Route::get('mail/users/{mailUser}/spamfilter', [MailUserSpamFilterController::class, 'show'])->whereNumber('mailUser');
Route::put('mail/users/{mailUser}/spamfilter', [MailUserSpamFilterController::class, 'update'])->whereNumber('mailUser');

// api/modules/mail/user-filters.yaml
Route::get('mail/users/{mailUser}/filters', [MailUserFilterController::class, 'index'])->whereNumber('mailUser');
Route::post('mail/users/{mailUser}/filters', [MailUserFilterController::class, 'store'])->whereNumber('mailUser');
Route::get('mail/users/{mailUser}/filters/{filterId}', [MailUserFilterController::class, 'show'])->whereNumber('mailUser')->whereNumber('filterId');
Route::put('mail/users/{mailUser}/filters/{filterId}', [MailUserFilterController::class, 'update'])->whereNumber('mailUser')->whereNumber('filterId');
Route::delete('mail/users/{mailUser}/filters/{filterId}', [MailUserFilterController::class, 'destroy'])->whereNumber('mailUser')->whereNumber('filterId');

// Mail Users — api/modules/mail/users.yaml
Route::get('mail/users', [MailUserController::class, 'index']);
Route::post('mail/users', [MailUserController::class, 'store']);
Route::get('mail/users/{mailUser}', [MailUserController::class, 'show'])->whereNumber('mailUser');
Route::put('mail/users/{mailUser}', [MailUserController::class, 'update'])->whereNumber('mailUser');
Route::delete('mail/users/{mailUser}', [MailUserController::class, 'destroy'])->whereNumber('mailUser');

// Mail Forwards — api/modules/mail/forwards.yaml
Route::get('mail/forwards', [MailForwardingController::class, 'index']);
Route::post('mail/forwards', [MailForwardingController::class, 'store']);
Route::get('mail/forwards/{mailForwarding}', [MailForwardingController::class, 'show'])->whereNumber('mailForwarding');
Route::put('mail/forwards/{mailForwarding}', [MailForwardingController::class, 'update'])->whereNumber('mailForwarding');
Route::delete('mail/forwards/{mailForwarding}', [MailForwardingController::class, 'destroy'])->whereNumber('mailForwarding');

// Mail Alias Domains — api/modules/mail/alias-domains.yaml
Route::get('mail/alias-domains', [MailAliasDomainController::class, 'index']);
Route::post('mail/alias-domains', [MailAliasDomainController::class, 'store']);
Route::get('mail/alias-domains/{mailAliasDomain}', [MailAliasDomainController::class, 'show'])->whereNumber('mailAliasDomain');
Route::put('mail/alias-domains/{mailAliasDomain}', [MailAliasDomainController::class, 'update'])->whereNumber('mailAliasDomain');
Route::delete('mail/alias-domains/{mailAliasDomain}', [MailAliasDomainController::class, 'destroy'])->whereNumber('mailAliasDomain');

// Spamfilter Config — api/modules/mail/spamfilter-config.yaml
// (no POST/DELETE by contract; static 'config' segment precedes the
// parameterized sibling resources below). Admin-only INCLUDING reads: the
// values live in the server.config blob, which carries no row permissions
// (spec 011 FR-017 + plan — SpamfilterConfig is one of the admin-gated
// non-listQuery controllers; the contract declares 403 on all three ops).
Route::middleware('scope.admin')->group(function () {
    Route::get('mail/spamfilter/config', [SpamfilterConfigController::class, 'index']);
    Route::get('mail/spamfilter/config/{serverId}', [SpamfilterConfigController::class, 'show'])->whereNumber('serverId');
    Route::put('mail/spamfilter/config/{serverId}', [SpamfilterConfigController::class, 'update'])->whereNumber('serverId');
});

// Spamfilter Policies — api/modules/mail/spamfilter-policies.yaml
// (reads row-scoped — policies are world-readable by seed convention;
// writes admin-only, spec 011 FR-017 hardening)
Route::get('mail/spamfilter/policies', [SpamfilterPolicyController::class, 'index']);
Route::post('mail/spamfilter/policies', [SpamfilterPolicyController::class, 'store'])->middleware('scope.admin');
Route::get('mail/spamfilter/policies/{spamfilterPolicy}', [SpamfilterPolicyController::class, 'show'])->whereNumber('spamfilterPolicy');
Route::put('mail/spamfilter/policies/{spamfilterPolicy}', [SpamfilterPolicyController::class, 'update'])->whereNumber('spamfilterPolicy')->middleware('scope.admin');
Route::delete('mail/spamfilter/policies/{spamfilterPolicy}', [SpamfilterPolicyController::class, 'destroy'])->whereNumber('spamfilterPolicy')->middleware('scope.admin');

// Spamfilter Users — api/modules/mail/spamfilter-users.yaml
// (reads row-scoped; writes admin-only, spec 011 FR-017 hardening)
Route::get('mail/spamfilter/users', [SpamfilterUserController::class, 'index']);
Route::post('mail/spamfilter/users', [SpamfilterUserController::class, 'store'])->middleware('scope.admin');
Route::get('mail/spamfilter/users/{spamfilterUser}', [SpamfilterUserController::class, 'show'])->whereNumber('spamfilterUser');
Route::put('mail/spamfilter/users/{spamfilterUser}', [SpamfilterUserController::class, 'update'])->whereNumber('spamfilterUser')->middleware('scope.admin');
Route::delete('mail/spamfilter/users/{spamfilterUser}', [SpamfilterUserController::class, 'destroy'])->whereNumber('spamfilterUser')->middleware('scope.admin');

// Spamfilter WB List — api/modules/mail/spamfilter-wblist.yaml
// (reads row-scoped; writes require the client's limit_spamfilter_wblist
// to be booked — legacy default 0, spec 011 FR-017)
Route::get('mail/spamfilter/wblist', [SpamfilterWBListController::class, 'index']);
Route::post('mail/spamfilter/wblist', [SpamfilterWBListController::class, 'store'])->middleware('scope.limit:limit_spamfilter_wblist');
Route::get('mail/spamfilter/wblist/{spamfilterWblist}', [SpamfilterWBListController::class, 'show'])->whereNumber('spamfilterWblist');
Route::put('mail/spamfilter/wblist/{spamfilterWblist}', [SpamfilterWBListController::class, 'update'])->whereNumber('spamfilterWblist')->middleware('scope.limit:limit_spamfilter_wblist');
Route::delete('mail/spamfilter/wblist/{spamfilterWblist}', [SpamfilterWBListController::class, 'destroy'])->whereNumber('spamfilterWblist')->middleware('scope.limit:limit_spamfilter_wblist');

// Mail Transports — api/modules/mail/transports.yaml
// (reads row-scoped; writes require limit_mailrouting — legacy default 0,
// mail_transport_edit.php:60, spec 011 FR-017)
Route::get('mail/transports', [MailTransportController::class, 'index']);
Route::post('mail/transports', [MailTransportController::class, 'store'])->middleware('scope.limit:limit_mailrouting');
Route::get('mail/transports/{mailTransport}', [MailTransportController::class, 'show'])->whereNumber('mailTransport');
Route::put('mail/transports/{mailTransport}', [MailTransportController::class, 'update'])->whereNumber('mailTransport')->middleware('scope.limit:limit_mailrouting');
Route::delete('mail/transports/{mailTransport}', [MailTransportController::class, 'destroy'])->whereNumber('mailTransport')->middleware('scope.limit:limit_mailrouting');

// Mail Relay Domains — api/modules/mail/relay-domains.yaml
// (reads row-scoped; writes admin-only, spec 011 FR-017 hardening)
Route::get('mail/relay-domains', [MailRelayDomainController::class, 'index']);
Route::post('mail/relay-domains', [MailRelayDomainController::class, 'store'])->middleware('scope.admin');
Route::get('mail/relay-domains/{mailRelayDomain}', [MailRelayDomainController::class, 'show'])->whereNumber('mailRelayDomain');
Route::put('mail/relay-domains/{mailRelayDomain}', [MailRelayDomainController::class, 'update'])->whereNumber('mailRelayDomain')->middleware('scope.admin');
Route::delete('mail/relay-domains/{mailRelayDomain}', [MailRelayDomainController::class, 'destroy'])->whereNumber('mailRelayDomain')->middleware('scope.admin');

// Mail Relay Recipients — api/modules/mail/relay-recipients.yaml
// (reads row-scoped; writes admin-only, spec 011 FR-017 hardening)
Route::get('mail/relay-recipients', [MailRelayRecipientController::class, 'index']);
Route::post('mail/relay-recipients', [MailRelayRecipientController::class, 'store'])->middleware('scope.admin');
Route::get('mail/relay-recipients/{mailRelayRecipient}', [MailRelayRecipientController::class, 'show'])->whereNumber('mailRelayRecipient');
Route::put('mail/relay-recipients/{mailRelayRecipient}', [MailRelayRecipientController::class, 'update'])->whereNumber('mailRelayRecipient')->middleware('scope.admin');
Route::delete('mail/relay-recipients/{mailRelayRecipient}', [MailRelayRecipientController::class, 'destroy'])->whereNumber('mailRelayRecipient')->middleware('scope.admin');

// Mail Access Rules — api/modules/mail/access-rules.yaml
// (reads row-scoped; writes require limit_mail_wblist — legacy default 0,
// spec 011 FR-017)
Route::get('mail/access-rules', [MailAccessController::class, 'index']);
Route::post('mail/access-rules', [MailAccessController::class, 'store'])->middleware('scope.limit:limit_mail_wblist');
Route::get('mail/access-rules/{mailAccess}', [MailAccessController::class, 'show'])->whereNumber('mailAccess');
Route::put('mail/access-rules/{mailAccess}', [MailAccessController::class, 'update'])->whereNumber('mailAccess')->middleware('scope.limit:limit_mail_wblist');
Route::delete('mail/access-rules/{mailAccess}', [MailAccessController::class, 'destroy'])->whereNumber('mailAccess')->middleware('scope.limit:limit_mail_wblist');

// Mail Content Filters — api/modules/mail/content-filters.yaml
// (reads row-scoped; writes admin-only, spec 011 FR-017 hardening)
Route::get('mail/content-filters', [MailContentFilterController::class, 'index']);
Route::post('mail/content-filters', [MailContentFilterController::class, 'store'])->middleware('scope.admin');
Route::get('mail/content-filters/{mailContentFilter}', [MailContentFilterController::class, 'show'])->whereNumber('mailContentFilter');
Route::put('mail/content-filters/{mailContentFilter}', [MailContentFilterController::class, 'update'])->whereNumber('mailContentFilter')->middleware('scope.admin');
Route::delete('mail/content-filters/{mailContentFilter}', [MailContentFilterController::class, 'destroy'])->whereNumber('mailContentFilter')->middleware('scope.admin');

// Mail Fetchmail (table mail_get) — api/modules/mail/fetchmail.yaml
Route::get('mail/fetchmail', [MailGetController::class, 'index']);
Route::post('mail/fetchmail', [MailGetController::class, 'store']);
Route::get('mail/fetchmail/{mailGet}', [MailGetController::class, 'show'])->whereNumber('mailGet');
Route::put('mail/fetchmail/{mailGet}', [MailGetController::class, 'update'])->whereNumber('mailGet');
Route::delete('mail/fetchmail/{mailGet}', [MailGetController::class, 'destroy'])->whereNumber('mailGet');
