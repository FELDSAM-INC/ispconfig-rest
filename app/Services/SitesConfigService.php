<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Read-only access to ISPConfig's serialized configuration blobs plus the
 * sites name-prefix resolution — a constitution Principle II documented
 * exception (config blobs are read, never written, exactly like legacy
 * getconf.inc.php).
 *
 *  - Global config: sys_ini.config (sysini_id = 1), INI sections [sites]
 *    (ftpuser_prefix, shelluser_prefix, webdavuser_prefix, dbname_prefix,
 *    dbuser_prefix, default_remote_dbserver, ...) and [misc]
 *    (ssh_authentication).
 *  - Per-server config: server.config, INI sections [web] (website_path,
 *    php_open_basedir, htaccess_allow_override, enable_sni, server_type,
 *    php_fpm_default_chroot) and [server] (ip_address, log_retention).
 *
 * Prefix resolution ports tools_sites.inc.php::replacePrefix/getPrefix:
 * the configured pattern may contain [CLIENTNAME], [CLIENTID] and
 * [DOMAINID] placeholders resolved from the owning client group. The API
 * is admin-scoped, so resolution always follows the legacy admin path
 * (group taken from the record, not the session).
 */
class SitesConfigService
{
    /**
     * Global config section from sys_ini (legacy get_global_config()).
     *
     * Deliberately uncached: controller instances are memoized on their
     * routes across requests within one process, so instance-level caches
     * would serve stale config. The reads are single-row pk lookups.
     *
     * @return array<string, string>
     */
    public function globalConfig(string $section): array
    {
        $blob = DB::table('sys_ini')->where('sysini_id', 1)->value('config');

        return $this->parseIni((string) $blob)[$section] ?? [];
    }

    /**
     * Per-server config section from server.config (legacy
     * get_server_config()).
     *
     * @return array<string, string>
     */
    public function serverConfig(int $serverId, string $section): array
    {
        $blob = DB::table('server')->where('server_id', $serverId)->value('config');

        return $this->parseIni((string) $blob)[$section] ?? [];
    }

    /**
     * Resolve a name prefix pattern for a record (legacy
     * tools_sites::replacePrefix, admin path of getClientName/getClientID).
     *
     * @param  string  $pattern  configured pattern, e.g. 'web[DOMAINID]_' or 'c[CLIENTID]'
     * @param  array<string, mixed>  $record  needs parent_domain_id and/or sys_groupid
     */
    public function resolvePrefix(string $pattern, array $record): string
    {
        if ($pattern === '') {
            return '';
        }

        if (str_contains($pattern, '[CLIENTNAME]')) {
            $pattern = str_replace('[CLIENTNAME]', $this->clientName($record), $pattern);
        }

        if (str_contains($pattern, '[CLIENTID]')) {
            $pattern = str_replace('[CLIENTID]', (string) $this->clientId($record), $pattern);
        }

        if (str_contains($pattern, '[DOMAINID]')) {
            $pattern = str_replace(
                '[DOMAINID]',
                ! empty($record['parent_domain_id']) ? (string) $record['parent_domain_id'] : '[DOMAINID]',
                $pattern
            );
        }

        return $pattern;
    }

    /**
     * Resolve one of the sites prefixes (ftpuser_prefix, shelluser_prefix,
     * webdavuser_prefix, dbname_prefix, dbuser_prefix) for a record.
     *
     * @param  array<string, mixed>  $record
     */
    public function sitesPrefix(string $key, array $record): string
    {
        $sites = $this->globalConfig('sites');

        return $this->resolvePrefix((string) ($sites[$key] ?? ''), $record);
    }

    /**
     * The client_id owning a record (legacy tools_sites::getClientID,
     * admin path): via parent_domain_id -> web_domain.sys_groupid, or the
     * record's own sys_groupid, then sys_group.client_id (fallback 0).
     *
     * @param  array<string, mixed>  $record
     */
    public function clientId(array $record): int
    {
        $groupId = $this->clientGroupId($record);

        if ($groupId === null) {
            return 0;
        }

        return (int) DB::table('sys_group')->where('groupid', $groupId)->value('client_id');
    }

    /**
     * The client group name for a record, normalized like legacy
     * tools_sites::getClientName + convertClientName (fallback 'default').
     *
     * @param  array<string, mixed>  $record
     */
    public function clientName(array $record): string
    {
        $groupId = $this->clientGroupId($record);

        $name = $groupId === null
            ? null
            : DB::table('sys_group')->where('groupid', $groupId)->value('name');

        if ($name === null || $name === '') {
            $name = 'default';
        }

        return $this->convertClientName((string) $name);
    }

    /**
     * SSH authentication mode from the [misc] global config
     * (shell_user_edit.php::onSubmit): 'password', 'key' or '' (both).
     */
    public function sshAuthenticationMode(): string
    {
        return (string) ($this->globalConfig('misc')['ssh_authentication'] ?? '');
    }

    /**
     * Determine the owning client group (legacy admin path,
     * tools_sites::getClientID): the parent domain's sys_groupid when
     * parent_domain_id is set, otherwise the record's own sys_groupid.
     *
     * @param  array<string, mixed>  $record
     */
    protected function clientGroupId(array $record): ?int
    {
        if (! empty($record['parent_domain_id'])) {
            $groupId = DB::table('web_domain')
                ->where('domain_id', (int) $record['parent_domain_id'])
                ->value('sys_groupid');

            if ($groupId !== null) {
                return (int) $groupId;
            }
        }

        if (isset($record['sys_groupid'])) {
            return (int) $record['sys_groupid'];
        }

        return null;
    }

    /**
     * Port of tools_sites::convertClientName: lowercase, drop spaces, keep
     * [a-z0-9_], replace anything else with '_'.
     */
    protected function convertClientName(string $name): string
    {
        $allowed = 'abcdefghijklmnopqrstuvwxyz0123456789_';
        $name = strtolower(trim($name));
        $result = '';

        for ($i = 0; $i < strlen($name); $i++) {
            if ($name[$i] === ' ') {
                continue;
            }
            $result .= str_contains($allowed, $name[$i]) ? $name[$i] : '_';
        }

        return $result;
    }

    /**
     * Port of ini_parser.inc.php::parse_ini_string — legacy's own INI
     * dialect ([section] headers, key=value lines, everything trimmed).
     *
     * @return array<string, array<string, string>>
     */
    protected function parseIni(string $ini): array
    {
        $config = [];
        $section = null;
        $ini = str_replace("\r\n", "\n", stripslashes($ini));

        foreach (explode("\n", $ini) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\[([\w\d_]+)\]$/', $line, $matches)) {
                $section = strtolower($matches[1]);
            } elseif ($section !== null && preg_match('/^([\w\d_]+)=(.*)$/', $line, $matches)) {
                $config[$section][trim($matches[1])] = trim($matches[2]);
            }
        }

        return $config;
    }
}
