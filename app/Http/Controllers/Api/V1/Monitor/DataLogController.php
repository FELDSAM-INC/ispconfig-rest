<?php

namespace App\Http\Controllers\Api\V1\Monitor;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Models\DataLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Monitor Data Logs (contract: api/modules/monitor/data-logs.yaml).
 *
 * Read-only view over ISPConfig's sys_datalog journal — the read-side
 * companion of the datalog write pattern (constitution Principle II):
 * consumers use it to check whether an accepted write has been processed
 * by the server daemons. No POST/PUT/DELETE exists for this resource.
 *
 * Legacy parity: source_code/interface/web/monitor/datalog_list.php and
 * db_mysql.inc.php::datalogStatus() — in particular the per-server
 * "unprocessed" concept, which is a datalog-ID watermark, not a timestamp.
 */
class DataLogController extends Controller
{
    use HandlesListQuery;

    /**
     * Contract sort enum => database column. The API exposes datalog_id as
     * `id`, so the sort parameter speaks API names and is mapped here.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'id' => 'datalog_id',
        'server_id' => 'server_id',
        'dbtable' => 'dbtable',
        'dbidx' => 'dbidx',
        'action' => 'action',
        'tstamp' => 'tstamp',
        'user' => 'user',
        'status' => 'status',
    ];

    /**
     * Stored lowercase (ispconfig3.sql: char(1), legacy writes i/u/d).
     *
     * @var array<int, string>
     */
    private const ACTIONS = ['i', 'u', 'd'];

    /**
     * sys_datalog.status SET values.
     *
     * @var array<int, string>
     */
    private const STATUSES = ['pending', 'ok', 'warning', 'error'];

    /**
     * GET /monitor/data-logs — filtered, sorted, paginated journal list.
     * Default ordering is newest first (sort=id, order=desc).
     */
    public function index(Request $request): JsonResponse
    {
        $query = DataLog::query();

        $this->applyEnumFilter($request, $query, 'action', self::ACTIONS, lowercase: true);
        $this->applyEnumFilter($request, $query, 'status', self::STATUSES);
        $this->applyDateWindow($request, $query);
        $this->applyUnprocessedOnly($request, $query);
        $this->normalizeSortParams($request);

        $result = $this->listQuery(
            $query,
            $request,
            sortable: array_values(self::SORTABLE),
            defaultSort: 'datalog_id',
            filters: [
                'server_id' => 'integer',
                'dbtable' => 'string',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /monitor/data-logs/{id} — one journal entry with the deserialized
     * change payload; implicit binding 404s as problem+json.
     */
    public function show(DataLog $dataLog): JsonResponse
    {
        return response()->json($dataLog);
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
     * Equality filter restricted to a documented enum — out-of-enum values
     * are a 400 per the contract, not a silent empty result. The action
     * filter lowercases its input first (legacy behavior: the DB stores
     * lowercase i/u/d and the old interface accepted either case).
     *
     * @param  array<int, string>  $allowed
     */
    protected function applyEnumFilter(Request $request, Builder $query, string $param, array $allowed, bool $lowercase = false): void
    {
        $value = $request->query($param);

        if ($value === null) {
            return;
        }

        if ($lowercase && is_string($value)) {
            $value = strtolower($value);
        }

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new BadRequestHttpException(sprintf(
                "Invalid value for filter '%s'. Allowed: %s.",
                $param,
                implode(', ', $allowed)
            ));
        }

        $query->where($param, $value);
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

    /**
     * unprocessed_only=true — only entries the given server's daemon has
     * not yet processed. ISPConfig tracks processing progress as a
     * datalog-ID watermark: server.updated holds the ID of the last journal
     * entry the server processed, so unprocessed entries are those with
     * datalog_id > server.updated (legacy: monitor/datalog_list.php and
     * db_mysql.inc.php::datalogStatus() — NOT a tstamp comparison; fixes
     * spec 004 gap G2). Requires server_id per the contract; a server row
     * that does not exist counts as watermark 0 (nothing processed yet).
     */
    protected function applyUnprocessedOnly(Request $request, Builder $query): void
    {
        $raw = $request->query('unprocessed_only');

        if ($raw === null) {
            return;
        }

        if (! is_string($raw)) {
            throw new BadRequestHttpException("Invalid boolean value for 'unprocessed_only'. Use true/false or 1/0.");
        }

        $unprocessedOnly = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($unprocessedOnly === null) {
            throw new BadRequestHttpException("Invalid boolean value for 'unprocessed_only'. Use true/false or 1/0.");
        }

        if (! $unprocessedOnly) {
            return;
        }

        $serverId = $request->query('server_id');

        if ($serverId === null) {
            throw new BadRequestHttpException("The 'unprocessed_only' filter requires 'server_id'.");
        }

        if (! is_string($serverId) || filter_var($serverId, FILTER_VALIDATE_INT) === false) {
            throw new BadRequestHttpException("Invalid integer value for filter 'server_id'.");
        }

        $watermark = (int) DB::table('server')
            ->where('server_id', (int) $serverId)
            ->value('updated');

        $query->where('datalog_id', '>', $watermark);
    }
}
