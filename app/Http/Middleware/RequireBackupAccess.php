<?php

namespace App\Http\Middleware;

use App\Models\WebDomain;
use App\Services\WebBackupService;
use App\Support\IspContext;
use App\Support\Problem;
use App\Support\ProblemType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Backup gate 'scope.backup' for the website backup sub-resources (spec 018
 * research R8 steps 2-3). Runs after route-model binding, so an unreadable or
 * missing website has already answered 404 through the read predicate:
 *
 *  - websites other than vhosts have no backups → 404 (legacy shows the
 *    Backup tab only for vhostdomain_type 'domain');
 *  - client and reseller keys need limit_backup = 'y' on their client → 403.
 */
class RequireBackupAccess
{
    public function __construct(protected WebBackupService $backups) {}

    public function handle(Request $request, Closure $next)
    {
        $website = $request->route('webDomain');

        if (! $website instanceof WebDomain) {
            return $next($request);
        }

        if (($website->getAttributes()['type'] ?? '') !== 'vhost') {
            throw new NotFoundHttpException('The requested resource does not exist.');
        }

        if (! $this->backups->backupAllowed(app(IspContext::class)->authScope())) {
            return Problem::response(403, 'Forbidden', 'Backups are not enabled for this account.', [
                'type' => ProblemType::uri(ProblemType::FEATURE_NOT_ALLOWED),
                'feature' => 'limit_backup',
            ]);
        }

        return $next($request);
    }
}
