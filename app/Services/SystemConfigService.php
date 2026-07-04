<?php

namespace App\Services;

use App\Models\SysIni;
use App\Support\IniConfig;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The sys_ini INI engine (contract: api/modules/system/*-config.yaml).
 *
 * Faithful port of the legacy blob handling:
 *  - parseBlob() = getconf::get_global_config (stripslashes on read) +
 *    ini_parser::parse_ini_string — CRLF-tolerant, `[section]` headers,
 *    `key=value` lines, keys/values trimmed, section names lowercased.
 *  - buildBlob() = ini_parser::get_ini_string — `[section]\n` + `key=value\n`
 *    per key (values trimmed) + one trailing blank line per section.
 *  - updateSections() = system_config_edit.php::onUpdateSave — read-merge-
 *    write of the WHOLE blob: submitted keys are merged into their section,
 *    every unsubmitted key (including legacy keys the API schemas never
 *    expose, e.g. [mail] smtp_*, [sites] client_protection) survives
 *    byte-for-byte, and the result is persisted through exactly one
 *    sys_datalog 'u' entry for sys_ini via SysIni::save()
 *    (legacy datalogUpdate('sys_ini', {config}, 'sysini_id', 1)).
 *
 * The exposed field set per section is the contract's (a deliberate subset
 * of the legacy tform); defaults and validation rules mirror
 * source_code/interface/web/admin/form/system_config.tform.php.
 *
 * The INI parsing/serialization itself is the canonical shared port in
 * App\Support\IniConfig (also used by ServerConfigService and
 * ServerIniConfigService for server.config).
 */
class SystemConfigService
{
    public const SECTIONS = ['sites', 'mail', 'dns', 'domains', 'misc'];

    /**
     * Field types: 'string' | 'yn' (enum y/n, stored verbatim) |
     * 'integer' (cast on read, serialized back as a plain string) |
     * 'csv_array' (JSON array <-> comma-separated blob value).
     * 'strip' marks legacy STRIPTAGS/STRIPNL SAVE filters.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function fieldMap(): array
    {
        $prefixRegex = '/^[a-zA-Z0-9\-\_\[\]]{0,50}$/';

        return [
            'sites' => [
                'dbname_prefix' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'regex:'.$prefixRegex]],
                'dbuser_prefix' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'regex:'.$prefixRegex]],
                'ftpuser_prefix' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'regex:'.$prefixRegex]],
                'shelluser_prefix' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'regex:'.$prefixRegex]],
                'default_webserver' => ['type' => 'integer', 'default' => '1', 'rules' => ['integer']],
                'default_dbserver' => ['type' => 'integer', 'default' => '1', 'rules' => ['integer']],
                'disable_client_remote_dbserver' => ['type' => 'yn', 'default' => 'n'],
                'default_remote_dbserver' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'max:255', $this->ipListRule()]],
                'web_php_options' => ['type' => 'csv_array', 'default' => '', 'rules' => ['array', 'min:1'], 'item_rules' => [Rule::in(['no', 'fast-cgi', 'cgi', 'mod', 'suphp', 'php-fpm', 'hhvm'])]],
                'ssh_authentication' => ['type' => 'string', 'default' => '', 'rules' => ['string', Rule::in(['', 'password', 'key'])]],
                'le_caa_autocreate_options' => ['type' => 'yn', 'default' => 'y'],
                'postgresql_database' => ['type' => 'yn', 'default' => 'n'],
            ],
            'mail' => [
                'enable_custom_login' => ['type' => 'yn', 'default' => 'n'],
                'enable_welcome_mail' => ['type' => 'yn', 'default' => 'y'],
                'show_per_domain_relay_options' => ['type' => 'yn', 'default' => 'n'],
                'mailbox_show_autoresponder_tab' => ['type' => 'yn', 'default' => 'y'],
                'mailbox_show_mail_filter_tab' => ['type' => 'yn', 'default' => 'y'],
                'mailbox_show_custom_rules_tab' => ['type' => 'yn', 'default' => 'y'],
                'mailbox_show_last_access' => ['type' => 'yn', 'default' => 'n'],
                'mailboxlist_webmail_link' => ['type' => 'yn', 'default' => 'n'],
                'webmail_url' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'regex:/^[0-9a-zA-Z\:\/\-\.\[\]]{0,255}$/']],
                'mailmailinglist_link' => ['type' => 'yn', 'default' => 'n'],
                'mailmailinglist_url' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'regex:/^[0-9a-zA-Z\:\/\-\.]{0,255}$/']],
                'default_mailserver' => ['type' => 'integer', 'default' => '1', 'rules' => ['integer']],
            ],
            'dns' => [
                'default_dnsserver' => ['type' => 'integer', 'default' => '1', 'rules' => ['integer']],
                'default_slave_dnsserver' => ['type' => 'integer', 'default' => '1', 'rules' => ['integer']],
                'dns_external_slave_fqdn' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'max:255'], 'strip' => true],
                'dns_show_zoneexport' => ['type' => 'yn', 'default' => 'n'],
            ],
            'domains' => [
                'use_domain_module' => ['type' => 'yn', 'default' => 'n'],
                // Legacy applies STRIPTAGS only; newlines are additionally
                // stripped because the INI format cannot represent them
                // (legacy would silently corrupt the blob).
                'new_domain_html' => ['type' => 'string', 'default' => '', 'rules' => ['string'], 'strip' => true],
            ],
            'misc' => [
                'company_name' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'max:255'], 'strip' => true],
                'custom_login_text' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'max:255'], 'strip' => true],
                'custom_login_link' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'regex:/^(http|https):\/\/.*|^$/'], 'strip' => true],
                'dashboard_atom_url_admin' => ['type' => 'string', 'default' => 'https://www.ispconfig.org/atom', 'rules' => ['string', 'max:255'], 'strip' => true],
                'dashboard_atom_url_reseller' => ['type' => 'string', 'default' => 'https://www.ispconfig.org/atom', 'rules' => ['string', 'max:255'], 'strip' => true],
                'dashboard_atom_url_client' => ['type' => 'string', 'default' => 'https://www.ispconfig.org/atom', 'rules' => ['string', 'max:255'], 'strip' => true],
                'tab_change_discard' => ['type' => 'yn', 'default' => 'n'],
                'tab_change_warning' => ['type' => 'yn', 'default' => 'n'],
                'use_loadindicator' => ['type' => 'yn', 'default' => 'y'],
                'use_combobox' => ['type' => 'yn', 'default' => 'y'],
                'show_support_messages' => ['type' => 'yn', 'default' => 'y'],
                'show_delete_on_forms' => ['type' => 'yn', 'default' => 'n'],
                'maintenance_mode' => ['type' => 'yn', 'default' => 'n'],
                'maintenance_mode_exclude_ips' => ['type' => 'string', 'default' => '', 'rules' => ['string', $this->ipListRule()]],
                'admin_dashlets_left' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'max:255'], 'strip' => true],
                'admin_dashlets_right' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'max:255'], 'strip' => true],
                'reseller_dashlets_left' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'max:255'], 'strip' => true],
                'reseller_dashlets_right' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'max:255'], 'strip' => true],
                'client_dashlets_left' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'max:255'], 'strip' => true],
                'client_dashlets_right' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'max:255'], 'strip' => true],
                'customer_no_template' => ['type' => 'string', 'default' => '', 'rules' => ['string', 'regex:/^[a-zA-Z0-9\-\_\[\]]{0,50}$/']],
                'customer_no_start' => ['type' => 'integer', 'default' => '', 'rules' => ['integer']],
                'customer_no_counter' => ['type' => 'integer', 'default' => '', 'rules' => ['integer']],
                'session_timeout' => ['type' => 'integer', 'default' => '', 'rules' => ['integer']],
                'session_allow_endless' => ['type' => 'yn', 'default' => 'n'],
                'min_password_length' => ['type' => 'integer', 'default' => '5', 'rules' => ['integer']],
                'min_password_strength' => ['type' => 'string', 'default' => '', 'rules' => ['string', Rule::in(['', '1', '2', '3', '4', '5'])]],
            ],
        ];
    }

    /**
     * Exposed field definitions of one section.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(string $section): array
    {
        return $this->fieldMap()[$section] ?? [];
    }

    public function isSection(string $section): bool
    {
        return in_array($section, self::SECTIONS, true);
    }

    /**
     * Laravel validation rules for one section's PUT body. All fields are
     * optional (absent key = leave unchanged, per the contract).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rulesFor(string $section, string $prefix = ''): array
    {
        $rules = [];

        foreach ($this->fields($section) as $key => $def) {
            $fieldRules = array_merge(['sometimes'], $def['rules'] ?? []);

            if (($def['type'] ?? '') === 'yn') {
                $fieldRules = ['sometimes', 'string', Rule::in(['y', 'n'])];
            }

            $rules[$prefix.$key] = $fieldRules;

            if (isset($def['item_rules'])) {
                $rules[$prefix.$key.'.*'] = $def['item_rules'];
            }
        }

        return $rules;
    }

    /**
     * Legacy SAVE filters, applied before validation: trim every scalar
     * (get_ini_string trims on write anyway) and STRIPTAGS + STRIPNL for the
     * marked text fields.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeInput(string $section, array $input): array
    {
        $fields = $this->fields($section);

        foreach ($input as $key => $value) {
            if (! isset($fields[$key]) || ! is_string($value)) {
                continue;
            }

            if ($fields[$key]['strip'] ?? false) {
                $value = strip_tags($value);
                $value = str_replace(["\r", "\n"], '', $value);
            }

            $input[$key] = trim($value);
        }

        return $input;
    }

    /**
     * GET /system/config — id + the five section objects.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $row = $this->singleton();
        $config = $this->parseBlob((string) $row->config);

        $out = ['id' => (int) $row->getKey()];

        foreach (self::SECTIONS as $section) {
            $out[$section] = $this->presentSection($section, $config[$section] ?? []);
        }

        return $out;
    }

    /**
     * GET /system/config/{section} — one section object, absent keys filled
     * with the legacy tform defaults.
     *
     * @return array<string, mixed>
     */
    public function getSection(string $section): array
    {
        $config = $this->parseBlob((string) $this->singleton()->config);

        return $this->presentSection($section, $config[$section] ?? []);
    }

    /**
     * PUT /system/config[/{section}] — read-merge-write of the whole blob
     * inside a transaction with the singleton row locked (lost-update
     * guard). Exactly one sys_datalog 'u' entry is produced via
     * SysIni::save(); all unsubmitted keys and sections are preserved.
     *
     * @param  array<string, array<string, mixed>>  $sectionChanges  section => validated exposed keys
     */
    public function updateSections(array $sectionChanges): void
    {
        DB::transaction(function () use ($sectionChanges): void {
            $row = SysIni::query()->lockForUpdate()->find(1);

            if ($row === null) {
                throw new HttpException(500, 'The sys_ini configuration singleton (sysini_id = 1) is missing; the ISPConfig installation is incomplete.');
            }

            $config = $this->parseBlob((string) $row->config);

            foreach ($sectionChanges as $section => $changes) {
                $values = [];

                foreach ($changes as $key => $value) {
                    $values[$key] = $this->toBlobValue($section, $key, $value);
                }

                $config = IniConfig::mergeSection($config, $section, $values);
            }

            $row->config = $this->buildBlob($config);
            $row->save();
        });
    }

    /**
     * The singleton row (GET paths; 500 when the installer-seeded row is
     * absent — legacy assumes it always exists).
     */
    protected function singleton(): SysIni
    {
        $row = SysIni::query()->find(1);

        if ($row === null) {
            throw new HttpException(500, 'The sys_ini configuration singleton (sysini_id = 1) is missing; the ISPConfig installation is incomplete.');
        }

        return $row;
    }

    /**
     * Cast a section's raw blob values into the contract's JSON types,
     * filling absent keys with the legacy defaults.
     *
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    protected function presentSection(string $section, array $values): array
    {
        $out = [];

        foreach ($this->fields($section) as $key => $def) {
            $raw = array_key_exists($key, $values) ? $values[$key] : $def['default'];

            $out[$key] = match ($def['type']) {
                'integer' => (int) $raw,
                'csv_array' => $raw === '' ? [] : array_values(array_filter(explode(',', $raw), fn ($v) => $v !== '')),
                default => (string) $raw,
            };
        }

        return $out;
    }

    /**
     * Serialize a validated JSON value back into its blob string form
     * (integers as plain strings, arrays comma-joined, y/n verbatim —
     * spec FR-020).
     */
    protected function toBlobValue(string $section, string $key, mixed $value): string
    {
        $type = $this->fields($section)[$key]['type'] ?? 'string';

        return match ($type) {
            'integer' => (string) (int) $value,
            'csv_array' => implode(',', (array) $value),
            default => trim((string) $value),
        };
    }

    /**
     * Legacy ini_parser::parse_ini_string (canonical implementation in
     * App\Support\IniConfig::parse()), reading the blob through
     * stripslashes() first exactly like getconf::get_global_config.
     *
     * @return array<string, array<string, string>>
     */
    public function parseBlob(string $blob): array
    {
        return IniConfig::parse(stripslashes($blob));
    }

    /**
     * Legacy ini_parser::get_ini_string (canonical implementation in
     * App\Support\IniConfig::serialize()) — `[section]` header, `key=value`
     * per key, one trailing blank line per section.
     *
     * @param  array<string, array<string, string>>  $config
     */
    public function buildBlob(array $config): string
    {
        return IniConfig::serialize($config);
    }

    /**
     * Legacy validate_database::valid_ip_list / ISIP-with-separator rule:
     * a comma-separated list of valid IPs, empty allowed.
     */
    protected function ipListRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            foreach (explode(',', $value) as $ip) {
                if (filter_var(trim($ip), FILTER_VALIDATE_IP) === false) {
                    $fail("The :attribute must be a comma-separated list of valid IP addresses ('".trim($ip)."' is not).");

                    return;
                }
            }
        };
    }
}
