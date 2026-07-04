<?php

namespace App\Services;

use App\Models\WebDomain;
use App\Support\IspContext;
use App\Support\LegacyCrypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Web-domain provisioning, mirroring
 * source_code/interface/web/sites/web_vhost_domain_edit.php and
 * web_vhost_domain_del.php:
 *
 *  - derived fields on create (document_root from the server web config
 *    `website_path` template with [website_id]/[client_id]/id-hash
 *    placeholders, system_user=web{id}, system_group=client{client_id},
 *    allow_override / php_open_basedir / php_fpm_chroot / log_retention
 *    from server config, added_date/added_by) — computed BEFORE the
 *    datalog insert so daemons receive the complete record (spec FR-008);
 *  - the Let's Encrypt two-step create (datalog `i` with ssl=n, then a
 *    datalog `u` enabling ssl/ssl_letsencrypt — legacy
 *    _letsencrypt_on_insert);
 *  - config-dependent validations (SNI duplicate-cert check, nginx
 *    rewrite-rules whitelist, PHP-FPM pool inequality, server_php_id
 *    reset);
 *  - the delete cascade (children, ftp/shell/cron/webdav users, backups,
 *    folders + folder users deleted; databases DETACHED via
 *    parent_domain_id -> 0);
 *  - the /ssl subresource writes (ssl_action save/del, forced renew).
 *
 * All writes flow through DatalogService (constitution Principle II); the
 * create path inserts first and datalogs the completed record once,
 * exactly like legacy's LE insert path.
 */
class WebDomainService
{
    public function __construct(
        protected DatalogService $datalog,
        protected SitesConfigService $config,
        protected IspContext $context,
    ) {}

