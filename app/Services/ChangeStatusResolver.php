<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Processing status of sys_datalog entries (spec 015 FR-003..FR-005, research R2/R3/R6).
 *
 * Legacy processing model (ISPConfig 3.3.1p1):
 *  - modules.inc.php::processDatalog() — a server processes entries above its
 *    `server.updated` watermark for its own server_id, its mirror master's
 *    server_id and server_id 0, advancing the watermark per entry;
 *  - db_mysql.inc.php::datalogStatus() — only active servers count;
 *  - db_mysql.inc.php::datalogError() — processing errors land in
 *    sys_datalog.error; the `status` column is never updated (always 'ok').
 *
 * Responsible servers R(s): s itself when active plus every active server
 * mirroring s; for s = 0 every active server. An entry is processed when its
 * datalog_id is at or below T(s) = min(updated over R(s)); an empty R(s)
 * means no server will ever process it (`stalled`).
 *
 * The server table is read once per resolver instance. SQL predicates inline
 * only integers read from that table, so they are safe to use in raw clauses.
 */
class ChangeStatusResolver
{
    /**
     * @var array<int, string>
     */
    public const STATUSES = ['pending', 'applied', 'failed', 'stalled'];

    /**
     * Target server id => threshold watermark, only for targets with at
     * least one responsible active server.
     *
     * @var array<int, int>|null
     */
    private ?array $thresholds = null;

    /**
     * @return array<int, int>
     */
    public function thresholds(): array
    {
        if ($this->thresholds !== null) {
            return $this->thresholds;
        }

        $servers = DB::table('server')->get(['server_id', 'active', 'mirror_server_id', 'updated']);

        $active = [];
        $targets = [];

        foreach ($servers as $server) {
            $targets[(int) $server->server_id] = true;

            if ((int) $server->active === 1) {
                $active[(int) $server->server_id] = $server;
                $targets[(int) $server->mirror_server_id] = true;
            }
        }

        unset($targets[0]);
        $thresholds = [];

        foreach (array_keys($targets) as $target) {
            $watermarks = [];

            if (isset($active[$target])) {
                $watermarks[] = (int) $active[$target]->updated;
            }

            foreach ($active as $serverId => $server) {
                if ($serverId !== $target && (int) $server->mirror_server_id === $target) {
                    $watermarks[] = (int) $server->updated;
                }
            }

            if ($watermarks !== []) {
                $thresholds[$target] = min($watermarks);
            }
        }

        if ($active !== []) {
            $thresholds[0] = min(array_map(fn (object $server): int => (int) $server->updated, $active));
        }

        return $this->thresholds = $thresholds;
    }

    /**
     * Status of one journal row (needs datalog_id, server_id, error).
     */
    public function statusOf(object|array $row): string
    {
        $row = (object) $row;
        $thresholds = $this->thresholds();
        $serverId = (int) $row->server_id;

        if (! array_key_exists($serverId, $thresholds)) {
            return 'stalled';
        }

        if ((int) $row->datalog_id > $thresholds[$serverId]) {
            return 'pending';
        }

        $error = $row->error ?? null;

        return ($error !== null && $error !== '') ? 'failed' : 'applied';
    }

    /**
     * Change set status from per-status counts (FR-005): pending > stalled > failed > applied.
     *
     * @param  array<string, int>  $counts
     */
    public static function aggregate(array $counts): string
    {
        foreach (['pending', 'stalled', 'failed'] as $status) {
            if (($counts[$status] ?? 0) > 0) {
                return $status;
            }
        }

        return 'applied';
    }

    /**
     * Restrict a sys_datalog query to entries with the given status.
     *
     * @template TQuery of \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function applyStatusFilter($query, string $status)
    {
        return $query->whereRaw($this->predicate($status));
    }

    /**
     * `SUM(CASE …)` select expressions named `<status>_count`, for an aggregate query over sys_datalog.
     *
     * @return array<int, string>
     */
    public function statusCountSelects(): array
    {
        return array_map(
            fn (string $status): string => sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END) AS %s', $this->predicate($status), $this->wrap($status.'_count')),
            self::STATUSES
        );
    }

    /**
     * SQL predicate for one status; mutually exclusive and exhaustive over all rows.
     */
    public function predicate(string $status): string
    {
        $error = $this->wrap('error');

        return match ($status) {
            'pending' => $this->watermarkPredicate('>'),
            'stalled' => $this->stalledPredicate(),
            'failed' => sprintf("(%s AND (%s IS NOT NULL AND %s <> ''))", $this->watermarkPredicate('<='), $error, $error),
            'applied' => sprintf("(%s AND (%s IS NULL OR %s = ''))", $this->watermarkPredicate('<='), $error, $error),
            default => throw new \InvalidArgumentException("Unknown change status '{$status}'."),
        };
    }

    private function watermarkPredicate(string $operator): string
    {
        $parts = [];

        foreach ($this->thresholds() as $serverId => $threshold) {
            $parts[] = sprintf('(%s = %d AND %s %s %d)', $this->wrap('server_id'), $serverId, $this->wrap('datalog_id'), $operator, $threshold);
        }

        return $parts === [] ? '(1 = 0)' : '('.implode(' OR ', $parts).')';
    }

    private function stalledPredicate(): string
    {
        $targets = array_keys($this->thresholds());

        return $targets === []
            ? '(1 = 1)'
            : sprintf('(%s NOT IN (%s))', $this->wrap('server_id'), implode(', ', array_map('intval', $targets)));
    }

    private function wrap(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }
}
