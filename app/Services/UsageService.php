<?php

namespace App\Services;

use App\Support\AuthScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing usage figures (spec 017; data-model.md, research R3–R9).
 *
 * Read-only projections over ISPConfig's collector data: website disk usage
 * (`monitor_data` harddisk_quota, KiB), mailbox storage (email_quota, bytes),
 * database sizes (database_size, bytes) and traffic (via TrafficPeriodService).
 * Every blob is matched only to the resource's own server (research R1); blobs
 * older than `config('api.usage.stale_after')` are treated as missing
 * (FR-011). Unknown values are null, never errors. Nothing is written.
 */
class UsageService
{
    /**
     * Website types with usage figures (FR-005).
     */
    public const WEB_TYPES = ['vhost', 'vhostsubdomain', 'vhostalias'];

    private const MB = 1048576;

    public function __construct(
        private readonly MonitorDataService $monitor,
        private readonly TrafficPeriodService $traffic,
        private readonly ClientLimitService $limits,
    ) {}

    /**
     * The client a summary request targets (FR-004, research R7): admin keys
     * must name it (422); client keys get their own client and may name only
     * themselves; reseller keys their own client or one of their clients. Any
     * other or unknown client is 404, never 403, so client ids cannot be probed.
     */
    public function resolveTargetClient(AuthScope $scope, ?int $clientId): int
    {
        if ($scope->isAdmin) {
            if ($clientId === null) {
                throw ValidationException::withMessages([
                    'client_id' => 'The client id is required for admin keys.',
                ]);
            }

            if (! DB::table('client')->where('client_id', $clientId)->exists()) {
                throw new ModelNotFoundException;
            }

            return $clientId;
        }

        $own = $scope->clientId;

        if ($clientId === null || $clientId === $own) {
            if ($own <= 0) {
                throw new ModelNotFoundException;
            }

            return $own;
        }

        if ($scope->isReseller()) {
            $groupId = DB::table('sys_group')->where('client_id', $clientId)->value('groupid');

            if ($groupId !== null && in_array((int) $groupId, $scope->groupIds, true)) {
                return $clientId;
            }
        }

        throw new ModelNotFoundException;
    }

    /**
     * Plan usage summary of one client (FR-002, FR-003, research R9), computed
     * with the client's own control-panel scope so it matches what the client
     * itself sees. No control-panel identity => 404 (owner decision 2026-09-14).
     *
     * @return array<string, mixed>
     */
    public function summary(int $clientId): array
    {
        $scope = AuthScope::forClient($clientId);
        $client = DB::table('client')->where('client_id', $clientId)->first();

        if ($scope === null || $client === null) {
            throw new ModelNotFoundException;
        }

        $sites = $this->readableRows('web_domain', $scope, ['domain', 'type', 'system_user', 'server_id', 'active'])
            ->filter(fn (object $site): bool => in_array($site->type, self::WEB_TYPES, true));
        $vhosts = $sites->where('type', 'vhost');
        $mailboxes = $this->readableRows('mail_user', $scope, ['email', 'server_id']);
        $databases = $this->readableRows('web_database', $scope, ['database_name', 'server_id']);

        $blobs = $this->monitor->latestBlobs(
            ['harddisk_quota', 'email_quota', 'database_size'],
            $vhosts->pluck('server_id')
                ->merge($mailboxes->pluck('server_id'))
                ->merge($databases->pluck('server_id'))
                ->all()
        );

        $disk = $this->total($vhosts->map(fn (object $site): array => $this->diskFigures((string) $site->system_user, (int) $site->server_id, $blobs)));
        $mail = $this->total($mailboxes->map(fn (object $box): array => $this->mailFigures((string) $box->email, (int) $box->server_id, $blobs)));
        $size = $this->total($databases->map(fn (object $db): array => $this->databaseFigures((string) $db->database_name, (int) $db->server_id, $blobs)));

        $activeHostnames = $sites
            ->filter(fn (object $site): bool => strtolower((string) $site->active) === 'y')
            ->pluck('domain')
            ->all();
        $trafficThisMonth = array_sum(array_column($this->traffic->webPeriods($activeHostnames), 'this_month'));

        $counts = [];

        foreach (ClientLimitService::USAGE_COUNT_COLUMNS as $key => $column) {
            $limit = $this->columnValue($client, $column);

            $counts[$key] = [
                'used' => $this->limits->countUsage($scope, $column),
                'limit' => $limit === null || $limit < 0 ? null : $limit,
            ];
        }

        return [
            'client_id' => $clientId,
            'web_disk' => $this->metric($disk['used'], $this->limits->allocatedQuotaBytes($scope, 'limit_web_quota'), $this->columnValue($client, 'limit_web_quota'), $disk['created']),
            'mail_storage' => $this->metric($mail['used'], $this->limits->allocatedQuotaBytes($scope, 'limit_mailquota'), $this->columnValue($client, 'limit_mailquota'), $mail['created']),
            'database_size' => $this->metric($size['used'], $this->limits->allocatedQuotaBytes($scope, 'limit_database_quota'), $this->columnValue($client, 'limit_database_quota'), $size['created']),
            'web_traffic_this_month' => $this->metric($trafficThisMonth, $this->limits->allocatedQuotaBytes($scope, 'limit_traffic_quota'), $this->columnValue($client, 'limit_traffic_quota'), null),
            'counts' => $counts,
            'period' => [
                'this_month_start' => $this->traffic->boundaries()['this_month_start']->format('Y-m-d'),
                'timezone' => $this->traffic->timezone(),
            ],
        ];
    }