    /**
     * Create a web domain with legacy-parity derived fields; returns the
     * fresh model. Must run inside a DB transaction.
     *
     * @param  array<string, mixed>  $payload  validated request payload
     */
    public function create(array $payload): WebDomain
    {
        $domain = new WebDomain;
        $userProvidedAllowOverride = array_key_exists('allow_override', $payload);
        $userProvidedLogRetention = array_key_exists('log_retention', $payload);
        $userProvidedChroot = array_key_exists('php_fpm_chroot', $payload);
        $userProvidedBasedir = array_key_exists('php_open_basedir', $payload);

        if (isset($payload['stats_password']) && $payload['stats_password'] !== '') {
            $payload['stats_password'] = LegacyCrypt::hash($payload['stats_password']);
        }

        $domain->fill($payload);
        $record = $domain->getAttributes();

        $isChildVhost = in_array($record['type'], ['vhostsubdomain', 'vhostalias'], true);
        $parent = $isChildVhost ? $this->vhostRow((int) $record['parent_domain_id']) : null;

        if ($isChildVhost) {
            // Parent-derived fixed values (legacy: server, group and quota
            // always come from the parent for vhost children).
            $record['server_id'] = (int) $parent->server_id;
            $record['sys_groupid'] = (int) $parent->sys_groupid;
            $record['hd_quota'] = 0;
        }

        $serverId = (int) ($record['server_id'] ?? 0);

        $this->assertUniqueVhost($serverId, $record['ip_address'] ?? null, (string) $record['domain']);
        $this->runConfigDependentChecks($record, null);
        $record['server_php_id'] = $this->resolveServerPhpId($record);

        // System fields (BaseModel defaults are bypassed by the raw insert).
        $record['sys_userid'] = $record['sys_userid'] ?? $this->context->sysUserId();
        $record['sys_groupid'] = $record['sys_groupid'] ?? $this->context->sysGroupId();
        $record['sys_perm_user'] = $record['sys_perm_user'] ?? 'riud';
        $record['sys_perm_group'] = $record['sys_perm_group'] ?? 'riud';
        $record['sys_perm_other'] = $record['sys_perm_other'] ?? '';

        // Legacy Let's Encrypt two-step create: insert with both flags 'n'.
        $letsencryptOnInsert = ($record['ssl'] ?? 'n') === 'y' && ($record['ssl_letsencrypt'] ?? 'n') === 'y';
        if ($letsencryptOnInsert) {
            $record['ssl'] = 'n';
            $record['ssl_letsencrypt'] = 'n';
        }

        $webConfig = $this->config->serverConfig($serverId, 'web');
        $serverConfig = $this->config->serverConfig($serverId, 'server');

        // Pre-insert server defaults (legacy onAfterInsert values that do
        // not need the new id).
        $record['added_date'] = date('Y-m-d');
        $record['added_by'] = $this->context->username();
        if (! $userProvidedLogRetention) {
            $record['log_retention'] = ((int) ($serverConfig['log_retention'] ?? 0)) > 0
                ? (int) $serverConfig['log_retention']
                : 10;
        }
        if (! $userProvidedChroot && ! empty($webConfig['php_fpm_default_chroot'])) {
            $record['php_fpm_chroot'] = $webConfig['php_fpm_default_chroot'];
        }

        $id = (int) DB::table('web_domain')->insertGetId($record, 'domain_id');

        // Derived provisioning fields (legacy onAfterInsert).
        if ($isChildVhost) {
            $documentRoot = (string) $parent->document_root;
            $webFolder = (string) ($record['web_folder'] ?? '');
            $basedir = (string) ($webConfig['php_open_basedir'] ?? '');
            $basedir = str_replace('[website_path]/web', $documentRoot.'/'.$webFolder, $basedir);
            $basedir = str_replace('[website_domain]/web', $record['domain'].'/'.$webFolder, $basedir);
            $basedir = str_replace('[website_path]', $documentRoot, $basedir);
            $basedir = str_replace('[website_domain]', (string) $record['domain'], $basedir);

            $derived = [
                'sys_groupid' => (int) $parent->sys_groupid,
                'system_user' => $parent->system_user,
                'system_group' => $parent->system_group,
                'document_root' => $documentRoot,
                'allow_override' => $parent->allow_override,
                'php_open_basedir' => $userProvidedBasedir ? $record['php_open_basedir'] : $basedir,
            ];
        } else {
            $clientId = (int) DB::table('sys_group')
                ->where('groupid', (int) $record['sys_groupid'])
                ->value('client_id');

            $documentRoot = (string) ($webConfig['website_path'] ?? '');
            $documentRoot = str_replace('[website_id]', (string) $id, $documentRoot);
            $documentRoot = str_replace('[website_idhash_1]', $this->idHash($id, 1), $documentRoot);
            $documentRoot = str_replace('[website_idhash_2]', $this->idHash($id, 2), $documentRoot);
            $documentRoot = str_replace('[website_idhash_3]', $this->idHash($id, 3), $documentRoot);
            $documentRoot = str_replace('[website_idhash_4]', $this->idHash($id, 4), $documentRoot);
            $documentRoot = str_replace('[client_id]', (string) $clientId, $documentRoot);
            $documentRoot = str_replace('[client_idhash_1]', $this->idHash($clientId, 1), $documentRoot);
            $documentRoot = str_replace('[client_idhash_2]', $this->idHash($clientId, 2), $documentRoot);
            $documentRoot = str_replace('[client_idhash_3]', $this->idHash($clientId, 3), $documentRoot);
            $documentRoot = str_replace('[client_idhash_4]', $this->idHash($clientId, 4), $documentRoot);

            $basedir = (string) ($webConfig['php_open_basedir'] ?? '');
            $basedir = str_replace('[website_path]', $documentRoot, $basedir);
            $basedir = str_replace('[website_domain]', (string) $record['domain'], $basedir);

            $derived = [
                'system_user' => 'web'.$id,
                'system_group' => 'client'.$clientId,
                'document_root' => $documentRoot,
                'php_open_basedir' => $userProvidedBasedir ? $record['php_open_basedir'] : $basedir,
            ];

            if (! $userProvidedAllowOverride && ($webConfig['htaccess_allow_override'] ?? '') !== '') {
                $derived['allow_override'] = $webConfig['htaccess_allow_override'];
            }
        }

        DB::table('web_domain')->where('domain_id', $id)->update($derived);

        // Datalog the COMPLETE record once (legacy datalogs the final row in
        // the LE path; spec FR-008 requires the derived values in the
        // payload for every create).
        $row = (array) DB::table('web_domain')->where('domain_id', $id)->first();
        $this->datalog->log('web_domain', 'domain_id', $id, 'i', [], $row);

        // Second step of the LE two-step create: a datalog update enabling
        // ssl + ssl_letsencrypt (legacy onAfterInsert).
        if ($letsencryptOnInsert) {
            DB::table('web_domain')->where('domain_id', $id)->update([
                'ssl' => 'y',
                'ssl_letsencrypt' => 'y',
            ]);
            $newRow = (array) DB::table('web_domain')->where('domain_id', $id)->first();
            $this->datalog->log('web_domain', 'domain_id', $id, 'u', $row, $newRow);
        }

        return WebDomain::query()->findOrFail($id);
    }

