<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Concerns\ReadsAccountQuery;
use App\Http\Controllers\Controller;
use App\Services\AccountCapabilitiesService;
use App\Services\PhpVersionService;
use App\Support\IspContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * GET /me/php-versions — PHP versions the account's websites may use per web
 * server and mode (contract: api/modules/me/php-versions.yaml, spec 021 US2).
 * Available to every valid key; read-only.
 */
class MePhpVersionsController extends Controller
{
    use HandlesListQuery;
    use ReadsAccountQuery;

    public function __construct(private readonly AccountCapabilitiesService $capabilities) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->rejectUnknownParameters($request, ['client_id', 'server_id', 'mode', 'limit', 'offset']);

        $limit = $this->positiveIntParam($request, 'limit', 25, min: 1, max: 100);
        $offset = $this->positiveIntParam($request, 'offset', 0, min: 0);
        $clientId = $this->positiveIdParameter($request, 'client_id', 'client id');
        $serverId = $this->positiveIdParameter($request, 'server_id', 'server id');
        $mode = $request->query('mode');

        if ($mode !== null && ! in_array($mode, PhpVersionService::VERSION_MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'The mode must be php-fpm or fast-cgi.']);
        }

        $target = $this->capabilities->resolveTarget(app(IspContext::class)->authScope(), $clientId);
        $versions = $this->capabilities->phpVersions($target, $serverId, $mode);

        return response()->json([
            'data' => array_slice($versions, $offset, $limit),
            'meta' => [
                'total' => count($versions),
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }
}
