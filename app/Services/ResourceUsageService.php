<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHP-FPM resource limits and measured cgroup usage of one account (spec 053).
 * Read-only: the php-limits worker on each webserver writes the API-owned
 * worker and usage rows; this service joins them with the account's websites.
 */
final class ResourceUsageService
{
    public const WEB_TYPES = ['vhost', 'vhostsubdomain', 'vhostalias'];

    /** Worker heartbeat expiry, as for the WAF worker. */
    public const HEARTBEAT_SECONDS = 150;

    /** The worker samples every minute; three missed samples make usage stale. */
    public const INTERVAL_SECONDS = 60;

    public const STALE_AFTER_SECONDS = 180;

    private const METRICS = ['memory_bytes', 'memory_peak_bytes', 'memory_limit_bytes', 'memory_limit_hits_24h',
        'cpu_percent', 'cpu_percent_24h', 'cpu_limit_percent', 'cpu_limited_minutes_24h',
        'tasks', 'tasks_limit', 'tasks_limit_hits_24h'];

    public function __construct(
        private readonly ClientResourceLimitsService $limits,
        private readonly TrafficPeriodService $traffic,
    ) {}

    /** @return array<string, mixed> */
    public function summary(int $clientId): array
    {
        $limits = $this->limits->limits($clientId);
        $revision = $this->limits->revision($clientId);
        $groupIds = DB::table('sys_group')->where('client_id', $clientId)->pluck('groupid')->map(fn ($id): int => (int) $id)->all();
        $sites = $groupIds === [] ? collect() : DB::table('web_domain')
            ->whereIn('sys_groupid', $groupIds)->whereIn('type', self::WEB_TYPES)
            ->orderBy('domain')->orderBy('domain_id')
            ->get(['domain_id', 'domain', 'type', 'parent_domain_id', 'server_id', 'php', 'active']);
        $assigned = array_map('intval', array_filter(explode(',', (string) DB::table('client')->where('client_id', $clientId)->value('web_servers'))));
        $serverIds = array_values(array_unique(array_merge($assigned, $sites->pluck('server_id')->map(fn ($id): int => (int) $id)->all())));
        sort($serverIds);
        $workers = $this->workers($serverIds);
        $usage = $this->usage($clientId);

        $servers = [];
        $oldest = null;
        foreach ($serverIds as $serverId) {
            $available = isset($workers[$serverId]);
            $account = $usage[$serverId]['account'][$clientId] ?? null;
            if ($available && $account !== null) {
                $oldest = $oldest === null ? (int) $account->measured_at : min($oldest, (int) $account->measured_at);
            }
            // The worker only creates a slice where the account has websites.
            $hasSites = $sites->contains(fn (object $site): bool => (int) $site->server_id === $serverId);
            $servers[] = [
                'server_id' => $serverId,
                'available' => $available,
                'applied' => $limits === null || ! $hasSites || ($account !== null && (int) $account->applied_revision === $revision),
                'usage' => $account === null ? null : $this->metrics($account),
            ];
        }
        $applied = array_column($servers, 'applied', 'server_id');

        $websites = $sites->map(function (object $site) use ($limits, $workers, $usage, $applied): array {
            $serverId = (int) $site->server_id;
            $row = $usage[$serverId]['website'][(int) $site->domain_id] ?? null;
            [$state, $reason] = $this->state($site, $limits !== null, isset($workers[$serverId]), $applied[$serverId] ?? false, $row);

            return [
                'domain_id' => (int) $site->domain_id,
                'domain' => (string) $site->domain,
                'type' => (string) $site->type,
                'parent_domain_id' => (int) $site->parent_domain_id,
                'server_id' => $serverId,
                'state' => $state,
                'reason' => $reason,
                'usage' => $row === null || $state === 'unlimited' ? null : $this->metrics($row),
            ];
        })->values()->all();

        return [
            'client_id' => $clientId,
            'limits' => $limits,
            'revision' => $revision,
            'available' => $workers !== [],
            'servers' => $servers,
            'websites' => $websites,
            'freshness' => [
                'interval_seconds' => self::INTERVAL_SECONDS,
                'stale_after_seconds' => self::STALE_AFTER_SECONDS,
                'measured_at' => $this->iso($oldest),
                'next_expected_at' => $this->iso($oldest === null ? null : $oldest + self::INTERVAL_SECONDS),
            ],
        ];
    }

    /**
     * Containment of one website. A limited account's website is only reported as
     * isolated when a fresh worker says so for the revision that is stored now.
     *
     * @return array{0: string, 1: string|null}
     */
    private function state(object $site, bool $limited, bool $available, bool $applied, ?object $row): array
    {
        if (! $limited) {
            return ['unlimited', null];
        }
        if ($site->active !== 'y') {
            return ['inactive', null];
        }
        if ($site->php !== 'php-fpm') {
            return ['not_fpm', 'php_mode_'.preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $site->php))];
        }
        if (! $available) {
            return ['unavailable', null];
        }
        if ($row === null || ! $applied || ! $this->fresh($row)) {
            return ['pending', null];
        }

        return [(string) $row->state, $row->reason === null ? null : (string) $row->reason];
    }

    /** @return array<string, int|float|string|null> */
    private function metrics(object $row): array
    {
        $fresh = $this->fresh($row);
        $metrics = [];
        foreach (self::METRICS as $metric) {
            $value = $fresh ? $row->{$metric} : null;
            $metrics[$metric] = $value === null ? null
                : (str_starts_with($metric, 'cpu_percent') ? round((float) $value, 1) : (int) $value);
        }
        $metrics['measured_at'] = $this->iso((int) $row->measured_at);

        return $metrics;
    }

    private function fresh(object $row): bool
    {
        return (int) $row->measured_at >= Carbon::now()->getTimestamp() - self::STALE_AFTER_SECONDS;
    }

    /**
     * @param  array<int, int>  $serverIds
     * @return array<int, object> fresh, ready workers by server id
     */
    private function workers(array $serverIds): array
    {
        if ($serverIds === [] || ! Schema::hasTable('api_php_limits_workers')) {
            return [];
        }

        return DB::table('api_php_limits_workers')->whereIn('server_id', $serverIds)
            ->where('heartbeat', '>=', Carbon::now()->getTimestamp() - self::HEARTBEAT_SECONDS)->where('status', 'ready')
            ->get()->keyBy(fn (object $row): int => (int) $row->server_id)->all();
    }

    /** @return array<int, array<string, array<int, object>>> server => scope => id => row */
    private function usage(int $clientId): array
    {
        if (! Schema::hasTable('api_php_limits_usage')) {
            return [];
        }
        $rows = [];
        /** @var Collection<int, object> $all */
        $all = DB::table('api_php_limits_usage')->where('client_id', $clientId)->get();
        foreach ($all as $row) {
            $rows[(int) $row->server_id][(string) $row->scope][(int) $row->scope_id] = $row;
        }

        return $rows;
    }

    private function iso(?int $timestamp): ?string
    {
        return $timestamp === null ? null : Carbon::createFromTimestamp($timestamp, $this->traffic->timezone())->toIso8601String();
    }
}
