<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Hosting capabilities of an account for its own API key (spec 035; contract
 * api/components/schemas/AccountSitesCapabilities.yaml).
 *
 * Reports the name prefixes the sites endpoints apply for the account and the
 * plan rules they enforce for client and reseller keys — databases, database
 * users, FTP accounts, SSH access and scheduled tasks — so a panel offers only
 * choices the endpoints accept. Read-only.
 */
class AccountSitesService
{
    /**
     * Contract field => `[sites]` configuration key.
     *
     * @var array<string, string>
     */
    private const PREFIXES = [
        'database' => 'dbname_prefix',
        'database_user' => 'dbuser_prefix',
        'ftp_user' => 'ftpuser_prefix',
        'shell_user' => 'shelluser_prefix',
        'webdav_user' => 'webdavuser_prefix',
    ];

    /**
     * Isolation modes ISPConfig offers for SSH accounts (shell_user.tform.php
     * 136-141 plus the `ssh-chroot` value of the client column).
     *
     * @var array<int, string>
     */
    private const CHROOT_MODES = ['no', 'jailkit', 'ssh-chroot'];

    /**
     * Task kinds an account can reach per `limit_cron_type`
     * (cron_edit.php:145-160; a http(s) command is always `url`).
     *
     * @var array<string, array<int, string>>
     */
    private const CRON_TYPES = [
        'url' => ['url'],
        'chrooted' => ['url', 'chrooted'],
        'full' => ['url', 'chrooted', 'full'],
    ];

    public function __construct(protected SitesConfigService $config) {}

    /**
     * @return array<string, mixed>
     */
    public function capabilities(int $clientId): array
    {
        $client = DB::table('client')->where('client_id', $clientId)->first();
        $groupId = DB::table('sys_group')->where('client_id', $clientId)->value('groupid');

        return [
            'prefixes' => $this->prefixes($groupId === null ? null : (int) $groupId),
            'databases' => [
                'quota_limit_mb' => $this->limitOrNull($client, 'limit_database_quota'),
                // Legacy has no permission for remote access: `remote_access` is
                // a plain checkbox without a valuelimit (database.tform.php:181).
                'remote_access' => true,
            ],
            'shell' => $this->shell($client),
            'cron' => $this->cron($client),
        ];
    }

    /**
     * The prefixes the write endpoints apply for this account, resolved with
     * the account's own group ([DOMAINID] stays unresolved — a capability
     * describes the account, not one website).
     *
     * @return array<string, string>
     */
    protected function prefixes(?int $groupId): array
    {
        $record = $groupId === null ? [] : ['sys_groupid' => $groupId];
        $prefixes = [];

        foreach (self::PREFIXES as $field => $key) {
            $prefixes[$field] = $this->config->sitesPrefix($key, $record);
        }

        return $prefixes;
    }

    /**
     * @return array{available: bool, chroot_options: array<int, string>}
     */
    protected function shell(?object $client): array
    {
        $available = $this->intValue($client, 'limit_shell_user') !== 0;

        if (! $available) {
            return ['available' => false, 'chroot_options' => []];
        }

        // Legacy tform_base::applyValueLimit('client:ssh_chroot'): the offered
        // modes intersected with the client's comma list.
        $allowed = array_map('trim', explode(',', (string) ($client->ssh_chroot ?? '')));

        return [
            'available' => true,
            'chroot_options' => array_values(array_intersect(self::CHROOT_MODES, $allowed)),
        ];
    }

    /**
     * @return array{types: array<int, string>, min_interval_minutes: int|null}
     */
    protected function cron(?object $client): array
    {
        $type = (string) ($client->limit_cron_type ?? 'url');
        $frequency = $this->intValue($client, 'limit_cron_frequency') ?? 0;

        return [
            'types' => self::CRON_TYPES[$type] ?? self::CRON_TYPES['url'],
            // Legacy checks the frequency only when the limit is > 1.
            'min_interval_minutes' => $frequency > 1 ? $frequency : null,
        ];
    }

    /**
     * A limit column as a positive number, or null when it is absent or
     * negative (unlimited).
     */
    protected function limitOrNull(?object $client, string $column): ?int
    {
        $value = $this->intValue($client, $column);

        return $value === null || $value < 0 ? null : $value;
    }

    protected function intValue(?object $client, string $column): ?int
    {
        if ($client === null || ! isset($client->{$column})) {
            return null;
        }

        return (int) $client->{$column};
    }
}