    /**
     * Update a web domain; immutable/preserved fields per legacy
     * onBeforeUpdate. Must run inside a DB transaction.
     *
     * @param  array<string, mixed>  $payload  validated request payload
     */
    public function update(WebDomain $domain, array $payload): WebDomain
    {
        // Preserved server-side (contract: system_user/system_group are not
        // writable; web_folder cannot be changed after creation — legacy
        // onSubmit restores it from the DB).
        unset($payload['web_folder']);

        if (isset($payload['stats_password']) && $payload['stats_password'] !== '') {
            $payload['stats_password'] = LegacyCrypt::hash($payload['stats_password']);
        }

        $domain->fill($payload);
        $record = $domain->getAttributes();

        // Unique key check when domain/ip changed (contract: 409).
        if ($domain->isDirty('domain') || $domain->isDirty('ip_address')) {
            $this->assertUniqueVhost(
                (int) $record['server_id'],
                $record['ip_address'] ?? null,
                (string) $record['domain'],
                (int) $domain->getKey()
            );
        }

        if (in_array($record['type'] ?? 'vhost', ['vhostsubdomain', 'vhostalias'], true)) {
            $domain->setAttribute('hd_quota', 0);
        }

        $this->runConfigDependentChecks($domain->getAttributes(), (int) $domain->getKey());
        $domain->setAttribute('server_php_id', $this->resolveServerPhpId($domain->getAttributes()));

        $domain->save();

        return $domain->refresh();
    }

