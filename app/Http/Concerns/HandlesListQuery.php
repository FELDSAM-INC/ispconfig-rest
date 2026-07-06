<?php

namespace App\Http\Concerns;

use App\Models\BaseModel;
use App\Support\IspContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Shared list-endpoint behavior for every module (constitution Principle V):
 * applies the shared query parameters limit/offset/sort/order plus the
 * endpoint's documented filters, and returns the canonical envelope
 * ['data' => [...], 'meta' => ['total', 'limit', 'offset']].
 *
 * Invalid parameter values throw BadRequestHttpException, which the global
 * handler renders as a 400 application/problem+json response (the contract
 * declares 400 for bad list parameters).
 *
 * Usage (see MailDomainController::index for the reference implementation):
 *
 *     $result = $this->listQuery($query, $request,
 *         sortable: ['domain', 'server_id'],   // allowed sort columns
 *         defaultSort: 'domain',
 *         filters: [                            // allowed equality filters
 *             'domain' => 'wildcard',           //   * translated to LIKE %
 *             'active' => 'boolean',            //   true/false/1/0 -> native y/n via the model cast
 *             'server_id' => 'integer',
 *             'type' => 'string',
 *         ]);
 *
 *     return response()->json($result);
 */
trait HandlesListQuery
{
    /**
     * Filter, sort and paginate an Eloquent query from request parameters.
     *
     * @param  Builder  $query  base query (model determines native filter values)
     * @param  array<int, string>  $sortable  whitelisted sort columns
     * @param  string  $defaultSort  column used when no sort parameter is given
     * @param  array<string, string>  $filters  query param => type (boolean|integer|string|wildcard|owning_client)
     * @param  array<int, string>  $extra  params the controller consumes itself (e.g. 'search')
     * @return array{data: array<int, mixed>, meta: array{total: int, limit: int, offset: int}}
     */
    protected function listQuery(Builder $query, Request $request, array $sortable, string $defaultSort, array $filters = [], array $extra = []): array
    {
        // Row-level read scoping (spec 011 FR-006/FR-007): lists on
        // sys-fielded ISPConfig tables are silently filtered to the rows the
        // acting scope may read — applied before filters and the count so
        // meta.total counts visible rows only (parity
        // listform_actions.inc.php:242-247). No-op for admin scopes.
        $model = $query->getModel();

        if ($model instanceof BaseModel && $model->hasSysFields()) {
            app(IspContext::class)->authScope()->applyReadPredicate($query, 'r');
        }

        // Unknown parameters are rejected, not silently ignored: a misspelled
        // filter would otherwise return the unfiltered collection — and a
        // consumer acting on that result (e.g. deleting "the match") would
        // target the wrong resource.
        $allowed = array_merge(['limit', 'offset', 'sort', 'order'], array_keys($filters), $extra);
        $unknown = array_diff(array_keys($request->query()), $allowed);
        if ($unknown !== []) {
            throw new BadRequestHttpException(
                sprintf("Unknown parameter '%s'. Allowed: %s.", implode("', '", $unknown), implode(', ', $allowed))
            );
        }

        $this->applyListFilters($query, $request, $filters);

        // Sorting: shared `sort` (column) + `order` (asc|desc) parameters.
        $sort = $request->query('sort', $defaultSort);
        if (! is_string($sort) || ! in_array($sort, $sortable, true)) {
            throw new BadRequestHttpException(
                sprintf("Invalid sort column '%s'. Allowed: %s.", is_string($sort) ? $sort : gettype($sort), implode(', ', $sortable))
            );
        }

        $order = $request->query('order', 'asc');
        if (! is_string($order) || ! in_array(strtolower($order), ['asc', 'desc'], true)) {
            throw new BadRequestHttpException("Invalid order value. Allowed: 'asc', 'desc'.");
        }

        // Pagination: shared `limit` (default 25, 1-100) + `offset` (>= 0).
        $limit = $this->positiveIntParam($request, 'limit', 25, min: 1, max: 100);
        $offset = $this->positiveIntParam($request, 'offset', 0, min: 0);

        $total = (clone $query)->toBase()->getCountForPagination();

        $data = $query->orderBy($sort, strtolower($order))
            ->skip($offset)
            ->take($limit)
            ->get();

        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * Apply the endpoint's documented equality filters. Boolean filters are
     * translated to the column's native representation through the model's
     * cast (e.g. YesNoBoolean => 'y'/'n' in the correct case), 'wildcard'
     * filters translate * to a SQL LIKE %.
     *
     * @param  array<string, string>  $filters  query param => type
     */
    protected function applyListFilters(Builder $query, Request $request, array $filters): void
    {
        $model = $query->getModel();

        foreach ($filters as $column => $type) {
            $value = $request->query($column);

            if ($value === null) {
                continue;
            }

            if (! is_string($value)) {
                throw new BadRequestHttpException("Invalid value for filter '{$column}'.");
            }

            switch ($type) {
                case 'boolean':
                    $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($bool === null) {
                        throw new BadRequestHttpException("Invalid boolean value for filter '{$column}'. Use true/false or 1/0.");
                    }
                    // Route through the model's cast to get the DB-native value.
                    $native = $model->newInstance()->forceFill([$column => $bool])->getAttributes()[$column] ?? $bool;
                    $query->where($column, $native);
                    break;

                case 'integer':
                    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                        throw new BadRequestHttpException("Invalid integer value for filter '{$column}'.");
                    }
                    $query->where($column, (int) $value);
                    break;

                case 'owning_client':
                    // Filter by the owning client: resolve the client's
                    // sys_group(s) and match the row's sys_groupid. The filter
                    // parameter is the client_id; the column filtered is
                    // sys_groupid. An unknown client yields no rows.
                    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
                        throw new BadRequestHttpException("Invalid client id for filter '{$column}'.");
                    }
                    $groupIds = DB::table('sys_group')->where('client_id', (int) $value)->pluck('groupid')->all();
                    $groupIds === []
                        ? $query->whereRaw('1 = 0')
                        : $query->whereIn('sys_groupid', $groupIds);
                    break;

                case 'wildcard':
                    if (str_contains($value, '*')) {
                        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $value);
                        $query->where($column, 'like', str_replace('*', '%', $escaped));
                    } else {
                        $query->where($column, $value);
                    }
                    break;

                default: // 'string'
                    $query->where($column, $value);
            }
        }
    }

    /**
     * Read an integer query parameter, enforcing the bounds the shared
     * OpenAPI parameters declare (400 problem+json on violation).
     */
    protected function positiveIntParam(Request $request, string $name, int $default, int $min, ?int $max = null): int
    {
        $raw = $request->query($name);

        if ($raw === null) {
            return $default;
        }

        if (! is_string($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            throw new BadRequestHttpException("The '{$name}' parameter must be an integer.");
        }

        $value = (int) $raw;

        if ($value < $min || ($max !== null && $value > $max)) {
            throw new BadRequestHttpException(
                "The '{$name}' parameter must be between {$min} and ".($max ?? 'unbounded').'.'
            );
        }

        return $value;
    }
}
