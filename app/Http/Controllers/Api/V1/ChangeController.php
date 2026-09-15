<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Services\ChangeStatusResolver;
use App\Support\IspContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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

    /**
     * Columns read for the Change shape — never `data` or `user`.
     *
     * @var array<int, string>
     */
    private const ENTRY_COLUMNS = ['datalog_id', 'session_id', 'dbtable', 'dbidx', 'action', 'tstamp', 'server_id', 'error'];

    /**
     * GET /changes — filled by user story 2.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => [], 'meta' => ['total' => 0, 'limit' => 25, 'offset' => 0]]);
    }

    /**
     * GET /changes/{changeSetId} — aggregate status over all visible entries
     * of one change set, entries paged oldest first (research R12).
     */
    public function show(Request $request, string $changeSetId): JsonResponse
    {
        $unknown = array_diff(array_keys($request->query()), ['limit', 'offset']);
        if ($unknown !== []) {
            throw new BadRequestHttpException(
                sprintf("Unknown parameter '%s'. Allowed: limit, offset.", implode("', '", $unknown))
            );
        }

        $limit = $this->positiveIntParam($request, 'limit', 25, min: 1, max: 100);
        $offset = $this->positiveIntParam($request, 'offset', 0, min: 0);

        // One resolver per request: route controllers are cached, the server watermarks are not.
        $statuses = app(ChangeStatusResolver::class);

        $aggregate = (array) $this->visibleEntries()
            ->where('session_id', $changeSetId)
            ->selectRaw('COUNT(*) AS total, MIN(tstamp) AS first_tstamp, '.implode(', ', $statuses->statusCountSelects()))
            ->first();

        $total = (int) ($aggregate['total'] ?? 0);

        if ($total === 0) {
            throw new NotFoundHttpException('The requested change set does not exist.');
        }

        $counts = [];
        foreach (ChangeStatusResolver::STATUSES as $status) {
            $counts[$status] = (int) $aggregate[$status.'_count'];
        }

        $entries = $this->visibleEntries()
            ->where('session_id', $changeSetId)
            ->orderBy('datalog_id')
            ->skip($offset)
            ->take($limit)
            ->get(self::ENTRY_COLUMNS)
            ->map(fn (object $row): array => $this->toChange($row, $statuses))
            ->all();

        return response()->json([
            'id' => $changeSetId,
            'status' => ChangeStatusResolver::aggregate($counts),
            'entry_counts' => $counts,
            'created_at' => CarbonImmutable::createFromTimestamp((int) $aggregate['first_tstamp'])->toIso8601String(),
            'entries' => $entries,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    /**
     * Journal entries visible to the acting key (FR-006): admin keys see all,
     * non-admin keys the entries written under their own username (legacy
     * datalogStatus() user scope).
     */
    private function visibleEntries(): Builder
    {
        $query = DB::table('sys_datalog');
        $context = app(IspContext::class);

        if (! $context->authScope()->isAdmin) {
            $query->where('user', $context->username());
        }

        return $query;
    }

    /**
     * Map a sys_datalog row to the Change shape (data-model.md).
     *
     * @return array<string, mixed>
     */
    private function toChange(object $row, ChangeStatusResolver $statuses): array
    {
        $status = $statuses->statusOf($row);
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
