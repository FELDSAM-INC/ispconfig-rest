<?php

namespace App\Http\Controllers\Api\V1\Usage;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Api\V1\Usage\Concerns\UsageListQuery;
use App\Http\Controllers\Controller;
use App\Models\WebDatabase;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Database usage (contract: api/modules/usage/databases.yaml, spec 017 US2).
 */
class DatabaseUsageController extends Controller
{
    use HandlesListQuery;
    use UsageListQuery;

    public function __construct(private readonly UsageService $usage) {}

    /**
     * GET /usage/databases
     */
    public function index(Request $request): JsonResponse
    {
        $this->rejectClientIdForClientKeys($request);

        $result = $this->listQuery(
            WebDatabase::query(),
            $request,
            sortable: ['database_name'],
            defaultSort: 'database_name',
            filters: [
                'database_name' => 'wildcard',
                'client_id' => 'owning_client',
            ],
        );

        $result['data'] = $this->usage->databaseRows($result['data']);

        return response()->json($result);
    }

    /**
     * GET /usage/databases/{id}
     */
    public function show(int $id): JsonResponse
    {
        $database = WebDatabase::query()->readable()->whereKey($id)->firstOrFail();

        return response()->json($this->usage->databaseRows([$database])[0]);
    }
}
