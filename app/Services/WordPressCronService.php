<?php

namespace App\Services;

use App\Models\CronJob;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Native cron owns the schedule; the existing jailed worker executes WordPress. */
final class WordPressCronService
{
    public const INTERVALS = [5, 10, 15, 30, 60, 360, 720, 1440];

    public function owner(WebDomain $site): ?object
    {
        return DB::table('client')->join('sys_group', 'sys_group.client_id', '=', 'client.client_id')->where('sys_group.groupid', $site->sys_groupid)->first();
    }

    public function view(WebDomain $site, string $installation): array
    {
        $owner = $this->owner($site);
        $type = $owner->limit_cron_type ?? 'full';
        $frequency = max(1, (int) ($owner->limit_cron_frequency ?? 1));
        $intervals = array_values(array_filter(self::INTERVALS, fn ($n) => $n >= $frequency));
        $row = Schema::hasTable('api_wordpress_cron') ? DB::table('api_wordpress_cron')->where('website_id', $site->getKey())->where('installation', $installation)->first() : null;
        $limit = (int) ($owner->limit_cron ?? -1);
        $used = DB::table('cron')->where('sys_groupid', $site->sys_groupid)->count();
        $reason = ! in_array($type, ['full', 'chrooted'], true) ? 'cron_command_required' : (! $intervals ? 'cron_frequency' : (($limit >= 0 && $used >= $limit && ! $row?->cron_id) ? 'cron_limit' : null));

        return ['available' => $reason === null, 'reason' => $reason, 'state' => $row->state ?? 'disabled', 'interval' => (int) ($row->interval ?? 15),
            'intervals' => $intervals, 'cron_id' => $row?->cron_id ? (int) $row->cron_id : null,
            'last_run' => $row?->last_run ? gmdate('c', $row->last_run) : null, 'last_output' => $row->last_output ?? null, 'error' => $row->error ?? null];
    }

    public function begin(WebDomain $site, array $installation, bool $enable, int $interval): string
    {
        $owner = $this->owner($site);
        if ($owner) {
            DB::table('client')->where('client_id', $owner->client_id)->lockForUpdate()->first();
        }
        $row = DB::table('api_wordpress_cron')->where('website_id', $site->getKey())->where('installation', $installation['id'])->first();
        $identity = app(WordPressService::class)->identity($site);
        abort_if($row && $row->identity !== $identity, 409, 'The managed WordPress schedule belongs to a previous website identity.');
        if (! $enable) {
            abort_unless($row, 409, 'WordPress cron is not managed.');
            if ($row->cron_id) {
                CronJob::query()->find($row->cron_id)?->delete();
            }
            DB::table('api_wordpress_cron')->where('id', $row->id)->update(['cron_id' => null, 'state' => 'disabling', 'error' => null]);

            return $row->id;
        }
        $capability = $this->view($site, $installation['id']);
        abort_unless($capability['available'], 409, $capability['reason']);
        abort_unless(in_array($interval, $capability['intervals'], true), 422, 'The selected interval runs more often than your plan allows.');
        $id = $row->id ?? (string) Str::uuid();
        $type = $owner->limit_cron_type ?? 'full';
        // A shell builtin works in native Jailkit as well as full crons. No site input is executable.
        $directory = ($type === 'chrooted' ? '' : rtrim($site->document_root, '/')).'/private';
        abort_unless(preg_match('~\A/[A-Za-z0-9_./-]+\z~D', $directory) && ! str_contains($directory, '..'), 409, 'Unsupported website directory.');
        $command = ': > '.escapeshellarg($directory.'/.ispcp-wp-cron-'.$id);
        $job = $row?->cron_id ? CronJob::query()->findOrFail($row->cron_id) : new CronJob;
        $job->fill(['parent_domain_id' => $site->getKey(), 'run_min' => $interval < 60 ? '*/'.$interval : '0',
            'run_hour' => $interval < 60 ? '*' : ($interval === 1440 ? '0' : ($interval === 60 ? '*' : '*/'.(int) ($interval / 60))),
            'run_mday' => '*', 'run_month' => '*', 'run_wday' => '*', 'command' => $command, 'active' => true, 'log' => false]);
        $job->forceFill(['type' => $type]);
        app(SitesService::class)->deriveServerAndGroup($job, $site);
        app(ClientLimitService::class)->checkCronLimits($job);
        $job->save(); // Includes count/reseller limits, ownership, locked-account checks and native datalog.
        DB::table('api_wordpress_cron')->updateOrInsert(['id' => $id], ['website_id' => $site->getKey(), 'server_id' => $site->server_id,
            'installation' => $installation['id'], 'identity' => $identity, 'path' => $installation['path'], 'cron_id' => $job->getKey(),
            'interval' => $interval, 'state' => 'enabling', 'error' => null] + ((! $row || $row->state === 'disabled') ? ['previous_captured' => false, 'previous_value' => null, 'last_run' => null] : []));

        return $id;
    }

    public function guard(CronJob $job): void
    {
        abort_if($this->managed($job) !== null, 409, 'This task is managed by WordPress. Change it from the WordPress cron settings.');
    }

    public function managed(CronJob $job): ?array
    {
        $row = Schema::hasTable('api_wordpress_cron') ? DB::table('api_wordpress_cron')->where('cron_id', $job->getKey())->first() : null;

        return $row ? ['website_id' => (int) $row->website_id, 'installation' => $row->installation, 'state' => $row->state, 'last_run' => $row->last_run ? gmdate('c', $row->last_run) : null] : null;
    }
}
