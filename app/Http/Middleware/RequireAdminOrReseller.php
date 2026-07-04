<?php

namespace App\Http\Middleware;

use App\Support\IspContext;
use App\Support\Problem;
use Closure;
use Illuminate\Http\Request;

/**
 * Module gate 'scope.reseller' (spec 011 FR-016): the legacy client module
 * is only granted to admins and to users with limit_client != 0 (resellers —
 * client_edit.php:328-331, auth.inc.php:60-80). Row scoping then confines a
 * reseller to its own clients inside the gate.
 */
class RequireAdminOrReseller
{
    public function handle(Request $request, Closure $next)
    {
        $scope = app(IspContext::class)->authScope();

        if (! $scope->isAdmin && ! $scope->isReseller()) {
            return Problem::response(403, 'Forbidden', 'This resource requires an administrator or reseller API key.');
        }

        return $next($request);
    }
}