    /**
     * Legacy delete cascade (web_vhost_domain_del.php::onBeforeDelete),
     * everything datalogged. For type=vhost: child domains, FTP users,
     * shell users, cron jobs, WebDAV users and backups are deleted and
     * databases are DETACHED (parent_domain_id -> 0). Protected folders
     * (+ their users) are deleted for every vhost type. Must run inside a
     * DB transaction.
     */
    public function deleteWithCascade(WebDomain $domain): void
    {
        $id = (int) $domain->getKey();

        if (($domain->getAttributes()['type'] ?? '') === 'vhost') {
            $children = DB::table('web_domain')
                ->where('parent_domain_id', $id)
                ->where('type', '!=', 'vhost')
                ->pluck('domain_id');
            foreach ($children as $childId) {
                $this->datalog->deleteRecord('web_domain', 'domain_id', $childId);
            }

            foreach (DB::table('ftp_user')->where('parent_domain_id', $id)->pluck('ftp_user_id') as $ftpId) {
                $this->datalog->deleteRecord('ftp_user', 'ftp_user_id', $ftpId);
            }

            foreach (DB::table('shell_user')->where('parent_domain_id', $id)->pluck('shell_user_id') as $shellId) {
                $this->datalog->deleteRecord('shell_user', 'shell_user_id', $shellId);
            }

            foreach (DB::table('cron')->where('parent_domain_id', $id)->pluck('id') as $cronId) {
                $this->datalog->deleteRecord('cron', 'id', $cronId);
            }

            foreach (DB::table('webdav_user')->where('parent_domain_id', $id)->pluck('webdav_user_id') as $davId) {
                $this->datalog->deleteRecord('webdav_user', 'webdav_user_id', $davId);
            }

            foreach (DB::table('web_backup')->where('parent_domain_id', $id)->pluck('backup_id') as $backupId) {
                $this->datalog->deleteRecord('web_backup', 'backup_id', $backupId);
            }

            // Databases are detached, never deleted.
            foreach (DB::table('web_database')->where('parent_domain_id', $id)->pluck('database_id') as $dbId) {
                $this->datalog->updateRecord('web_database', 'database_id', $dbId, ['parent_domain_id' => 0]);
            }
        }

        $folders = DB::table('web_folder')->where('parent_domain_id', $id)->pluck('web_folder_id');
        foreach ($folders as $folderId) {
            foreach (DB::table('web_folder_user')->where('web_folder_id', $folderId)->pluck('web_folder_user_id') as $folderUserId) {
                $this->datalog->deleteRecord('web_folder_user', 'web_folder_user_id', $folderUserId);
            }
            $this->datalog->deleteRecord('web_folder', 'web_folder_id', $folderId);
        }

        $domain->delete();
    }

    /**
     * Store uploaded certificate material with ssl_action='save' (legacy
     * SSL tab save action; the server plugin installs the cert).
     *
     * @return array{ssl_cert: string, ssl_key: string, ssl_bundle: string, ssl_letsencrypt: bool}
     */
    public function saveSsl(WebDomain $domain, string $cert, string $key, ?string $bundle): array
    {
        $domain->forceFill([
            'ssl_cert' => $cert,
            'ssl_key' => $key,
            'ssl_bundle' => $bundle ?? '',
            'ssl_action' => 'save',
        ])->save();

        $attributes = $domain->getAttributes();

        return [
            'ssl_cert' => (string) $attributes['ssl_cert'],
            'ssl_key' => (string) $attributes['ssl_key'],
            'ssl_bundle' => (string) $attributes['ssl_bundle'],
            'ssl_letsencrypt' => ($attributes['ssl_letsencrypt'] ?? 'n') === 'y',
        ];
    }

    /**
     * Remove the certificate with ssl_action='del' (the server plugin
     * removes the installed cert asynchronously).
     */
    public function deleteSsl(WebDomain $domain): void
    {
        $domain->forceFill([
            'ssl_cert' => '',
            'ssl_key' => '',
            'ssl_bundle' => '',
            'ssl_action' => 'del',
        ])->save();
    }

    /**
     * Let's Encrypt renewal trigger: a forced no-change datalog update
     * (the resync mechanism) making the LE plugin re-evaluate the domain.
     */
    public function renewLetsEncrypt(WebDomain $domain): void
    {
        $domain->forceDatalog()->save();
    }

    /**
     * Reject a create/update that collides with the web_domain unique key
     * (server_id, ip_address, domain) — contract 409.
     */
    protected function assertUniqueVhost(int $serverId, ?string $ipAddress, string $domain, ?int $excludeId = null): void
    {
        $query = DB::table('web_domain')
            ->where('server_id', $serverId)
            ->where('domain', $domain);

        $ipAddress === null || $ipAddress === ''
            ? $query->where(fn ($q) => $q->whereNull('ip_address')->orWhere('ip_address', ''))
            : $query->where('ip_address', $ipAddress);

        if ($excludeId !== null) {
            $query->where('domain_id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new ConflictHttpException(
                "The domain '{$domain}' already exists on this server/IP combination."
            );
        }
    }

