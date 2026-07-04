<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the contract's ServerStatus projection (spec 009; schema
 * api/components/schemas/ServerStatus.yaml) from ISPConfig's monitor_data
 * scratch table.
 *
 * monitor_data is written by the server-side 100-monitor_* cronjobs as
 * PHP-serialize()d array blobs, one row per (server_id, type, created);
 * monitor_tools::delOldRecords() prunes rows older than ~240 s per type,
 * so only the NEWEST row per type is meaningful (legacy parity:
 * interface/web/monitor/show_sys_state.php). The table has a composite
 * primary key and is read-only for the API, hence query-builder reads and
 * no Eloquent model (plan 009, Complexity Tracking).
 */
class MonitorDataService
{
    /**
     * Legacy _setState() severity ladder (show_sys_state.php) — the
     * highest-weight state across a server's monitor types wins.
     *
     * @var array<string, int>
     */
    private const STATE_WEIGHTS = [
        'no_state' => 0,
        'ok' => 1,
        'unknown' => 2,
        'info' => 3,
        'warning' => 4,
        'critical' => 5,
        'error' => 6,
    ];

    /**
     * Database 7-value state enum => contract 4-value status enum
     * (ServerStatus.yaml `status` description).
     *
     * @var array<string, string>
     */
    private const STATE_MAP = [
        'no_state' => 'unknown',
        'unknown' => 'unknown',
        'ok' => 'ok',
        'info' => 'ok',
        'warning' => 'warning',
        'critical' => 'error',
        'error' => 'error',
    ];

    /**
     * Flags written by monitor_tools::monitorServices() — 1 running,
     * 0 down, -1 not monitored. Keys absent from older/smaller blobs
     * (e.g. mongodbserver) count as not monitored.
     *
     * @var array<int, string>
     */
    private const SERVICE_KEYS = [
        'webserver',
        'ftpserver',
        'smtpserver',
        'pop3server',
        'imapserver',
        'bindserver',
        'mysqlserver',
        'mongodbserver',
    ];

    /**
     * Legacy skips this type when aggregating the server state
     * (show_sys_state.php: "not as easy as i thought").
     */
    private const STATE_SKIP_TYPES = ['openvz_beancounter'];

    /**
     * Build one ServerStatus projection for a `server` row.
     *
     * Every metric field derives from the newest monitor_data blob of one
     * type; a missing type or a corrupt blob yields null for its fields,
     * never an error (FR-003/FR-017).
     *
     * @param  object{server_id: int|string, server_name: string}  $server
     * @return array<string, mixed>
     */
    public function buildServerStatus(object $server): array
    {
        $latest = $this->latestPerType((int) $server->server_id);

        $serverLoad = $this->decode($latest['server_load']->data ?? null);

        return [
            'server_id' => (int) $server->server_id,
            'server_name' => $server->server_name,
            'status' => $this->aggregateStatus($latest),
            'load_average' => $this->loadAverage($serverLoad),
            'uptime' => $this->uptimeSeconds($serverLoad),
            'memory_usage' => $this->memoryUsage($this->decode($latest['mem_usage']->data ?? null)),
            'disk_usage' => $this->diskUsage($this->decode($latest['disk_usage']->data ?? null)),
            'services' => $this->services($this->decode($latest['services']->data ?? null)),
            'last_updated' => $this->lastUpdated($latest),
        ];
    }

