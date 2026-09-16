<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Models\WebDomain;
use App\Services\AccountBackupService;
use App\Services\AccountCapabilitiesService;
use App\Services\WebBackupService;
use App\Support\IspContext;
use App\Support\Problem;
use App\Support\ProblemType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * GET /me/backups — the account's backup overview (contract:
 * api/modules/me/backups.yaml, spec 041). One entry per vhost website the key
 * may read, carrying the newest backup of each type, so a consumer renders a
 * backup overview in one request instead of one request per website.
 *
 * Read-only. The plan gate and the vhost-only rule are the ones spec 018
 * applies per website (RequireBackupAccess), so the overview never discloses
 * what the detail endpoints would refuse.
 */
class MeBackupsController extends Controller
{
    use HandlesListQuery;

    public function __construct(
        private readonly AccountCapabilitiesService $capabilities,
        private readonly AccountBackupService $overview,
        private readonly WebBackupService $backups,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $scope = app(IspContext::class)->authScope();

        // Same gate as every per-website backup endpoint, before any work.
        if (! $this->backups->backupAllowed($scope)) {
            return Problem::response(403, 'Forbidden', 'Backups are not enabled for this account.', [
                'type' => ProblemType::uri(ProblemType::FEATURE_NOT_ALLOWED),
                'feature' => 'limit_backup',
            ]);
        }

        $raw = $request->query('client_id');

        if ($raw !== null && (! is_string($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < 1)) {
            throw ValidationException::withMessages([
                'client_id' => 'The client id must be a positive integer.',
            ]);
        }

        $clientId = $this->capabilities->resolveTarget($scope, $raw === null ? null : (int) $raw);

        $query = WebDomain::query()->where('type', 'vhost');

        // Admin and reseller keys may read more than the requested account, so
        // the page is restricted to the resolved client's own rows.
        if (! $scope->isAdmin && $clientId === $scope->clientId) {
            // The read predicate of HandlesListQuery already limits the page.
        } else {
            $this->overview->restrictToClient($query, $clientId);
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['domain'],
            defaultSort: 'domain',
            extra: ['client_id'],
        );

        $result['data'] = $this->overview->overview($result['data']);

        return response()->json($result);
    }
}
