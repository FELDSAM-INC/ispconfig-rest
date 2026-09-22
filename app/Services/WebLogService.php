<?php

namespace App\Services;

use App\Models\WebDomain;
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
        if ($this->workers === null) {
            $this->workers = Schema::hasTable('api_web_log_workers')
                ? DB::table('api_web_log_workers')->where('heartbeat', '>=', time() - 90)->pluck('server_id')->map(fn ($id) => (int) $id)->all() : [];
        }

        return in_array((int) $site->server_id, $this->workers, true);
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
            $request = json_encode(['kind' => $kind, 'lines' => $lines, 'before' => $before], JSON_THROW_ON_ERROR);
            $id = hash('sha256', implode(':', [$site->server_id, $site->getKey(), $site->sys_groupid, $site->domain, $request]));
            // Results are short-lived: each new refresh queues a fresh read. No web logs in ISPConfig datalog.
            DB::table('api_web_log_reads')->where('id', $id)->where(function ($query): void {
                $query->where('created_at', '<', time() - 30)
                    ->orWhere(fn ($ready) => $ready->whereNotNull('result')->where('created_at', '<', time() - 8));
            })->delete();
            DB::table('api_web_log_reads')->insertOrIgnore([
                'id' => $id, 'server_id' => $site->server_id, 'website_id' => $site->getKey(), 'sys_groupid' => $site->sys_groupid,
                'domain' => $site->domain, 'request' => $request, 'created_at' => time(),
            ]);
            $raw = DB::table('api_web_log_reads')->where('id', $id)->value('result');
            if ($raw === null) {
                return ['state' => 'pending'];
            }
            $result = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
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

    private function reason(string $reason): string
    {
        return in_array($reason, ['logs_archive_limit', 'logs_archive_invalid', 'logs_rotated'], true) ? $reason : 'logs_unavailable';
    }
}
