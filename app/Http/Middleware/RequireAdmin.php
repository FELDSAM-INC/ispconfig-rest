<?php

namespace App\Http\Middleware;

use App\Support\IspContext;
use App\Support\Problem;
use Closure;
use Illuminate\Http\Request;

/**
 * Module gate 'scope.admin' (spec 011 FR-013…FR-015, FR-017): the wrapped
 * routes belong to ISPConfig modules a non-admin login never receives
 * (admin/monitor — sys_user.modules parity, config.inc.php:109) or to
 * admin-menu-only surfaces hardened by the spec. Denied before route-model
 * binding, so no query runs for unauthorized keys.
 */
class RequireAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! app(IspContext::class)->authScope()->isAdmin) {
            return Problem::response(403, 'Forbidden', 'This resource requires an administrator API key.');
        }

        return $next($request);
    }
}
