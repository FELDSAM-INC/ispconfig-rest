<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Services\ChangeStatusResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Changes (contract: api/modules/changes/changes.yaml, spec 015).
 *
 * Read-only view of sys_datalog entries with their processing status for
 * every key. Journal payloads, writer usernames and server ids are never
 * returned; admins read payloads through /monitor/data-logs.
 */
class ChangeController extends Controller
{
    use HandlesListQuery;

    /**
     * @var array<string, string>
     */
    private const ACTIONS = ['i' => 'create', 'u' => 'update', 'd' => 'delete'];

    public function __construct(private readonly ChangeStatusResolver $statuses) {}

    /**
     * GET /changes — filled by user story 2.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => [], 'meta' => ['total' => 0, 'limit' => 25, 'offset' => 0]]);
    }

    /**
     * GET /changes/{changeSetId} — filled by user story 1.
     */
    public function show(Request $request, string $changeSetId): JsonResponse
    {
        throw new NotFoundHttpException('The requested change set does not exist.');
    }

    /**
     * Map a sys_datalog row to the Change shape (data-model.md).
     *
     * @return array<string, mixed>
     */
    private function toChange(object $row): array
    {
        $status = $this->statuses->statusOf($row);
        $value = explode(':', (string) $row->dbidx, 2)[1] ?? '';

        $change = [
            'id' => (int) $row->datalog_id,
            'change_set_id' => (string) $row->session_id,
            'table' => (string) $row->dbtable,
            'record_id' => ctype_digit($value) ? (int) $value : null,
            'action' => self::ACTIONS[strtolower((string) $row->action)] ?? 'update',
            'status' => $status,
        ];

        if ($status === 'failed') {
            $change['error'] = (string) $row->error;
        }

        $change['created_at'] = CarbonImmutable::createFromTimestamp((int) $row->tstamp)->toIso8601String();

        return $change;
    }
}
