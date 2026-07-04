<?php

namespace App\Http\Middleware;

use App\Services\ClientLimitService;
use App\Support\IspContext;
use App\Support\Problem;
use Closure;
use Illuminate\Http\Request;

/**
 * Limit-access gate 'scope.limit:{limit_column}' (spec 011 FR-017): writes
 * to resource types whose legacy limit defaults to 0 (mail transports —
 * limit_mailrouting, mail access rules — limit_mail_wblist, spamfilter
 * wblist — limit_spamfilter_wblist) are denied unless the acting client's
 * limit column is non-zero. Admin keys and keys without a client row pass
 * (legacy get_client_limit returns -1 when no client row exists,
 * auth.inc.php:139-141).
 */
class RequireClientLimit
{
    public function __construct(protected ClientLimitService $limits) {}

    public function handle(Request $request, Closure $next, string $limitColumn)
    {
        $scope = app(IspContext::class)->authScope();

        if (! $this->limits->resourceEnabled($scope, $limitColumn)) {
            return Problem::response(403, 'Forbidden', 'This feature is not enabled for your account.');
        }

        return $next($request);
    }
}