    /**
     * Disk figures of one vhost's system user from its server's harddisk_quota
     * blob (research R3): KiB -> bytes; soft/hard limits only when > 0.
     *
     * @param  array<int, array<string, array{data: array<mixed>|null, created: int}>>  $blobs
     * @return array{used: int|null, soft: int|null, hard: int|null, files: int|null, created: int|null}
     */
    protected function diskFigures(string $systemUser, int $serverId, array $blobs): array
    {
        $unknown = ['used' => null, 'soft' => null, 'hard' => null, 'files' => null, 'created' => null];
        $blob = $this->freshBlob($blobs, $serverId, 'harddisk_quota');

        if ($blob === null || $systemUser === '') {
            return $unknown;
        }

        $user = $blob['data']['user'][$systemUser] ?? null;

        if (! is_array($user) || ($used = $this->number($user['used'] ?? null)) === null) {
            return $unknown;
        }

        $soft = $this->number($user['soft'] ?? null);
        $hard = $this->number($user['hard'] ?? null);
        $files = $this->number($user['files'] ?? null);

        return [
            'used' => (int) round($used * 1024),
            'soft' => $soft !== null && $soft > 0 ? (int) round($soft * 1024) : null,
            'hard' => $hard !== null && $hard > 0 ? (int) round($hard * 1024) : null,
            'files' => $files === null ? null : (int) $files,
            'created' => $blob['created'],
        ];
    }

    /**
     * Storage used by one mailbox from its server's email_quota blob (bytes).
     *
     * @param  array<int, array<string, array{data: array<mixed>|null, created: int}>>  $blobs
     * @return array{used: int|null, created: int|null}
     */
    protected function mailFigures(string $email, int $serverId, array $blobs): array
    {
        $blob = $this->freshBlob($blobs, $serverId, 'email_quota');
        $used = $blob === null ? null : $this->number($blob['data'][$email]['used'] ?? null);

        return $used === null
            ? ['used' => null, 'created' => null]
            : ['used' => (int) $used, 'created' => $blob['created']];
    }

    /**
     * Size of one database from its server's database_size blob (bytes).
     *
     * @param  array<int, array<string, array{data: array<mixed>|null, created: int}>>  $blobs
     * @return array{used: int|null, created: int|null}
     */
    protected function databaseFigures(string $databaseName, int $serverId, array $blobs): array
    {
        $blob = $this->freshBlob($blobs, $serverId, 'database_size');

        if ($blob !== null) {
            foreach ($blob['data'] as $entry) {
                if (is_array($entry) && ($entry['database_name'] ?? null) === $databaseName) {
                    $size = $this->number($entry['size'] ?? null);

                    return $size === null
                        ? ['used' => null, 'created' => null]
                        : ['used' => (int) $size, 'created' => $blob['created']];
                }
            }
        }

        return ['used' => null, 'created' => null];
    }

    /**
     * The blob of a server and type when present, decodable and not stale.
     *
     * @param  array<int, array<string, array{data: array<mixed>|null, created: int}>>  $blobs
     * @return array{data: array<mixed>, created: int}|null
     */
    protected function freshBlob(array $blobs, int $serverId, string $type): ?array
    {
        $blob = $blobs[$serverId][$type] ?? null;

        if ($blob === null || $blob['data'] === null) {
            return null;
        }

        $staleAfter = (int) config('api.usage.stale_after.'.$type, 0);

        if ($staleAfter > 0 && Carbon::now()->getTimestamp() - $blob['created'] > $staleAfter) {
            return null;
        }

        return $blob;
    }

    /**
     * Numeric collector value; legacy recursive merges can turn a value into a
     * list of numbers, of which the largest counts (quota_lib::get_quota_data).
     */
    protected function number(mixed $value): ?float
    {
        if (is_array($value)) {
            $numbers = array_map('floatval', array_filter($value, 'is_numeric'));

            return $numbers === [] ? null : max($numbers);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Sum of the known values and the oldest contributing collector timestamp.
     *
     * @param  Collection<int, array{used: int|null, created: int|null}>  $figures
     * @return array{used: int|null, created: int|null}
     */
    protected function total(Collection $figures): array
    {
        $known = $figures->filter(fn (array $figure): bool => $figure['used'] !== null);

        if ($known->isEmpty()) {
            return ['used' => null, 'created' => null];
        }

        return [
            'used' => (int) $known->sum('used'),
            'created' => $known->min('created'),
        ];
    }

    /**
     * @return array{used_bytes: int|null, allocated_bytes: int, limit_bytes: int|null, used_percent: float|null, measured_at: string|null}
     */
    protected function metric(?int $used, int $allocated, ?int $limitMb, ?int $measured): array
    {
        $limitBytes = $limitMb === null || $limitMb < 0 ? null : $limitMb * self::MB;

        return [
            'used_bytes' => $used,
            'allocated_bytes' => $allocated,
            'limit_bytes' => $limitBytes,
            'used_percent' => $this->percent($used, $limitBytes),
            'measured_at' => $this->iso($measured),
        ];
    }

    protected function percent(int|float|null $used, int|float|null $of): ?float
    {
        if ($used === null || $of === null || $of <= 0) {
            return null;
        }

        return round($used / $of * 100, 1);
    }

    protected function iso(?int $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : Carbon::createFromTimestamp($timestamp, $this->traffic->timezone())->toIso8601String();
    }

    /**
     * Rows of a resource table the scope may read (spec 011 'r' predicate).
     *
     * @param  array<int, string>  $columns
     * @return Collection<int, object>
     */
    protected function readableRows(string $table, AuthScope $scope, array $columns): Collection
    {
        if (! Schema::hasTable($table)) {
            return collect();
        }

        /** @var Builder $query */
        $query = $scope->applyReadPredicate(DB::table($table), 'r');

        return $query->get($columns);
    }

    protected function columnValue(object $row, string $column): ?int
    {
        $value = $row->{$column} ?? null;

        return $value === null ? null : (int) $value;
    }
}