    /**
     * Newest monitor_data row per type for one server. The retention
     * window (~240 s) keeps the row count tiny, so fetching all rows
     * newest-first and keeping the first row seen per type is both
     * correct and cheap (legacy: ORDER BY created DESC per type).
     *
     * @return array<string, object{type: string, created: int|string, data: ?string, state: string}>
     */
    public function latestPerType(int $serverId): array
    {
        $rows = DB::table('monitor_data')
            ->where('server_id', $serverId)
            ->orderByDesc('created')
            ->get(['type', 'created', 'data', 'state']);

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->type] ??= $row;
        }

        return $latest;
    }

    /**
     * unserialize() a collector blob defensively: collectors only ever
     * store plain arrays, so allowed_classes=false closes the PHP
     * object-injection vector, and anything that does not decode to an
     * array (corrupt/truncated mediumtext) is treated as absent (FR-003).
     *
     * @return array<mixed>|null
     */
    private function decode(?string $blob): ?array
    {
        if ($blob === null || $blob === '') {
            return null;
        }

        $payload = @unserialize($blob, ['allowed_classes' => false]);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Highest-severity state across the newest row of every monitor type
     * (legacy _setState ladder, openvz_beancounter skipped), mapped onto
     * the contract's 4-value enum. No monitor data at all => unknown.
     *
     * @param  array<string, object>  $latest
     */
    private function aggregateStatus(array $latest): string
    {
        $states = [];

        foreach ($latest as $type => $row) {
            if (! in_array($type, self::STATE_SKIP_TYPES, true)) {
                $states[] = $row->state;
            }
        }

        if ($states === []) {
            return 'unknown';
        }

        // Legacy seeds the fold with 'ok' before weighing each type.
        $highest = 'ok';
        $highestWeight = self::STATE_WEIGHTS['ok'];

        foreach ($states as $state) {
            $weight = self::STATE_WEIGHTS[$state] ?? 0;

            if ($weight > $highestWeight) {
                $highest = $state;
                $highestWeight = $weight;
            }
        }

        return self::STATE_MAP[$highest] ?? 'unknown';
    }

    /**
     * [load_1, load_5, load_15] from the server_load blob.
     *
     * @param  array<mixed>|null  $blob
     * @return array<int, float>|null
     */
    private function loadAverage(?array $blob): ?array
    {
        if ($blob === null || ! isset($blob['load_1'], $blob['load_5'], $blob['load_15'])) {
            return null;
        }

        return [
            (float) $blob['load_1'],
            (float) $blob['load_5'],
            (float) $blob['load_15'],
        ];
    }

    /**
     * Uptime seconds from the server_load blob's up_days/up_hours/
     * up_minutes — minute resolution; the collector stores no raw
     * seconds counter (FR-006).
     *
     * @param  array<mixed>|null  $blob
     */
    private function uptimeSeconds(?array $blob): ?int
    {
        if ($blob === null || ! isset($blob['up_days'], $blob['up_hours'], $blob['up_minutes'])) {
            return null;
        }

        return (int) $blob['up_days'] * 86400
            + (int) $blob['up_hours'] * 3600
            + (int) $blob['up_minutes'] * 60;
    }

    /**
     * Memory usage percent from the mem_usage blob — a /proc/meminfo map
     * with values in bytes: (MemTotal - MemAvailable) / MemTotal * 100.
     *
     * @param  array<mixed>|null  $blob
     */
    private function memoryUsage(?array $blob): ?float
    {
        if ($blob === null || ! isset($blob['MemTotal'], $blob['MemAvailable'])) {
            return null;
        }

        $total = (float) $blob['MemTotal'];

        if ($total <= 0) {
            return null;
        }

        return round(($total - (float) $blob['MemAvailable']) / $total * 100, 2);
    }

    /**
     * Per-filesystem entries from the disk_usage blob (df -PhT rows).
     * The collector stores percent as the raw df string ("31%") — the
     * contract exposes it as a number.
     *
     * @param  array<mixed>|null  $blob
     * @return array<int, array<string, mixed>>|null
     */
    private function diskUsage(?array $blob): ?array
    {
        if ($blob === null) {
            return null;
        }

        $filesystems = [];

        foreach ($blob as $row) {
            if (! is_array($row) || ! isset($row['fs'])) {
                continue;
            }

            $filesystems[] = [
                'fs' => $row['fs'],
                'type' => $row['type'] ?? null,
                'size' => $row['size'] ?? null,
                'used' => $row['used'] ?? null,
                'available' => $row['available'] ?? null,
                'percent' => isset($row['percent']) ? (float) $row['percent'] : null,
                'mounted' => $row['mounted'] ?? null,
            ];
        }

        return $filesystems;
    }

    /**
     * Per-service booleans from the services blob: 1 => true (running),
     * 0 => false (down), -1 or key absent => null (not monitored). All
     * eight contract keys are always present in the response.
     *
     * @param  array<mixed>|null  $blob
     * @return array<string, bool|null>|null
     */
    private function services(?array $blob): ?array
    {
        if ($blob === null) {
            return null;
        }

        $services = [];

        foreach (self::SERVICE_KEYS as $key) {
            $flag = $blob[$key] ?? -1;
            $services[$key] = in_array((int) $flag, [0, 1], true) ? (bool) $flag : null;
        }

        return $services;
    }

    /**
     * ISO 8601 date-time of MAX(monitor_data.created) for the server —
     * when its monitor data was last collected (FR-011).
     *
     * @param  array<string, object>  $latest
     */
    private function lastUpdated(array $latest): ?string
    {
        $max = null;

        foreach ($latest as $row) {
            $created = (int) $row->created;

            if ($max === null || $created > $max) {
                $max = $created;
            }
        }

        return $max === null ? null : Carbon::createFromTimestamp($max)->toIso8601String();
    }
}
