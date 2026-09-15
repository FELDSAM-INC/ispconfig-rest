<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Models\DataLog;
use App\Services\ChangeStatusResolver;
use App\Support\IspContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
     * GET /changes — journal entries visible to the key with their status,
     * newest first by default (research R6/R9).
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->query('sort') !== null) {
            throw new BadRequestHttpException("The 'sort' parameter is not supported; changes are ordered by journal id, use 'order'.");
        }

        $statuses = app(ChangeStatusResolver::class);
        $query = $this->restrictToVisible(DataLog::query()->select(self::ENTRY_COLUMNS));

        $this->applyExactFilter($request, $query, 'table', 'dbtable', 255);
        $this->applyExactFilter($request, $query, 'change_set_id', 'session_id', 64);
        $this->applySinceFilter($request, $query);

        $status = $request->query('status');
        if ($status !== null) {
            if (! is_string($status) || ! in_array($status, ChangeStatusResolver::STATUSES, true)) {
                throw new BadRequestHttpException('Invalid status value. Allowed: '.implode(', ', ChangeStatusResolver::STATUSES).'.');
            }

            $statuses->applyStatusFilter($query, $status);
        }

        if ($request->query('order') === null) {
            $request->query->set('order', 'desc');
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['datalog_id'],
            defaultSort: 'datalog_id',
            extra: ['status', 'table', 'change_set_id', 'since'],
        );

        return response()->json([
            'data' => $result['data']
                ->map(fn (DataLog $row): array => $this->toChange((object) $row->getAttributes(), $statuses))
                ->all(),
            'meta' => $result['meta'],
        ]);
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

        $aggregate = (array) $this->restrictToVisible(DB::table('sys_datalog'))
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

        $entries = $this->restrictToVisible(DB::table('sys_datalog'))
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
     * Restrict a sys_datalog query to entries visible to the acting key
     * (FR-006): admin keys see all, non-admin keys the entries written under
     * their own username (legacy datalogStatus() user scope).
     *
     * @template TQuery of Builder|EloquentBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function restrictToVisible($query)
    {
        $context = app(IspContext::class);

        if (! $context->authScope()->isAdmin) {
            $query->where('user', $context->username());
        }

        return $query;
    }

    private function applyExactFilter(Request $request, EloquentBuilder $query, string $parameter, string $column, int $maxLength): void
    {
        $value = $request->query($parameter);

        if ($value === null) {
            return;
        }

        if (! is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new BadRequestHttpException("Invalid value for filter '{$parameter}'.");
        }

        $query->where($column, $value);
    }

    /**
     * `since` is an ISO 8601 date-time compared with the journal timestamp (tstamp >=).
     */
    private function applySinceFilter(Request $request, EloquentBuilder $query): void
    {
        $since = $request->query('since');

        if ($since === null) {
            return;
        }

        if (! is_string($since) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $since) !== 1) {
            throw new BadRequestHttpException("Invalid 'since' value. Use an ISO 8601 date-time, for example 2026-09-14T10:00:00Z.");
        }

        $query->where('tstamp', '>=', CarbonImmutable::parse($since)->getTimestamp());
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
