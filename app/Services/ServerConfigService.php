<?php

namespace App\Services;

use App\Models\Server;
use App\Support\IniConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The server.config INI-blob engine (contract:
 * api/modules/server/server-config.yaml + ServerConfig.yaml and the eleven
 * Server*Config.yaml section schemas).
 *
 * The configuration of a server is ONE INI-style text blob stored in the
 * `config` column of the `server` row. Legacy ISPConfig reads it with its
 * own parser (source_code/interface/lib/classes/ini_parser.inc.php via
 * getconf::get_server_config, which applies stripslashes first) and writes
 * it back whole (server_config_edit.php::onUpdateSave):
 *
 *   parse whole blob -> replace ONE section -> re-serialize whole blob ->
 *   datalogUpdate('server', ['config' => $blob], 'server_id', $id)
 *
 * parse()/serialize() delegate to the canonical App\Support\IniConfig port
 * of ini_parser::parse_ini_string / get_ini_string — the stored blob MUST
 * stay parseable by legacy at all times, and a parse->serialize round trip
 * of a blob legacy itself produced is byte-identical.
 *
 * WRITE DISCIPLINE — server.config has TWO writers, on purpose:
 *  - THIS service persists through Server::save(), which DATALOGS the
 *    update (sys_datalog 'u' on `server` carrying the whole new blob) —
 *    parity with the legacy Server Config panel
 *    (server_config_edit.php::onUpdateSave via tform datalogUpdate);
 *  - ServerIniConfigService (the mail module's spamfilter-config view over
 *    the same blob) writes with a plain UPDATE and NO datalog — parity
 *    with the legacy Spamfilter Config panel (spamfilter_config_edit.php),
 *    which never datalogs. Do NOT "unify" the two disciplines.
 *
 * Merge guarantees on updateSection() (contract, server-config.yaml):
 *  - only the target [section] changes; every other section and key —
 *    including [global] and unknown sections — is preserved byte-for-byte;
 *  - keys inside the target section that are unknown to the section schema
 *    (e.g. sendmail_path, rspamd_redis_passwd, xmpp_use_ispv6 written by the
 *    installer) are preserved untouched, in place;
 *  - omitted text/select keys keep their current value; omitted checkbox
 *    keys are written with their unchecked value 'n' (legacy form
 *    semantics: unchecked checkboxes are not POSTed and get backfilled in
 *    onUpdateSave);
 *  - [mail] only: rspamd_available is ALWAYS forced from the stored blob
 *    (client input ignored); a nonzero mailbox_size_limit smaller than
 *    message_size_limit is rejected with 422; switching content_filter to
 *    rspamd force-datalogs every spamfilter_users / spamfilter_wblist row
 *    of the server (re-sync trigger, legacy onAfterUpdate).
 *
 * Interpretation note (recorded deviation): the section schemas declare
 * additionalProperties: true so that GET can return keys the schema does
 * not model; on PUT, keys outside the schema's field inventory are IGNORED
 * (not written) — mirroring legacy, where tform only ever writes its
 * defined form fields, and protecting the blob from arbitrary injection.
 */
class ServerConfigService
{
    /**
     * The writable sections, i.e. the /servers/{id}/configs/{section}
     * endpoints declared in server-config.yaml. [global] is read-only and
     * has no endpoint.
     *
     * @var array<int, string>
     */
    public const SECTIONS = [
        'server', 'getmail', 'mail', 'web', 'dns', 'fastcgi', 'xmpp',
        'jailkit', 'vlogger', 'cron', 'rescue',
    ];