    /**
     * Server-config-dependent legacy validations shared by create and
     * update (web_vhost_domain_edit.php::onSubmit):
     *
     *  - SNI duplicate-cert check when the server web config has SNI
     *    disabled;
     *  - nginx rewrite_rules line whitelist (nginx servers only);
     *  - PHP-FPM dynamic pool inequality.
     *
     * @param  array<string, mixed>  $record  merged raw attributes
     */
    protected function runConfigDependentChecks(array $record, ?int $excludeId): void
    {
        $serverId = (int) ($record['server_id'] ?? 0);
        $webConfig = $this->config->serverConfig($serverId, 'web');

        // SNI: only one ssl=y domain per IP when SNI is disabled.
        if (($record['ssl'] ?? 'n') === 'y' && ($webConfig['enable_sni'] ?? '') !== 'y') {
            $existing = DB::table('web_domain')
                ->where('ssl', 'y')
                ->where('ip_address', $record['ip_address'] ?? null)
                ->when($excludeId !== null, fn ($q) => $q->where('domain_id', '!=', $excludeId))
                ->count();

            if ($existing > 0) {
                throw ValidationException::withMessages([
                    'ssl' => 'SNI is disabled on this server: only one SSL-enabled domain is allowed per IP address.',
                ]);
            }
        }

        // nginx rewrite rules whitelist.
        if (($webConfig['server_type'] ?? '') === 'nginx'
            && isset($record['rewrite_rules']) && trim((string) $record['rewrite_rules']) !== ''
            && ! WebDomain::validateRewriteRules((string) $record['rewrite_rules'])) {
            throw ValidationException::withMessages([
                'rewrite_rules' => 'Invalid rewrite rules.',
            ]);
        }

        // PHP-FPM dynamic pool inequality: max_children >= max_spare >=
        // start >= min_spare > 0.
        if (($record['pm'] ?? '') === 'dynamic') {
            $maxChildren = (int) ($record['pm_max_children'] ?? 0);
            $maxSpare = (int) ($record['pm_max_spare_servers'] ?? 0);
            $start = (int) ($record['pm_start_servers'] ?? 0);
            $minSpare = (int) ($record['pm_min_spare_servers'] ?? 0);

            if (! ($maxChildren >= $maxSpare && $maxSpare >= $start && $start >= $minSpare && $minSpare > 0)) {
                throw ValidationException::withMessages([
                    'pm_max_children' => 'PHP-FPM dynamic pool sizes must satisfy pm_max_children >= pm_max_spare_servers >= pm_start_servers >= pm_min_spare_servers > 0.',
                ]);
            }
        }

    }

    /**
     * Custom PHP versions (server_php_id) only exist for php-fpm /
     * fast-cgi — every other mode resets the value to 0 (legacy
     * onSubmit:1284-1304).
     *
     * @param  array<string, mixed>  $record  merged raw attributes
     */
    protected function resolveServerPhpId(array $record): int
    {
        if (! empty($record['server_php_id'])
            && ! in_array($record['php'] ?? '', ['php-fpm', 'fast-cgi'], true)) {
            return 0;
        }

        return (int) ($record['server_php_id'] ?? 0);
    }

    /**
     * Fetch a vhost row for parent derivation.
     */
    protected function vhostRow(int $domainId): object
    {
        $row = DB::table('web_domain')->where('domain_id', $domainId)->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'parent_domain_id' => 'The parent web domain does not exist.',
            ]);
        }

        return $row;
    }

    /**
     * Port of web_vhost_domain_edit.php::id_hash — decimal digits of the
     * id joined with '/' for the [*_idhash_N] docroot placeholders.
     */
    protected function idHash(int $id, int $levels): string
    {
        $hash = ''.$id % 10;
        $id = intdiv($id, 10);
        $levels--;

        while ($levels > 0) {
            $hash .= '/'.$id % 10;
            $id = intdiv($id, 10);
            $levels--;
        }

        return $hash;
    }
}
