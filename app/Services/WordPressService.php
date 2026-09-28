<?php

namespace App\Services;

use App\Models\WebDatabase;
use App\Models\WebDomain;
use App\Support\IspContext;
use App\Support\WordPressPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WordPressService
{
    private ?array $workers = null;

    public function worker(WebDomain $site): ?object
    {
        if ($this->workers === null) {
            $this->workers = Schema::hasTable('api_wordpress_workers')
                ? DB::table('api_wordpress_workers')->where('heartbeat', '>=', time() - 150)->get()->keyBy('server_id')->all() : [];
        }

        return $this->workers[$site->server_id] ?? null;
    }

    public function identity(WebDomain $site): string
    {
        return WordPressPolicy::identity($site->getAttributes(), (string) app(WebRuntimeService::class)->publicRoot($site));
    }

    public function snapshot(WebDomain $site): ?object
    {
        return DB::table('api_wordpress_sites')->where('website_id', $site->getKey())->where('identity', $this->identity($site))->first();
    }

    public function summary(WebDomain $site): array
    {
        $worker = $this->worker($site);
        $snapshot = Schema::hasTable('api_wordpress_sites') ? $this->snapshot($site) : null;
        $installs = json_decode($snapshot->installations ?? '[]', true) ?: [];
        $public = array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'path', 'title', 'url', 'admin_url'])), $installs);

        return ['available' => (bool) ($worker->available ?? false), 'count' => count($installs), 'installations' => $public];
    }

    public function view(WebDomain $site): array
    {
        $worker = $this->worker($site);
        $snapshot = Schema::hasTable('api_wordpress_sites') ? $this->snapshot($site) : null;
        $installs = json_decode($snapshot->installations ?? '[]', true) ?: [];
        // The worker snapshot contains private DB matching and undo metadata. Never serialize it wholesale.
        $public = array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'path', 'url', 'admin_url', 'version', 'title', 'checked_at', 'security', 'error', 'integrity'])) + ['cron' => app(WordPressCronService::class)->view($site, $row['id'])], $installs);
        $job = Schema::hasTable('api_wordpress_jobs') ? DB::table('api_wordpress_jobs')->where('website_id', $site->getKey())->where('identity', $this->identity($site))->where('action', '!=', 'cron_run')->orderByDesc('created_at')->orderByDesc('id')->first() : null;

        return ['available' => (bool) ($worker->available ?? false), 'reason' => $worker->reason ?? ($worker ? null : 'worker_unavailable'),
            'scanned_at' => $snapshot ? gmdate('c', $snapshot->scanned_at) : null, 'scan_incomplete' => (bool) ($snapshot->incomplete ?? false), 'installations' => $public,
            'measures' => array_map(fn ($key) => ['id' => $key, 'reversible' => ! in_array($key, WordPressPolicy::ONE_WAY, true),
                'available' => in_array($key, ['prefix', 'admin_login'], true) ? (int) ($worker->version ?? 0) >= 3 : (! in_array($key, WordPressPolicy::SERVER, true) || $site->web_server_type === 'apache'),
                'reason' => in_array($key, ['prefix', 'admin_login'], true) && (int) ($worker->version ?? 0) < 3 ? 'worker_update_required' : (in_array($key, WordPressPolicy::SERVER, true) && $site->web_server_type !== 'apache' ? 'apache_required' : null)], [...WordPressPolicy::SERVER, ...WordPressPolicy::LOCAL]),
            'tools_available' => (bool) ($worker->available ?? false) && (int) ($worker->version ?? 0) >= 2,
            'job' => $job ? $this->present($job) : null];
    }

    public function create(WebDomain $site, array $input): array
    {
        abort_unless(app(IspContext::class)->authScope()->allows($site->getAttributes(), 'u'), 403);
        app(LockedClientGuard::class)->checkBackupWrite($site);
        abort_unless($site->active && in_array($site->type, ['vhost', 'vhostsubdomain', 'vhostalias'], true), 409, 'WordPress requires an active vhost.');
        abort_unless($this->worker($site)?->available && app(WebRuntimeService::class)->publicRoot($site), 409, 'The WordPress worker is unavailable.');
        if (in_array($input['action'], ['verify_integrity', 'cron_enable', 'cron_disable'], true)) {
            abort_unless((int) $this->worker($site)->version >= 2, 409, 'Update the WordPress worker to enable these tools.');
        }

        return DB::transaction(function () use ($site, $input): array {
            DB::table('api_wordpress_workers')->where('server_id', $site->server_id)->lockForUpdate()->first();
            $site = WebDomain::query()->readable()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(app(IspContext::class)->authScope()->allows($site->getAttributes(), 'u'), 403);
            abort_unless($site->active && in_array($site->type, ['vhost', 'vhostsubdomain', 'vhostalias'], true), 409, 'WordPress requires an active vhost.');
            app(LockedClientGuard::class)->checkBackupWrite($site);
            abort_if(DB::table('api_wordpress_jobs')->where('website_id', $site->getKey())->whereIn('status', ['queued', 'running', 'recovery_required'])->exists(), 409, 'A WordPress operation is pending or needs recovery.');
            $installation = null;
            $backup = null;
            if ($input['action'] !== 'rescan') {
                $snapshot = $this->snapshot($site);
                $installs = json_decode($snapshot->installations ?? '[]', true) ?: [];
                foreach ($installs as $row) {
                    if ($row['id'] === $input['installation']) {
                        $installation = $row;
                        break;
                    }
                }
                abort_unless($installation, 404, 'Re-scan this website to find the installation.');
                if (in_array($input['action'], ['secure', 'revert'], true)) {
                    foreach ($input['measures'] as $measure) {
                        abort_if(($installation['security'][$measure]['status'] ?? '') === 'unavailable', 409, 'This security measure is unavailable; run a security check.');
                    }
                }
                if (in_array($input['action'], ['secure', 'revert'], true) && array_intersect($input['measures'], ['prefix', 'admin_login'])) {
                    abort_unless((int) $this->worker($site)->version >= 3, 409, 'Update the WordPress worker to enable reversible database changes.');
                    $database = WebDatabase::query()->readable()->whereKey($installation['database_id'] ?? 0)->first();
                    abort_unless($database && $database->sys_groupid == $site->sys_groupid && $database->server_id == $site->server_id, 409, 'A local, owned database is required.');
                    $input['database_change'] = true;
                    if ($input['action'] === 'revert') {
                        foreach (array_intersect($input['measures'], ['prefix', 'admin_login']) as $measure) {
                            abort_unless(($installation['security'][$measure]['can_revert'] ?? false) === true, 409, 'Run a security check; the previous value is unavailable or has changed.');
                        }
                    }
                }
                if (in_array($input['action'], ['secure', 'revert'], true)) {
                    $this->native($site, $installation['path'], $input['measures'], $input['action'] === 'secure');
                }
                if (in_array($input['action'], ['cron_enable', 'cron_disable'], true)) {
                    $input['cron_token'] = app(WordPressCronService::class)->begin($site, $installation, $input['action'] === 'cron_enable', (int) ($input['interval'] ?? 15));
                }
            }
            $payload = $input + ['path' => $installation['path'] ?? null, 'public_root' => app(WebRuntimeService::class)->publicRoot($site), 'database_id' => $installation['database_id'] ?? null];
            $job = ['id' => (string) Str::uuid(), 'website_id' => (int) $site->getKey(), 'server_id' => (int) $site->server_id,
                'identity' => $this->identity($site), 'action' => $input['action'], 'status' => 'queued', 'request' => json_encode($payload, JSON_THROW_ON_ERROR),
                'backup_id' => $backup, 'created_at' => time(), 'finished_at' => null, 'error' => null];
            DB::table('api_wordpress_jobs')->insert($job);

            return $this->present((object) $job);
        });
    }

    private function native(WebDomain $site, string $path, array $measures, bool $secure): void
    {
        $server = array_values(array_intersect($measures, WordPressPolicy::SERVER));
        if ($server) {
            abort_unless($site->web_server_type === 'apache', 409, 'These measures require Apache.');
            try {
                $raw = (string) $site->apache_directives;
                $current = WordPressPolicy::blocks($raw)[WordPressPolicy::id($path)]['measures'] ?? [];
                $selected = $secure ? array_unique([...$current, ...$server]) : array_diff($current, $server);
                $site->apache_directives = WordPressPolicy::replace($raw, $path, $selected);
                if (strlen($site->apache_directives) > 60000) {
                    throw new InvalidArgumentException('The generated configuration is too large.');
                }
            } catch (InvalidArgumentException $e) {
                throw new ConflictHttpException($e->getMessage());
            }
        }
        if ($secure && in_array('languages', $measures, true)) {
            foreach (['cgi', 'ssi', 'perl', 'python', 'ruby'] as $field) {
                $site->setAttribute($field, false);
            }
        }
        if ($site->isDirty()) {
            $site->save();
        }
    }

    public function job(WebDomain $site, string $id): array
    {
        $job = DB::table('api_wordpress_jobs')->where('id', $id)->where('website_id', $site->getKey())->where('identity', $this->identity($site))->first();
        abort_unless($job, 404);

        return $this->present($job);
    }

    public function guardSite(int $siteId): void
    {
        $busy = Schema::hasTable('api_wordpress_jobs') && DB::table('api_wordpress_jobs')->where('website_id', $siteId)
            ->whereIn('status', ['queued', 'running', 'recovery_required'])->exists();
        abort_if($busy, 409, 'A WordPress operation is pending or needs recovery.');
    }

    public function guardDatabaseUser(int $userId): void
    {
        $this->lockWorkers(DB::table('web_database')->where('database_user_id', $userId)->pluck('server_id')->all());
        foreach (DB::table('web_database')->where('database_user_id', $userId)->pluck('database_id') as $id) {
            $this->guardDatabase((int) $id);
        }
    }

    public function guardDatabase(int $databaseId): void
    {
        $this->lockWorkers([(int) DB::table('web_database')->where('database_id', $databaseId)->value('server_id')]);
        $busy = Schema::hasTable('api_wordpress_jobs') && DB::table('api_wordpress_jobs as wp')
            ->leftJoin('api_database_operations as backup', 'backup.id', '=', 'wp.backup_id')
            ->where(fn ($query) => $query->where('backup.database_id', $databaseId)->orWhere('wp.request->database_id', $databaseId))->whereIn('wp.status', ['queued', 'running', 'recovery_required'])->exists();
        abort_if($busy, 409, 'A WordPress operation is pending or needs recovery.');
    }

    private function lockWorkers(array $servers): void
    {
        if (DB::transactionLevel() > 0 && Schema::hasTable('api_wordpress_workers')) {
            DB::table('api_wordpress_workers')->whereIn('server_id', $servers)->orderBy('server_id')->lockForUpdate()->get();
        }
    }

    public function present(object $row): array
    {
        $backupDatabase = $row->backup_id ? DB::table('api_database_operations')->where('id', $row->backup_id)->where('expires_at', '>', time())->value('database_id') : null;
        if ($backupDatabase && ! WebDatabase::query()->readable()->whereKey($backupDatabase)->exists()) {
            $backupDatabase = null;
        }

        $configurationPending = false;
        if ($row->status === 'completed') {
            $snapshot = DB::table('api_wordpress_sites')->where('website_id', $row->website_id)->where('identity', $row->identity)->value('installations');
            foreach (json_decode($snapshot ?? '[]', true) ?: [] as $installation) {
                foreach (WordPressPolicy::SERVER as $id) {
                    $configurationPending = $configurationPending || ($installation['security'][$id]['status'] ?? '') === 'pending';
                }
            }
        }

        return ['configuration_pending' => $configurationPending, 'backup_database_id' => $backupDatabase ? (int) $backupDatabase : null, 'id' => $row->id, 'action' => $row->action, 'status' => $row->status, 'created_at' => gmdate('c', $row->created_at),
            'finished_at' => $row->finished_at ? gmdate('c', $row->finished_at) : null, 'error' => $row->error, 'backup_id' => $row->backup_id];
    }
}
