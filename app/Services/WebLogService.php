<?php

namespace App\Services;

use App\Models\CronJob;
use App\Models\WebDomain;
use App\Support\CronOutputLog;
use App\Support\WebLogReader;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class WebLogService
{
    private ?array $workers = null;

    public function available(WebDomain $site): bool
    {
        if ($this->local($site)) {
            return $this->reader()->available((string) $site->domain);
        }
        return in_array((int) $site->server_id, $this->workerServers(), true);
    }

    private function local(WebDomain $site): bool
    {
        return (int) config('web_logs.local_server_id') > 0 && (int) $site->server_id === (int) config('web_logs.local_server_id');
    }

    private function reader(): WebLogReader
    {
        return new WebLogReader((string) config('web_logs.root'));
    }

    public function read(WebDomain $site, string $kind, int $lines, ?string $cursor): array
    {
        $before = null;
        if ($cursor !== null) {
            try {
                $decoded = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
                if (($decoded['site'] ?? null) !== (int) $site->getKey() || ($decoded['group'] ?? null) !== (int) $site->sys_groupid || ($decoded['kind'] ?? null) !== $kind || ! is_array($decoded['position'] ?? null)) {
                    throw new RuntimeException;
                }
                $before = $decoded['position'];
            } catch (\Throwable) {
                throw ValidationException::withMessages(['before' => 'The log cursor is invalid for this website and log type.']);
            }
        }
        if (! $this->available($site)) {
            return ['state' => 'unavailable'];
        }
        if ($this->local($site)) {
            try {
                $result = $this->reader()->read((string) $site->domain, $kind, $lines, $before);
            } catch (RuntimeException $e) {
                return ['state' => 'unavailable', 'reason' => $this->reason($e->getMessage())];
            }
        } else {
            $result = $this->queue($site, ['kind' => $kind, 'lines' => $lines, 'before' => $before]);
            if ($result === null) {
                return ['state' => 'pending'];
            }
            if (isset($result['error'])) {
                return ['state' => 'unavailable', 'reason' => $this->reason($result['error'])];
            }
        }
        $next = $result['before'] ?? null;
        unset($result['before']);

        return ['state' => 'ready'] + $result + ['before' => $next === null ? null : Crypt::encryptString(json_encode([
            'site' => (int) $site->getKey(), 'group' => (int) $site->sys_groupid, 'kind' => $kind, 'position' => $next,
        ], JSON_THROW_ON_ERROR))];
    }

    /**
     * The last lines of a scheduled task's own output log (spec 054). Only the web-log worker on the website's server
     * can read the website user's files, also when the API runs on that server.
     */
    public function cron(CronJob $job, int $lines): array
    {
        // Its own log, or the WordPress worker's wp-cron.log for a WordPress cron takeover
        if (CronOutputLog::parse((string) $job->getAttributes()['command']) === null && app(WordPressCronService::class)->managed($job) === null) {
            return ['state' => 'disabled'];
        }
        $site = WebDomain::query()->find((int) $job->getAttributes()['parent_domain_id']);
        if ($site === null || ! in_array((int) $site->server_id, $this->workerServers(), true)) {
            return ['state' => 'unavailable'];
        }
        $result = $this->queue($site, ['kind' => 'cron', 'cron_id' => (int) $job->getKey(), 'lines' => $lines]);
        if ($result === null) {
            return ['state' => 'pending'];
        }
        if (isset($result['error'])) {
            return ['state' => 'unavailable', 'reason' => $this->reason($result['error'])];
        }

        return ['state' => 'ready', 'lines' => $result['lines'] ?? [], 'size' => (int) ($result['size'] ?? 0),
            'modified_at' => isset($result['modified_at']) ? gmdate('c', (int) $result['modified_at']) : null, 'truncated' => (bool) ($result['truncated'] ?? false)];
    }

    /** @return int[] servers with a current web-log worker heartbeat */
    private function workerServers(): array
    {
        if ($this->workers === null) {
            $this->workers = Schema::hasTable('api_web_log_workers')
                ? DB::table('api_web_log_workers')->where('heartbeat', '>=', time() - 90)->pluck('server_id')->map(fn ($id) => (int) $id)->all() : [];
        }

        return $this->workers;
    }

    /**
     * Queues one read for the website's web-log worker; its result, or null while it is pending. Results are
     * short-lived: each new refresh queues a fresh read. No web logs in ISPConfig datalog.
     */
    private function queue(WebDomain $site, array $request): ?array
    {
        $request = json_encode($request, JSON_THROW_ON_ERROR);
        $id = hash('sha256', implode(':', [$site->server_id, $site->getKey(), $site->sys_groupid, $site->domain, $request]));
        DB::table('api_web_log_reads')->where('id', $id)->where(function ($query): void {
            $query->where('created_at', '<', time() - 30)
                ->orWhere(fn ($ready) => $ready->whereNotNull('result')->where('created_at', '<', time() - 8));
        })->delete();
        DB::table('api_web_log_reads')->insertOrIgnore([
            'id' => $id, 'server_id' => $site->server_id, 'website_id' => $site->getKey(), 'sys_groupid' => $site->sys_groupid,
            'domain' => $site->domain, 'request' => $request, 'created_at' => time(),
        ]);
        $raw = DB::table('api_web_log_reads')->where('id', $id)->value('result');

        return $raw === null ? null : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    }

    private function reason(string $reason): string
    {
        return in_array($reason, ['logs_archive_limit', 'logs_archive_invalid', 'logs_rotated'], true) ? $reason : 'logs_unavailable';
    }
}