    /**
     * Per-section field inventory: field => 'checkbox' | array of Laravel
     * validation rules. Source of truth for PUT validation, checkbox
     * backfill and integer typing on read ('integer' rule => int output).
     *
     * Inventories and constraints are verbatim from the Server*Config.yaml
     * schemas, which mirror the legacy tabs of
     * source_code/interface/web/admin/form/server_config.tform.php.
     *
     * @var array<string, array<string, string|array<int, string>>>
     */
    protected const FIELDS = [
        'server' => [
            'auto_network_configuration' => 'checkbox',
            'ip_address' => ['ipv4'],
            'netmask' => ['ipv4'],
            'v6_prefix' => ['nullable', 'string', 'max:255'],
            'gateway' => ['ipv4'],
            'firewall' => ['in:bastille,ufw'],
            'hostname' => ['string', 'max:255', 'regex:/^[\w\.\-]{1,255}\.[a-zA-Z0-9\-]{2,63}$/'],
            'nameservers' => ['string', 'filled', 'max:255'],
            'loglevel' => ['integer', 'in:0,1,2'],
            'admin_notify_events' => ['integer', 'in:0,1,2,3'],
            'backup_dir' => ['nullable', 'string', 'max:255'],
            'backup_tmp' => ['string', 'filled', 'max:255'],
            'backup_dir_is_mount' => 'checkbox',
            'backup_mode' => ['in:userzip,rootgz,borg'],
            'backup_time' => ['string', 'regex:/^\d{1,2}:(00|15|30|45)$/'],
            'backup_delete' => 'checkbox',
            'sysbackup_copies' => ['integer', 'min:0', 'max:999'],
            'monit_url' => ['nullable', 'string', 'regex:/^[0-9a-zA-Z\:\/\-\.\[\]]{0,255}$/'],
            'monit_user' => ['nullable', 'string', 'max:255'],
            'monit_password' => ['nullable', 'string', 'max:255'],
            'munin_url' => ['nullable', 'string', 'regex:/^[0-9a-zA-Z\:\/\-\.\[\]]{0,255}$/'],
            'munin_user' => ['nullable', 'string', 'max:255'],
            'munin_password' => ['nullable', 'string', 'max:255'],
            'monitor_system_updates' => 'checkbox',
            'log_retention' => ['integer', 'min:0'],
            'migration_mode' => 'checkbox',
        ],
        'getmail' => [
            'getmail_config_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
        ],
        'mail' => [
            'module' => ['in:postfix_mysql'],
            'maildir_path' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/\[\]]{5,128}$/'],
            'maildir_format' => ['in:maildir,mdbox'],
            'homedir_path' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'content_filter' => ['in:amavisd,rspamd'],
            'rspamd_password' => ['nullable', 'string', 'max:255'],
            'rspamd_redis_servers' => ['nullable', 'string', 'max:255'],
            'rspamd_redis_password' => ['nullable', 'string', 'max:255'],
            'rspamd_redis_bayes_servers' => ['nullable', 'string', 'max:255'],
            'rspamd_redis_bayes_password' => ['nullable', 'string', 'max:255'],
            'rspamd_available' => 'checkbox', // readOnly: forced from the stored blob
            'dkim_path' => ['nullable', 'string', 'max:255'],
            'dkim_strength' => ['integer', 'in:1024,2048,4096'],
            'pop3_imap_daemon' => ['in:courier,dovecot'],
            'mail_filter_syntax' => ['in:maildrop,sieve'],
            'mailuser_uid' => ['integer', 'min:1999'],
            'mailuser_gid' => ['integer', 'min:1999'],
            'mailuser_name' => ['string', 'regex:/^(?!ispconfig|root)([a-zA-Z0-9]{1,20})$/'],
            'mailuser_group' => ['string', 'regex:/^(?!ispconfig|root)([a-zA-Z0-9]{1,20})$/'],
            'mailbox_virtual_uidgid_maps' => 'checkbox',
            'relayhost' => ['nullable', 'string', 'max:255'],
            'relayhost_user' => ['nullable', 'string', 'max:255'],
            'relayhost_password' => ['nullable', 'string', 'max:255'],
            'reject_sender_login_mismatch' => 'checkbox',
            'reject_unknown' => ['in:helo,client,client_helo,none'],
            'mailbox_size_limit' => ['integer', 'min:0'],
            'message_size_limit' => ['integer', 'min:0'],
            'realtime_blackhole_list' => ['nullable', 'string', 'max:255'],
            'stress_adaptive' => 'checkbox',
            'mailbox_soft_delete' => ['in:n,0,-1,1,7,30,90,365'],
            'mailbox_quota_stats' => 'checkbox',
            'overquota_notify_threshold' => ['integer', 'min:0', 'max:100'],
            'overquota_notify_admin' => 'checkbox',
            'overquota_notify_reseller' => 'checkbox',
            'overquota_notify_client' => 'checkbox',
            'overquota_notify_freq' => ['integer', 'min:0'],
            'overquota_notify_onok' => 'checkbox',
            'rspamd_url' => ['nullable', 'string', 'max:255'],
        ],
        'web' => [
            'server_type' => ['in:apache,nginx'],
            'website_basedir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'website_path' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/\[\]]{5,128}$/'],
            'website_symlinks' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/\[\]\:]{5,128}$/'],
            'website_symlinks_rel' => 'checkbox',
            'network_filesystem' => 'checkbox',
            'website_autoalias' => ['nullable', 'string', 'max:255'],
            'vhost_rewrite_v6' => 'checkbox',
            'vhost_proxy_protocol_enabled' => ['in:n,y,all'],
            'vhost_proxy_protocol_protocols' => ['regex:/^(ipv4|ipv6|ipv4,ipv6)$/'],
            'vhost_proxy_protocol_http_port' => ['integer'],
            'vhost_proxy_protocol_https_port' => ['integer'],
            'vhost_conf_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'vhost_conf_enabled_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'apache_init_script' => ['nullable', 'string', 'regex:/^(|[a-zA-Z0-9\.\-\_]{1,128})$/'],
            'nginx_enable_pagespeed' => 'checkbox',
            'nginx_vhost_conf_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'nginx_vhost_conf_enabled_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'CA_path' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9\.\-\_\/]{0,128}$/'],
            'CA_pass' => ['nullable', 'string', 'max:255'],
            'security_level' => ['in:10,20'],
            'set_folder_permissions_on_update' => 'checkbox',
            'web_folder_permission' => ['in:0710,0711,0750,0751'],
            'web_folder_protection' => 'checkbox',
            'add_web_users_to_sshusers_group' => 'checkbox',
            'check_apache_config' => 'checkbox',
            'enable_sni' => 'checkbox',
            'enable_ip_wildcard' => 'checkbox',
            'logging' => ['in:yes,anon,no'],
            'overtraffic_notify_admin' => 'checkbox',
            'overtraffic_notify_reseller' => 'checkbox',
            'overtraffic_notify_client' => 'checkbox',
            'overtraffic_disable_web' => 'checkbox',
            'overquota_notify_threshold' => ['integer', 'min:0', 'max:100'],
            'overquota_notify_admin' => 'checkbox',
            'overquota_notify_reseller' => 'checkbox',
            'overquota_notify_client' => 'checkbox',
            'overquota_db_notify_threshold' => ['integer', 'min:0', 'max:100'],
            'overquota_db_notify_admin' => 'checkbox',
            'overquota_db_notify_reseller' => 'checkbox',
            'overquota_db_notify_client' => 'checkbox',
            'overquota_notify_freq' => ['integer', 'min:0'],
            'overquota_notify_onok' => 'checkbox',
            'user' => ['string', 'filled', 'max:255'],
            'group' => ['string', 'filled', 'max:255'],
            'connect_userid_to_webid' => 'checkbox',
            'connect_userid_to_webid_start' => ['integer'],
            'nginx_user' => ['string', 'filled', 'max:255'],
            'nginx_group' => ['string', 'filled', 'max:255'],
            'php_ini_path_apache' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'php_ini_path_cgi' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'php_default_hide' => 'checkbox',
            'php_default_name' => ['string', 'filled', 'max:255'],
            'php_fpm_init_script' => ['string', 'regex:/^[a-zA-Z0-9\.\-\_]{1,128}$/'],
            'php_fpm_ini_path' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'php_fpm_pool_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'php_fpm_start_port' => ['integer', 'min:1'],
            'php_fpm_socket_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{5,128}$/'],
            'php_open_basedir' => ['string', 'max:4000', 'regex:/^[a-zA-Z0-9\.\-\_\/\]\[\:]{1,}$/'],
            'php_ini_check_minutes' => ['integer', 'min:0'],
            'php_handler' => ['in:no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm'],
            'php_fpm_default_chroot' => 'checkbox',
            'php_fpm_incron_reload' => 'checkbox',
            'nginx_cgi_socket' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'htaccess_allow_override' => ['string', 'filled', 'max:255'],
            'apps_vhost_enabled' => 'checkbox',
            'apps_vhost_port' => ['integer'],
            'apps_vhost_ip' => ['string', 'max:255'],
            'apps_vhost_servername' => ['nullable', 'string', 'max:255'],
            'awstats_conf_dir' => ['nullable', 'string', 'max:255'],
            'awstats_data_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'awstats_pl' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'awstats_buildstaticpages_pl' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'skip_le_check' => 'checkbox',
            'php_fpm_reload_mode' => ['in:reload,restart'],
            'le_signature_type' => ['in:RSA,ECDSA'],
            'le_delete_on_site_remove' => 'checkbox',
            'le_auto_cleanup' => 'checkbox',
            'le_auto_cleanup_denylist' => ['nullable', 'string', 'max:255'],
            'le_revoke_before_delete' => 'checkbox',
        ],
        'dns' => [
            'bind_user' => ['string', 'regex:/^(?!ispconfig)([a-zA-Z0-9]{1,20})$/'],
            'bind_group' => ['string', 'regex:/^(?!ispconfig)([a-zA-Z0-9]{1,20})$/'],
            'bind_zonefiles_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'bind_keyfiles_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'bind_zonefiles_masterprefix' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9\.\-\_\/]{0,128}$/'],
            'bind_zonefiles_slaveprefix' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9\.\-\_\/]{0,128}$/'],
            'named_conf_path' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'named_conf_local_path' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'disable_bind_log' => 'checkbox',
        ],
        'fastcgi' => [
            'fastcgi_starter_path' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/\[\]]{1,128}$/'],
            'fastcgi_starter_script' => ['string', 'regex:/^[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'fastcgi_alias' => ['string', 'regex:/^[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'fastcgi_phpini_path' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/\[\]]{1,128}$/'],
            'fastcgi_children' => ['integer', 'min:1'],
            'fastcgi_max_requests' => ['integer', 'min:0'],
            'fastcgi_bin' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/\[\]]{1,128}$/'],
            'fastcgi_config_syntax' => ['integer', 'in:1,2'],
        ],
        'xmpp' => [
            'xmpp_use_ipv6' => 'checkbox',
            'xmpp_bosh_max_inactivity' => ['integer', 'min:15', 'max:360'],
            'xmpp_server_admins' => ['nullable', 'string'],
            'xmpp_modules_enabled' => ['nullable', 'string'],
            'xmpp_port_http' => ['integer'],
            'xmpp_port_https' => ['integer'],
            'xmpp_port_pastebin' => ['integer'],
            'xmpp_port_bosh' => ['integer'],
        ],
        'jailkit' => [
            'jailkit_chroot_home' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/\[\]]{1,}$/'],
            'jailkit_chroot_app_sections' => ['string', 'max:1000', 'regex:/^[a-zA-Z0-9\.\-\_\ ]{1,}$/'],
            'jailkit_chroot_app_programs' => ['string', 'max:1000', 'regex:/^[a-zA-Z0-9\.\-\_\/\ ]{1,}$/'],
            'jailkit_chroot_cron_programs' => ['string', 'max:1000', 'regex:/^[a-zA-Z0-9\.\-\_\/\ ]{1,}$/'],
            'jailkit_chroot_authorized_keys_template' => ['nullable', 'string', 'max:1000', 'regex:/^[a-zA-Z0-9\.\-\_\/\ ]*$/'],
            'jailkit_hardlinks' => ['in:allow,no,yes'],
        ],
        'vlogger' => [
            'config_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
        ],
        'cron' => [
            'init_script' => ['string', 'regex:/^[a-zA-Z0-9\-\_]{1,30}$/'],
            'crontab_dir' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
            'wget' => ['string', 'regex:/^\/[a-zA-Z0-9\.\-\_\/]{1,128}$/'],
        ],
        'rescue' => [
            'try_rescue' => 'checkbox',
            'do_not_try_rescue_httpd' => 'checkbox',
            'do_not_try_rescue_mongodb' => 'checkbox',
            'do_not_try_rescue_mysql' => 'checkbox',
            'do_not_try_rescue_mail' => 'checkbox',
        ],
    ];

    public function __construct(protected DatalogService $datalog) {}

    /**
     * Legacy ini_parser::parse_ini_string() — canonical implementation in
     * App\Support\IniConfig::parse() (this class's port was the
     * fixture-proven original it was extracted from).
     *
     * @return array<string, array<string, string>> ordered sections => ordered key/value pairs
     */
    public function parse(string $ini): array
    {
        return IniConfig::parse($ini);
    }

    /**
     * Legacy ini_parser::get_ini_string() — canonical implementation in
     * App\Support\IniConfig::serialize(). parse(serialize(parse($x))) is a
     * fixed point, and serializing an unmodified parse of a legacy-produced
     * blob reproduces it byte for byte.
     *
     * @param  array<string, array<string, mixed>>  $config
     */
    public function serialize(array $config): string
    {
        return IniConfig::serialize($config);
    }

    /**
     * The fully parsed configuration of a server (GET /servers/{id}/configs,
     * ServerConfig.yaml): server_id + every section present in the blob.
     * Sections with an endpoint are integer-typed per their schema; [global]
     * and unknown sections are returned verbatim (strings).
     *
     * @return array<string, mixed>
     */
    public function getConfig(Server $server): array
    {
        $parsed = $this->parseBlob($server);

        $result = ['server_id' => (int) $server->getKey()];

        foreach ($parsed as $section => $values) {
            $result[$section] = isset(self::FIELDS[$section]) ? $this->typed($section, $values) : $values;
        }

        return $result;
    }

    /**
     * One parsed section (GET /servers/{id}/configs/{section}), [] when the
     * blob does not contain it (fresh server row).
     *
     * @return array<string, mixed>
     */
    public function getSection(Server $server, string $section): array
    {
        return $this->typed($section, $this->parseBlob($server)[$section] ?? []);
    }

    /**
     * Laravel validation rules for a section PUT, derived from the field
     * inventory: every key optional ('sometimes'), checkboxes in:y,n.
     * rspamd_available is readOnly — no rule, input ignored.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(string $section): array
    {
        $rules = [];

        foreach (self::FIELDS[$section] as $field => $spec) {
            if ($field === 'rspamd_available') {
                continue;
            }

            $rules[$field] = $spec === 'checkbox'
                ? ['sometimes', 'in:y,n']
                : array_merge(['sometimes'], $spec);
        }

        return $rules;
    }

    /**
     * Whole-blob read-merge-write of one section (PUT
     * /servers/{id}/configs/{section}), mirroring legacy
     * server_config_edit.php::onUpdateSave — see the class docblock for the
     * merge guarantees. Persists through Server::save() (datalog action 'u'
     * on table `server` carrying the full new blob in the `config` field)
     * and fires the mail-section rspamd re-sync side effect when triggered.
     *
     * Call inside a DB transaction.
     *
     * @param  array<string, mixed>  $input  validated section payload
     * @return array<string, mixed> the updated, typed section
     */
    public function updateSection(Server $server, string $section, array $input): array
    {
        $config = $this->parseBlob($server);
        $current = $config[$section] ?? [];

        if ($section === 'mail') {
            $this->guardMailSizeLimits($input, $current);
        }

        $new = $current;

        foreach (self::FIELDS[$section] as $field => $spec) {
            // Legacy onUpdateSave: rspamd_available always comes from the
            // stored config, never from user input.
            if ($section === 'mail' && $field === 'rspamd_available') {
                $new[$field] = (($current[$field] ?? 'n') === 'y') ? 'y' : 'n';

                continue;
            }

            if (array_key_exists($field, $input)) {
                $new[$field] = $this->toIniValue($input[$field]);
            } elseif ($spec === 'checkbox') {
                // Unchecked-checkbox backfill (legacy $field['value'][0]).
                $new[$field] = 'n';
            }
        }

        $config = IniConfig::mergeSection($config, $section, $new);

        $switchesToRspamd = $section === 'mail'
            && ($input['content_filter'] ?? null) === 'rspamd'
            && ($current['content_filter'] ?? '') !== 'rspamd';

        // Server::save() DATALOGS the blob update (server-config panel
        // parity). ServerIniConfigService writes the same column WITHOUT a
        // datalog (spamfilter panel parity) — both are correct, see the
        // class docblock.
        $server->setAttribute('config', $this->serialize($config));
        $server->save();

        if ($switchesToRspamd) {
            $this->resyncSpamfilterRecords((int) $server->getKey());
        }

        return $this->typed($section, $new);
    }

    /**
     * Parse the server's stored blob, applying stripslashes first exactly
     * like legacy getconf::get_server_config().
     *
     * @return array<string, array<string, string>>
     */
    protected function parseBlob(Server $server): array
    {
        return $this->parse(stripslashes($server->rawConfig()));
    }

    /**
     * Legacy server_config_edit.php::onSubmit(): a nonzero mailbox size
     * limit must not be smaller than the message size limit. Compares the
     * effective (request value falling back to stored) values, since the
     * API allows partial section bodies.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $current
     */
    protected function guardMailSizeLimits(array $input, array $current): void
    {
        $mailboxLimit = (int) ($input['mailbox_size_limit'] ?? $current['mailbox_size_limit'] ?? 0);
        $messageLimit = (int) ($input['message_size_limit'] ?? $current['message_size_limit'] ?? 0);

        if ($mailboxLimit !== 0 && $mailboxLimit < $messageLimit) {
            throw ValidationException::withMessages([
                'mailbox_size_limit' => 'The mailbox size limit must be 0 (unlimited) or at least the message size limit.',
            ]);
        }
    }

    /**
     * Legacy server_config_edit.php::onAfterUpdate(), contract-scoped
     * portion: switching content_filter to rspamd force-datalogs every
     * spamfilter_users and spamfilter_wblist row of the server so the
     * daemon re-syncs them (datalog action 'u' with the unchanged record,
     * legacy $force_update — a documented Principle II exception).
     */
    protected function resyncSpamfilterRecords(int $serverId): void
    {
        $users = DB::table('spamfilter_users')->where('server_id', $serverId)->get();

        foreach ($users as $user) {
            $record = (array) $user;
            $this->datalog->updateRecord('spamfilter_users', 'id', (int) $record['id'], $record, true);
        }

        $wblists = DB::table('spamfilter_wblist')->where('server_id', $serverId)->get();

        foreach ($wblists as $wblist) {
            $record = (array) $wblist;
            $this->datalog->updateRecord('spamfilter_wblist', 'wblist_id', (int) $record['wblist_id'], $record, true);
        }
    }

    /**
     * Integer-type the values of a known section for API output: fields
     * whose schema declares them integer come back as ints; everything else
     * (including unknown keys) stays a string, matching the blob.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function typed(string $section, array $values): array
    {
        $fields = self::FIELDS[$section] ?? [];

        foreach ($values as $key => $value) {
            $spec = $fields[$key] ?? null;

            if (is_array($spec) && in_array('integer', $spec, true) && is_numeric($value)) {
                $values[$key] = (int) $value;
            }
        }

        return $values;
    }

    /**
     * Normalize one request value to its INI representation: integers to
     * strings, null to '', newlines stripped (legacy STRIPNL — embedded
     * newlines would corrupt the blob format).
     */
    protected function toIniValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return str_replace(["\r", "\n"], '', trim((string) $value));
    }
}
