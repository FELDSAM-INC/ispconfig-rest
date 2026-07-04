<?php

namespace App\Http\Controllers\Api\V1\Monitor;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Monitor System Logs (contract: api/modules/monitor/system-logs.yaml).
 *
 * Read-only view over ISPConfig's sys_log table — the per-server
 * processing log the daemons write while applying datalog changes. Each
 * entry's datalog_id bridges to the /monitor/data-logs resource. GET only;
 * the legacy UI's delete actions are deliberately not exposed (spec 009).
 *
 * Legacy parity: source_code/interface/web/monitor/list/log.list.php —
 * default ordering newest first, filters on server_id and loglevel
 * (0=Debug, 1=Warning, 2=Error).
 */
class SystemLogController extends Controller
{
    use HandlesListQuery;

    /**
     * Contract sort enum => database column. The API exposes syslog_id as
     * `id`, so the sort parameter speaks API names and is mapped here.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'id' => 'syslog_id',
        'server_id' => 'server_id',
        'datalog_id' => 'datalog_id',
        'loglevel' => 'loglevel',
        'tstamp' => 'tstamp',
    ];

    /**
     * sys_log.loglevel values (legacy: 0=Debug, 1=Warning, 2=Error).
     *
     * @var array<int, int>
     */
    private const LOG_LEVELS = [0, 1, 2];

    /**
     * GET /monitor/system-logs — filtered, sorted, paginated log list.
     * Default ordering is newest first (sort=tstamp, order=desc).
     */
    public function index(Request $request): JsonResponse
    {
        $query = SystemLog::query();

        $this->applyLogLevelFilter($request, $query);
        $this->applyDateWindow($request, $query);
        $this->normalizeSortParams($request);

        $result = $this->listQuery(
            $query,
            $request,
            sortable: array_values(self::SORTABLE),
            defaultSort: 'tstamp',
            filters: [
                'server_id' => 'integer',
            ],
            extra: ['loglevel', 'start_date', 'end_date'],
        );

        return response()->json($result);
    }

    /**
     * Validate the sort parameter against the contract enum (API field
     * names), map it to the database column, and materialize the contract's
     * newest-first default order (the shared helper defaults to asc).
     */
    protected function normalizeSortParams(Request $request): void
    {
        $sort = $request->query('sort');

        if ($sort !== null) {
            if (! is_string($sort) || ! isset(self::SORTABLE[$sort])) {
                throw new BadRequestHttpException(sprintf(
                    "Invalid sort column '%s'. Allowed: %s.",
                    is_string($sort) ? $sort : gettype($sort),
                    implode(', ', array_keys(self::SORTABLE))
                ));
            }

            $request->query->set('sort', self::SORTABLE[$sort]);
        }

        if ($request->query('order') === null) {
            $request->query->set('order', 'desc');
        }
    }

    /**
     * Equality filter restricted to the contract's loglevel enum
     * (0=Debug, 1=Warning, 2=Error) — out-of-enum or non-integer values
     * are a 400 per the contract, not a silent empty result.
     */
    protected function applyLogLevelFilter(Request $request, Builder $query): void
    {
        $value = $request->query('loglevel');

        if ($value === null) {
            return;
        }

        if (! is_string($value)
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || ! in_array((int) $value, self::LOG_LEVELS, true)) {
            throw new BadRequestHttpException(sprintf(
                "Invalid value for filter 'loglevel'. Allowed: %s.",
                implode(', ', self::LOG_LEVELS)
            ));
        }

        $query->where('loglevel', (int) $value);
    }

    /**
     * Inclusive tstamp window: start_date/end_date as UNIX timestamps.
     */
    protected function applyDateWindow(Request $request, Builder $query): void
    {
        if ($request->query('start_date') !== null) {
            $query->where('tstamp', '>=', $this->positiveIntParam($request, 'start_date', 0, min: 0));
        }

        if ($request->query('end_date') !== null) {
            $query->where('tstamp', '<=', $this->positiveIntParam($request, 'end_date', 0, min: 0));
        }
    }
}
